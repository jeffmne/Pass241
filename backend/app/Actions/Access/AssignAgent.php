<?php

namespace App\Actions\Access;

use App\Enums\AuditAction;
use App\Enums\RoleName;
use App\Models\Event;
use App\Models\EventAgent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cree ou rattache un agent de controle (sections 27.2, 30).
 *
 * Un agent est un utilisateur portant le role ACCESS_AGENT, pas une entite
 * separee : il se connecte par Sanctum comme tout le monde, et le systeme de
 * roles existant lui interdit deja l'administration. Recreer un modele
 * d'authentification a cote serait dupliquer ce qui fonctionne, avec deux fois
 * plus de surface a securiser (regle 81).
 *
 * TROIS SITUATIONS, une seule action :
 *
 * 1. Adresse inconnue : un compte est cree, avec un mot de passe genere que
 *    l'organisateur transmet a son agent. C'est le cas courant sur ce marche —
 *    un agent recrute pour une soiree n'a souvent pas d'adresse qu'il consulte,
 *    et attendre qu'il clique sur un lien d'invitation le laisserait dehors le
 *    soir venu.
 *
 * 2. Adresse deja connue : le compte est REUTILISE. Un agent qui travaille
 *    pour deux organisateurs n'a pas deux comptes, et un organisateur qui tient
 *    lui-meme la porte s'affecte avec son propre compte.
 *
 * 3. Agent deja affecte a cet evenement : l'affectation est mise a jour — on
 *    ne recree jamais une ligne, c'est elle qui explique pourquoi tel agent a
 *    scanne tel billet il y a six mois (section 67).
 */
class AssignAgent
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @return array{agent: EventAgent, password: string|null}
     *                                                         Le mot de passe n'est rendu QUE lorsqu'un compte vient d'etre
     *                                                         cree, et n'est jamais stocke en clair : il est affiche une fois a
     *                                                         l'organisateur, qui le transmet.
     */
    public function handle(
        Event $event,
        string $email,
        string $name,
        ?int $entryPointId,
        User $actor,
    ): array {
        $email = Str::lower(trim($email));

        return DB::transaction(function () use ($event, $email, $name, $entryPointId, $actor) {
            $existing = User::query()->where('email', $email)->first();
            $password = null;

            if ($existing === null) {
                $password = Str::password(10, symbols: false);

                $existing = User::create([
                    'name' => trim($name),
                    'email' => $email,
                    'password' => Hash::make($password),
                ]);
            }

            $this->attachToOrganization($existing, $event);

            $assignment = EventAgent::query()
                ->where('event_id', $event->getKey())
                ->where('user_id', $existing->getKey())
                ->first();

            if ($assignment) {
                // Reactivation d'une affectation revoquee, ou changement de
                // porte : la ligne existante est reprise, jamais remplacee.
                $assignment->forceFill([
                    'entry_point_id' => $entryPointId,
                    'is_active' => true,
                    'revoked_at' => null,
                ])->save();
            } else {
                $assignment = EventAgent::create([
                    'event_id' => $event->getKey(),
                    'user_id' => $existing->getKey(),
                    'entry_point_id' => $entryPointId,
                ]);
            }

            $this->audit->log(
                AuditAction::AgentCreated,
                $assignment,
                [
                    'agent' => $existing->email,
                    'event' => $event->slug,
                    'entry_point_id' => $entryPointId,
                    'account_created' => $password !== null,
                ],
                actor: $actor,
                organizationId: $event->organization_id,
            );

            return ['agent' => $assignment->load(['agent', 'entryPoint']), 'password' => $password];
        });
    }

    /** Retire un agent d'un evenement, sans effacer son passage (section 67). */
    public function revoke(EventAgent $assignment, User $actor): void
    {
        if (! $assignment->is_active) {
            return;
        }

        $assignment->revoke();

        $this->audit->log(
            AuditAction::AgentDisabled,
            $assignment,
            ['agent' => $assignment->agent?->email],
            actor: $actor,
            organizationId: $assignment->organization_id,
        );
    }

    /**
     * Rattache l'agent a l'organisation et lui donne son role.
     *
     * Les roles Spatie sont scopes par organisation : le meme utilisateur peut
     * etre agent ici et organisateur ailleurs sans qu'aucun droit ne fuite
     * d'une organisation a l'autre.
     */
    private function attachToOrganization(User $user, Event $event): void
    {
        $organization = $event->organization
            ?? throw new RuntimeException('L\'evenement n\'a pas d\'organisation.');

        $this->context->runFor($organization, function () use ($user, $organization) {
            $member = $organization->members()
                ->where('user_id', $user->getKey())
                ->first();

            if ($member === null) {
                $organization->members()->create([
                    'user_id' => $user->getKey(),
                    'joined_at' => now(),
                ]);
            } elseif ($member->revoked_at !== null) {
                $member->forceFill(['revoked_at' => null])->save();
            }

            setPermissionsTeamId($organization->getKey());

            if (! $user->hasRole(RoleName::AccessAgent->value)) {
                $user->assignRole(RoleName::AccessAgent->value);
            }
        });
    }
}
