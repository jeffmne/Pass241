<?php

namespace App\Http\Controllers\Api\Agent;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Access\AgentAccess;
use App\Services\Audit\AuditLogger;
use App\Support\AgentDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connexion de l'application agent (section 27.3).
 *
 * Jeton Sanctum plutot que session : l'application est native, elle n'a pas de
 * cookie et doit conserver son acces entre deux ouvertures (SecureStore).
 *
 * Le jeton porte l'ABILITE `agent` et elle seule. Un telephone d'agent se perd
 * a une entree de concert : meme vole, son jeton ne donne acces a rien
 * d'autre. C'est la difference entre un incident et une compromission.
 *
 * Le jeton est nomme d'apres l'appareil, ce qui rend la revocation a distance
 * possible sans deconnecter les autres agents.
 */
class AuthController extends Controller
{
    public function login(Request $request, AgentAccess $access, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:120', 'regex:/\A[a-zA-Z0-9._:-]+\z/'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        /*
         * Limitation par couple email + appareil.
         *
         * Par email seul, quelqu'un pourrait bloquer un agent legitime en
         * echouant volontairement depuis un autre appareil — a l'entree d'un
         * evenement, cela revient a fermer la porte.
         */
        $key = 'agent-login:'.Str::lower($data['email']).'|'.$data['device_id'];

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Trop de tentatives. Reessayez dans une minute.',
            ])->status(429);
        }

        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            $audit->log(AuditAction::LoginFailed, null, [
                'email' => $data['email'],
                'channel' => 'agent',
                'device' => $data['device_id'],
            ]);

            // Message unique : distinguer « mot de passe faux » de « compte
            // inconnu » revele quelles adresses existent.
            throw ValidationException::withMessages([
                'email' => 'Identifiants incorrects.',
            ]);
        }

        /*
         * Le compte doit etre affecte a au moins un evenement.
         *
         * C'est ce qui separe l'application agent du reste : un compte
         * d'organisateur sans affectation ne s'y connecte pas. Un organisateur
         * qui tient lui-meme la porte s'affecte, et cela fonctionne — c'est le
         * cas courant d'un petit evenement.
         */
        if (! $access->isAgent($user)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Ce compte n\'est affecte a aucun evenement.',
            ])->status(403);
        }

        RateLimiter::clear($key);

        // Un seul jeton par appareil : une reconnexion remplace le precedent
        // plutot que d'empiler des acces oublies.
        $user->tokens()->where('name', AgentDevice::tokenName($data['device_id']))->delete();

        $expiration = (int) config('sanctum.expiration', 1440);

        $token = $user->createToken(
            AgentDevice::tokenName($data['device_id']),
            ['agent'],
            expiresAt: $expiration > 0 ? now()->addMinutes($expiration) : null,
        );

        $user->forceFill(['last_login_at' => now()])->save();

        $audit->log(AuditAction::Login, $user, [
            'channel' => 'agent',
            'device' => $data['device_id'],
            'device_name' => $data['device_name'] ?? null,
        ], actor: $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'agent' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            // L'application compare cette heure a son horloge locale et
            // avertit si l'ecart est anormal : la regle du double scan repose
            // sur l'horodatage de l'appareil (section 31.1).
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function logout(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();

        $request->user()->currentAccessToken()->delete();

        $audit->log(AuditAction::Logout, $user, ['channel' => 'agent'], actor: $user);

        return response()->json(status: 204);
    }
}
