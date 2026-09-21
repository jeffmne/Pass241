<?php

namespace App\Actions\Invitations;

use App\Enums\AuditAction;
use App\Enums\OrderOrigin;
use App\Enums\OrderStatus;
use App\Jobs\SendInvitationEmail;
use App\Models\Event;
use App\Models\Invitation;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Ticketing\StockLedger;
use App\Support\Tenancy\OrganizationContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creation d'invitations (section 25).
 *
 * Une invitation MET UNE PLACE DE COTE. C'est le point qui compte : sans cela,
 * un organisateur qui invite cinquante personnes verrait son evenement se
 * vendre entierement pendant que ses invites reflechissent, et cinquante
 * personnes se presenteraient a une porte qui n'a plus de place.
 *
 * La place est retenue par une commande a zero franc en attente (decision
 * D-039). Le stock la compte comme reservee tant que son echeance n'est pas
 * passee, exactement comme un acheteur en train de payer (decision D-017) — et
 * la rend au public d'elle-meme le moment venu, sans qu'aucun traitement n'ait
 * a s'executer.
 *
 * TOUT OU RIEN sur le lot. Inviter dix personnes et n'en reserver que sept
 * parce que le stock s'est epuise en cours de route laisserait l'organisateur
 * sans moyen de savoir lesquelles. La transaction couvre le lot entier.
 */
class CreateInvitations
{
    /**
     * Plafond par lot.
     *
     * Cent invitations d'un coup couvrent tous les usages reels — une liste de
     * partenaires, une classe, une delegation. Au-dela, c'est une liste
     * importee, et l'import n'est pas au perimetre.
     */
    public const MAX_PER_BATCH = 100;

    public function __construct(
        private readonly StockLedger $stock,
        private readonly AuditLogger $audit,
        private readonly OrganizationContext $context,
    ) {}

    /**
     * @param  list<array{name: string, email?: string|null}>  $guests
     * @param  bool  $sendEmails  envoyer le lien aux invites qui ont une adresse
     * @return Collection<int, Invitation>
     *
     * @throws RuntimeException si le stock ne suffit pas
     */
    public function handle(
        Event $event,
        TicketType $ticketType,
        array $guests,
        \DateTimeInterface $expiresAt,
        ?User $actor = null,
        bool $sendEmails = true,
    ): Collection {
        if ($guests === []) {
            throw new RuntimeException('Aucun invite a inviter.');
        }

        if (count($guests) > self::MAX_PER_BATCH) {
            throw new RuntimeException(sprintf(
                'Pas plus de %d invitations a la fois.',
                self::MAX_PER_BATCH
            ));
        }

        // Le type de billet doit appartenir a CET evenement : un identifiant
        // venu du navigateur ne doit pas pouvoir puiser dans le stock d'un
        // autre evenement (regle 80.4).
        if ($ticketType->event_id !== $event->getKey()) {
            throw new RuntimeException('Ce type de billet n\'appartient pas a cet evenement.');
        }

        $invitations = $this->context->runFor($event->organization, function () use (
            $event, $ticketType, $guests, $expiresAt, $actor
        ) {
            return DB::transaction(function () use ($event, $ticketType, $guests, $expiresAt, $actor) {
                // Les invitations et les achats partagent le meme verrou :
                // aucun des deux chemins ne peut promettre la derniere place
                // pendant que l'autre est en train de la reserver.
                $ticketType = TicketType::query()->acrossOrganizations()
                    ->where('event_id', $event->getKey())
                    ->lockForUpdate()->findOrFail($ticketType->getKey());
                $available = $this->stock->available($ticketType);

                if ($available < count($guests)) {
                    throw new RuntimeException(sprintf(
                        'Il ne reste que %d place%s sur « %s ».',
                        $available,
                        $available > 1 ? 's' : '',
                        $ticketType->name
                    ));
                }

                $invitations = collect();

                foreach ($guests as $guest) {
                    $invitations->push(
                        $this->createOne($event, $ticketType, $guest, $expiresAt, $actor)
                    );
                }

                $this->audit->log(
                    AuditAction::InvitationCreated,
                    $event,
                    [
                        'ticket_type' => $ticketType->name,
                        'count' => $invitations->count(),
                        'expires_at' => $expiresAt->format(DATE_ATOM),
                    ],
                    actor: $actor,
                    organizationId: $event->organization_id,
                );

                return $invitations;
            });
        });

        if ($sendEmails) {
            $this->sendLinks($invitations);
        }

        return $invitations;
    }

