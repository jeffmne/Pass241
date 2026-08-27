<?php

namespace App\Livewire\Dashboard\Events;

use App\Actions\Invitations\CancelInvitation;
use App\Actions\Invitations\CreateInvitations;
use App\Enums\AuditAction;
use App\Enums\InvitationStatus;
use App\Enums\PermissionName;
use App\Jobs\SendInvitationEmail;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockLedger;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use RuntimeException;

/**
 * Invitations d'un evenement (section 25).
 *
 * L'organisateur invite en collant une liste de noms, pas en remplissant un
 * formulaire par personne : une delegation, une classe, une liste de
 * partenaires arrivent toujours sous forme de liste.
 *
 * CE QUE L'ECRAN DIT SANS QU'ON LE DEMANDE : chaque invitation retient une
 * place. C'est la seule chose qu'un organisateur doit comprendre avant
 * d'inviter cinquante personnes sur un evenement qui en contient deux cents.
 *
 * Aucun filtrage par organisation dans les requetes : il est applique par la
 * portee globale du trait BelongsToOrganization, et la liaison de route sur
 * l'evenement a deja verifie l'appartenance (section 9).
 */
#[Layout('components.layouts.app')]
class Invitations extends Component
{
    public Event $event;

    public bool $showForm = false;

    public ?int $ticketTypeId = null;

    /** Une invitation par ligne : « Nom », « Nom, email » ou « Nom <email> ». */
    public string $guests = '';

    public string $expiresAt = '';

    /**
     * Envoyer le lien aux invites qui ont laisse une adresse.
     *
     * Coche par defaut : une adresse collee dans la liste n'a qu'une raison
     * d'etre. La case reste decochable pour l'organisateur qui prepare ses
     * invitations a l'avance et veut choisir son moment.
     */
    public bool $notifyByEmail = true;

    /** Lien affiche apres creation, a copier et transmettre. */
    public ?array $lastCreated = null;

    public function mount(Event $event): void
    {
        abort_unless(auth()->user()->can(PermissionName::InvitationsManage->value), 403);

        $this->event = $event;
        $this->expiresAt = $this->defaultExpiry()->format('Y-m-d\TH:i');
    }

    /**
     * Echeance par defaut : le debut de l'evenement.
     *
     * Une invitation qui expirerait apres le debut ne rendrait sa place a
     * personne — plus personne n'achete a ce moment-la. La proposer ainsi evite
     * a l'organisateur d'y penser.
     */
    private function defaultExpiry(): Carbon
    {
        $tz = config('app.display_timezone');

        return ($this->event->starts_at ?? now()->addWeek())->timezone($tz);
    }

    public function create(CreateInvitations $invitations): void
    {
        $this->authorizeManage();

        $data = $this->validate([
            'ticketTypeId' => ['required', 'integer'],
            'guests' => ['required', 'string', 'max:8000'],
            'expiresAt' => ['required', 'date'],
        ], attributes: [
            'ticketTypeId' => 'type de billet',
            'guests' => 'liste des invites',
            'expiresAt' => 'date limite',
        ]);

        // Le type doit appartenir a CET evenement : un identifiant venu du
        // navigateur ne doit pas puiser dans le stock d'un autre (regle 80.4).
        $ticketType = TicketType::query()
            ->where('event_id', $this->event->getKey())
            ->whereKey($data['ticketTypeId'])
            ->firstOrFail();

        $parsed = $this->parseGuests($data['guests']);

        if ($parsed === []) {
            $this->addError('guests', 'Indiquez au moins un nom.');

            return;
        }

        $expiresAt = Carbon::parse($data['expiresAt'], config('app.display_timezone'))->utc();

        if ($expiresAt->isPast()) {
            $this->addError('expiresAt', 'La date limite est deja passee.');

            return;
        }

        try {
            $created = $invitations->handle(
                event: $this->event,
                ticketType: $ticketType,
                guests: $parsed,
                expiresAt: $expiresAt,
                actor: auth()->user(),
                sendEmails: $this->notifyByEmail,
            );
        } catch (RuntimeException $e) {
            // Stock insuffisant, lot trop gros : le message vient de l'action
            // et se lit tel quel.
            $this->addError('guests', $e->getMessage());

            return;
        }

        $this->lastCreated = $created
            ->map(fn (Invitation $invitation) => [
                'name' => $invitation->guest_name,
                'url' => $invitation->publicUrl(),
                // Ceux-la n'ont rien a transmettre a la main : le dire evite
                // qu'ils recoivent le lien deux fois.
                'emailed' => $this->notifyByEmail && $invitation->guest_email !== null,
            ])
            ->all();

        $this->reset(['guests', 'showForm']);
        $this->resetErrorBag();

        $emailed = collect($this->lastCreated)->where('emailed', true)->count();

        $this->dispatch('notify', message: sprintf(
            '%d invitation%s creee%s.%s',
            $created->count(),
            $created->count() > 1 ? 's' : '',
            $created->count() > 1 ? 's' : '',
            $emailed > 0 ? sprintf(' %d lien%s envoye%s par email.', $emailed, $emailed > 1 ? 's' : '', $emailed > 1 ? 's' : '') : '',
        ));
    }

