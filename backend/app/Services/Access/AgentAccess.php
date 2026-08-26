<?php

namespace App\Services\Access;

use App\Models\Event;
use App\Models\EventAgent;
use App\Models\User;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;

/**
 * Ce qu'un agent a le droit de controler (sections 27.2, 30).
 *
 * « Un agent ne voit que les evenements auxquels il est affecte. » Toutes les
 * routes de l'API agent passent par ici, et par ici seulement : une
 * verification dupliquee dans chaque controleur finirait par diverger, et
 * c'est le genre de divergence qui laisse un agent scanner l'evenement d'une
 * autre organisation.
 *
 * Les affectations sont lues HORS PORTEE D'ORGANISATION : un agent n'a pas de
 * contexte d'organisation actif — il en sert peut-etre plusieurs — et c'est
 * justement l'affectation qui definit son perimetre.
 */
class AgentAccess
{
    public function __construct(private readonly OrganizationContext $context) {}

    /**
     * Affectations actives d'un agent.
     *
     * @return Collection<int, EventAgent>
     */
    public function assignments(User $agent): Collection
    {
        /*
         * Toute la lecture se fait hors portee, d'un seul bloc.
         *
         * Lever la portee relation par relation ne fonctionne pas : une
         * fermeture de chargement imbrique recoit une Relation et non un
         * Builder, et le scope local ne s'y transmet pas. Le dire une fois ici
         * est plus sur, et plus honnete — un agent n'a PAS de contexte
         * d'organisation, il en sert peut-etre plusieurs.
         */
        return $this->context->withoutScope(fn () => EventAgent::query()
            ->active()
            ->where('user_id', $agent->getKey())
            ->with(['event.location', 'event.organization', 'entryPoint'])
            ->get()
            // Une affectation dont l'evenement a disparu n'est pas
            // exploitable : la laisser ferait planter l'application au
            // demarrage, au pire moment.
            ->filter(fn (EventAgent $assignment) => $assignment->event !== null)
            ->values());
    }

    /** L'agent peut-il controler cet evenement, maintenant ? */
    public function canControl(User $agent, Event $event): bool
    {
        return $this->assignmentFor($agent, $event) !== null;
    }

    /**
     * Resout un evenement DEPUIS le perimetre de l'agent.
     *
     * Resolution et autorisation dans le meme geste, volontairement. Le liage
     * de route ne convient pas ici : il resout par slug et applique la portee
     * d'organisation, qu'un agent n'a pas — l'evenement reviendrait
     * introuvable. Le faire a la main dans chaque controleur finirait par
     * diverger, et c'est le genre de divergence qui laisse un agent scanner
     * l'evenement d'une autre organisation.
     *
     * Repond 404 et non 403 sur un evenement non affecte : l'agent n'a pas a
     * apprendre quels evenements existent ailleurs sur la plateforme.
     */
    public function eventFor(User $agent, int|string $eventId): Event
    {
        $assignment = $this->context->withoutScope(fn () => EventAgent::query()
            ->active()
            ->where('user_id', $agent->getKey())
            ->where('event_id', $eventId)
            ->with(['event.location', 'event.organization'])
            ->first());

        abort_if($assignment?->event === null, 404);

        return $assignment->event;
    }

    /**
     * Affectation de l'agent sur cet evenement, ou null.
     *
     * Sert aussi a retrouver son point d'entree : un scan sans point d'entree
     * reste valide (le cas d'une porte unique), mais quand l'organisateur en a
     * affecte un, c'est celui-la qui est enregistre — pas celui que
     * l'application affirme.
     */
    public function assignmentFor(User $agent, Event $event): ?EventAgent
    {
        return $this->context->withoutScope(fn () => EventAgent::query()
            ->active()
            ->where('user_id', $agent->getKey())
            ->where('event_id', $event->getKey())
            ->first());
    }

    /** L'utilisateur peut-il ouvrir l'application agent ? */
    public function isAgent(User $user): bool
    {
        return $this->context->withoutScope(fn () => EventAgent::query()
            ->active()
            ->where('user_id', $user->getKey())
            ->exists());
    }
}