    /**
     * Met en file l'envoi du lien, pour les invites qui ont une adresse.
     *
     * APRES la transaction, jamais dedans : un worker assez rapide pourrait
     * chercher une invitation que la transaction n'a pas encore validee, et ne
     * rien trouver. La mise en file elle-meme ne doit pas non plus faire
     * echouer la creation — les places sont retenues, ce qui est l'essentiel ;
     * un lien non parti se rattrape par le bouton de renvoi, ou par le
     * copier-coller.
     *
     * @param  Collection<int, Invitation>  $invitations
     */
    private function sendLinks(Collection $invitations): void
    {
        foreach ($invitations as $invitation) {
            if (! $invitation->guest_email) {
                continue;
            }

            try {
                SendInvitationEmail::dispatch($invitation->getKey());
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  array{name: string, email?: string|null}  $guest
     */
    private function createOne(
        Event $event,
        TicketType $ticketType,
        array $guest,
        \DateTimeInterface $expiresAt,
        ?User $actor,
    ): Invitation {
        $name = trim($guest['name']);
        [$firstName, $lastName] = $this->splitName($name);

        $order = new Order([
            'event_id' => $event->getKey(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            // L'adresse sert a envoyer le billet une fois l'invitation
            // acceptee, par le meme chemin qu'un achat (section 69).
            'email' => $guest['email'] ?? null,
            'subtotal' => 0,
            'psp_fee_amount' => 0,
            'amount' => 0,
            // C'est cette echeance qui retient la place, et qui la rend.
            'expires_at' => $expiresAt,
        ]);

        /*
         * `origin` hors de `$fillable`, volontairement : aucun formulaire ne
         * doit pouvoir transformer un achat en invitation, ce qui reviendrait a
         * offrir un billet payant.
         */
        $order->forceFill([
            'origin' => OrderOrigin::Invitation->value,
            'reference' => $this->uniqueOrderReference(),
            'status' => OrderStatus::Pending->value,
        ])->save();

        $order->items()->create([
            'ticket_type_id' => $ticketType->getKey(),
            // Nom du type fige a la creation, comme pour un achat : il peut
            // changer avant que l'invite n'accepte.
            'ticket_type_name' => $ticketType->name,
            'quantity' => 1,
            'unit_price' => 0,
            'subtotal' => 0,
        ]);

        return Invitation::create([
            'event_id' => $event->getKey(),
            'order_id' => $order->getKey(),
            /*
             * Le jeton EST l'acces : il doit etre impossible a deviner. Quarante
             * caracteres aleatoires, la ou une reference de billet en compte
             * huit — cette derniere n'est pas un secret, celui-ci l'est.
             */
            'token' => Str::random(40),
            'guest_name' => $name,
            'guest_email' => $guest['email'] ?? null,
            'expires_at' => $expiresAt,
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Coupe un nom en prenom et nom.
     *
     * La commande exige les deux champs. Un invite annonce le plus souvent
     * « Marie Nzeng » d'un bloc, et rien ne justifie de lui demander deux
     * cases : ce qui compte est le nom imprime sur le billet, reconstitue
     * ensuite par `buyerName()`.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? ''];
    }

    private function uniqueOrderReference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

        do {
            $block = fn (int $length) => collect(range(1, $length))
                ->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])
                ->implode('');

            $reference = 'PS241-'.$block(4).'-'.$block(3);
        } while (Order::query()->acrossOrganizations()->where('reference', $reference)->exists());

        return $reference;
    }
}