    /**
     * Envoie — ou renvoie — le lien par email.
     *
     * Le renvoi est la reponse a la phrase que tout organisateur entend :
     * « je n'ai rien recu ». Il ne cree rien, ne change pas la place retenue,
     * et se contente de refaire partir le meme lien.
     */
    public function sendEmail(int $id): void
    {
        $this->authorizeManage();

        $invitation = $this->find($id);

        if (! $invitation->guest_email) {
            $this->dispatch('notify', message: 'Cet invite n\'a pas d\'adresse email.');

            return;
        }

        if (! $invitation->isOpen()) {
            $this->dispatch('notify', message: 'Cette invitation n\'est plus ouverte.');

            return;
        }

        /*
         * Garde-fou contre le clic repete. L'organisateur qui ne voit rien
         * arriver reclique ; sans cela, l'invite recoit cinq fois le meme
         * message et l'adresse de la plateforme finit en indesirable.
         */
        if ($invitation->sent_at !== null && $invitation->sent_at->gt(now()->subMinute())) {
            $this->dispatch('notify', message: 'Lien deja envoye il y a moins d\'une minute.');

            return;
        }

        SendInvitationEmail::dispatch($invitation->getKey());

        $this->dispatch('notify', message: 'Lien envoye a '.$invitation->guest_email.'.');
    }

    /**
     * Marque une invitation comme transmise a la main.
     *
     * Le canal reel de l'organisateur reste le message — tous les invites n'ont
     * pas d'adresse email, et ici beaucoup n'en donneront jamais. La plateforme
     * ne pretend pas avoir envoye quoi que ce soit : elle enregistre que
     * l'organisateur dit l'avoir fait. Le journal distingue les deux par son
     * champ `channel`, et c'est la seule facon de savoir, plus tard, si un
     * invite qui affirme n'avoir rien recu peut etre cru.
     */
    public function markSent(int $id, AuditLogger $audit): void
    {
        $this->authorizeManage();

        $invitation = $this->find($id);

        if ($invitation->status !== InvitationStatus::Created) {
            return;
        }

        $invitation->forceFill([
            'status' => InvitationStatus::Sent->value,
            'sent_at' => now(),
        ])->save();

        $audit->log(
            AuditAction::InvitationSent,
            $invitation,
            ['guest' => $invitation->guest_name, 'channel' => 'manuel'],
            organizationId: $invitation->organization_id,
        );
    }

    public function cancel(int $id, CancelInvitation $invitations): void
    {
        $this->authorizeManage();

        try {
            $invitations->cancel($this->find($id), auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('notify', message: $e->getMessage());

            return;
        }

        $this->dispatch('notify', message: 'Invitation annulee, la place est rendue.');
    }

    public function dismissCreated(): void
    {
        $this->lastCreated = null;
    }

    private function find(int $id): Invitation
    {
        return Invitation::query()
            ->where('event_id', $this->event->getKey())
            ->whereKey($id)
            ->firstOrFail();
    }

    private function authorizeManage(): void
    {
        abort_unless(
            auth()->user()->can(PermissionName::InvitationsManage->value),
            403,
            'Vous n\'avez pas le droit de gerer les invitations.'
        );
    }

    /**
     * Lit la liste collee par l'organisateur.
     *
     * Trois formes acceptees, parce que ce sont les trois qu'on rencontre en
     * copiant depuis un carnet d'adresses ou un message : « Nom »,
     * « Nom, adresse » et « Nom <adresse> ». Les lignes vides sont ignorees
     * plutot que refusees — une liste collee en contient toujours.
     *
     * @return list<array{name: string, email: string|null}>
     */
    private function parseGuests(string $raw): array
    {
        $guests = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $email = null;

            if (preg_match('/^(.*?)[<,;]\s*([^>\s,;]+@[^>\s,;]+)\s*>?$/', $line, $matches)) {
                $line = trim($matches[1]);
                $email = filter_var($matches[2], FILTER_VALIDATE_EMAIL) ?: null;
            }

            $name = trim($line, " \t,;<>");

            if ($name === '') {
                continue;
            }

            $guests[] = ['name' => $name, 'email' => $email];
        }

        return $guests;
    }

    public function render()
    {
        $invitations = Invitation::query()
            ->where('event_id', $this->event->getKey())
            ->with(['order.items', 'ticket'])
            ->orderByDesc('id')
            ->get();

        $ticketTypes = TicketType::query()
            ->where('event_id', $this->event->getKey())
            ->orderBy('position')->orderBy('id')
            ->get();

        $stock = app(StockLedger::class);

        return view('livewire.dashboard.events.invitations', [
            'invitations' => $invitations,
            'ticketTypes' => $ticketTypes,
            // Places encore disponibles par type : c'est le chiffre qui dit a
            // l'organisateur combien de personnes il peut encore inviter.
            'available' => $ticketTypes->mapWithKeys(
                fn (TicketType $type) => [$type->getKey() => $stock->available($type)]
            ),
            'counts' => [
                'held' => $invitations->filter(fn (Invitation $i) => $i->status->holdsSeat())->count(),
                'accepted' => $invitations->where('status', InvitationStatus::Accepted)->count(),
                'open' => $invitations->filter(fn (Invitation $i) => $i->isOpen())->count(),
            ],
        ]);
    }
}
