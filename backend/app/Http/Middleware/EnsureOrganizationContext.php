<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Etablit le contexte d'organisation de la requete (section 9, section 80.4).
 *
 * L'organisation est deduite de l'utilisateur authentifie, jamais de la
 * requete. Aucun parametre d'URL, champ de formulaire, en-tete ou corps JSON
 * n'est consulte ici — c'est precisement le point de la regle : ne jamais faire
 * confiance a un `organizationId` envoye par le frontend.
 *
 * L'appartenance est revalidee a chaque requete plutot que mise en cache dans
 * la session : une appartenance revoquee doit couper l'acces immediatement, et
 * non a la prochaine reconnexion.
 */
class EnsureOrganizationContext
{
    public function __construct(private readonly OrganizationContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->deny($request, 401, 'Authentification requise.');
        }

        $organization = $this->resolveOrganization($user);

        if (! $organization) {
            // L'utilisateur est authentifie mais n'appartient a aucune
            // organisation active : il doit en creer une avant d'aller plus loin.
            return $request->expectsJson()
                ? response()->json(['message' => 'Aucune organisation active.'], 403)
                : redirect()->route('organizations.create');
        }

        // Defense en profondeur : `current_organization_id` peut pointer vers une
        // organisation dont l'appartenance a ete revoquee entre-temps.
        if (! $user->belongsToOrganization($organization)) {
            $user->forceFill(['current_organization_id' => null])->save();
            $this->context->forget();

            return $this->deny($request, 403, 'Vous n\'appartenez plus a cette organisation.');
        }

        if ($organization->isSuspended() && ! $user->isSuperAdmin()) {
            return $this->deny($request, 403, 'Cette organisation est suspendue.');
        }

        $this->context->set($organization);

        // Portee des roles Spatie sur la meme organisation : un role accorde
        // dans l'organisation A ne doit rien autoriser dans l'organisation B.
        setPermissionsTeamId($organization->getKey());

        return $next($request);
    }

    /**
     * Organisation active de l'utilisateur, avec reprise automatique sur la
     * plus ancienne appartenance encore valide si aucune n'est selectionnee.
     */
    private function resolveOrganization(User $user): ?Organization
    {
        if ($user->current_organization_id !== null) {
            $organization = Organization::find($user->current_organization_id);

            if ($organization) {
                return $organization;
            }
        }

        $membership = $user->memberships()->active()->oldest('joined_at')->first();

        if (! $membership) {
            return null;
        }

        $user->forceFill(['current_organization_id' => $membership->organization_id])->save();

        return $membership->organization;
    }

    private function deny(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $status);
        }

        abort($status, $message);
    }
}
