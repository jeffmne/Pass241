<?php

namespace App\Livewire\Public;

use App\Actions\Orders\CreateOrder;
use App\Enums\PaymentProvider;
use App\Models\Event;
use App\Models\TicketType;
use App\Rules\MobileMoneyNumber;
use App\Services\Payments\PaymentManager;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * Parcours d'achat public (sections 15, 16, 16.1).
 *
 * « Mobile-first, rapide, simple, rassurant, sans inscription inutile. »
 * L'acheteur n'a pas de compte et n'en cree pas : nom, prenom, email
 * facultatif, operateur, numero, c'est tout.
 *
 * Le composant est ouvert au public. Il ne fait donc confiance a rien de ce
 * qu'il recoit : les quantites sont revalidees, et les types de billets sont
 * systematiquement rattaches a l'evenement de la page (section 80.4). Aucun
 * prix ne transite par le client — ils sont relus en base.
 */
class Checkout extends Component
{
    #[Locked]
    public Event $event;

    /** @var array<int, int> identifiant de type de billet => quantite */
    public array $quantities = [];

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $provider = '';

    public string $payerPhone = '';

    public function mount(Event $event): void
    {
        $this->event = $event;

        foreach ($event->publicTicketTypes() as $ticketType) {
            $this->quantities[$ticketType->getKey()] = 0;
        }
    }

    protected function rules(): array
    {
        return [
            'quantities' => ['array'],
            'quantities.*' => ['integer', 'min:0', 'max:100'],
            'firstName' => ['required', 'string', 'min:2', 'max:80'],
            'lastName' => ['required', 'string', 'min:2', 'max:80'],
            // Section 15.1 : l'email reste facultatif. Le billet est livre et
            // recuperable sans lui.
            'email' => ['nullable', 'email', 'max:255'],
            'provider' => ['required', 'in:'.implode(',', PaymentProvider::values())],
            'payerPhone' => ['required', 'string', 'max:20', new MobileMoneyNumber($this->selectedProvider())],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'firstName' => 'prenom',
            'lastName' => 'nom',
            'email' => 'adresse email',
            'provider' => 'moyen de paiement',
            'payerPhone' => 'numero de telephone',
        ];
    }

    protected function messages(): array
    {
        return [
            'provider.required' => 'Choisissez Airtel Money ou Moov Money.',
            'provider.in' => 'Choisissez Airtel Money ou Moov Money.',
        ];
    }

    private function selectedProvider(): ?PaymentProvider
    {
        return PaymentProvider::tryFrom($this->provider);
    }

    /*
     * ---------------------------------------------------------------------
     * Panier
     * ---------------------------------------------------------------------
     */

    public function increment(int $ticketTypeId): void
    {
        $ticketType = $this->ticketType($ticketTypeId);

        if (! $ticketType) {
            return;
        }

        $current = (int) ($this->quantities[$ticketTypeId] ?? 0);
        $max = $ticketType->maxPurchasableQuantity();

        // Bornage cote serveur : le composant est public, la limite ne peut pas
        // reposer sur l'attribut `max` d'un champ que n'importe qui peut
        // modifier dans son navigateur.
        $this->quantities[$ticketTypeId] = min($current + 1, $max);
    }

    public function decrement(int $ticketTypeId): void
    {
        $current = (int) ($this->quantities[$ticketTypeId] ?? 0);

        $this->quantities[$ticketTypeId] = max(0, $current - 1);
    }

    /** Montant total, toujours recalcule depuis les prix en base. */
    public function total(): int
    {
        $total = 0;

        foreach ($this->quantities as $ticketTypeId => $quantity) {
            if ($quantity < 1) {
                continue;
            }

            $ticketType = $this->ticketType((int) $ticketTypeId);

            if ($ticketType) {
                $total += $ticketType->price * (int) $quantity;
            }
        }

        return $total;
    }

    public function ticketCount(): int
    {
        return array_sum(array_map('intval', $this->quantities));
    }

    /*
     * ---------------------------------------------------------------------
     * Commande
     * ---------------------------------------------------------------------
     */

    public function placeOrder(CreateOrder $createOrder, PaymentManager $payments): void
    {
        if ($this->ticketCount() < 1) {
            $this->addError('quantities', 'Choisissez au moins un billet.');

            return;
        }

        $this->validate();

        try {
            $order = $createOrder->handle(
                event: $this->event,
                quantities: array_map('intval', $this->quantities),
                firstName: trim($this->firstName),
                lastName: trim($this->lastName),
                email: trim($this->email) ?: null,
                provider: PaymentProvider::from($this->provider),
                payerPhone: trim($this->payerPhone),
                ipAddress: request()->ip(),
            );
        } catch (RuntimeException $e) {
            // Le stock a pu changer pendant la saisie : le message dit
            // precisement quoi, pour que l'acheteur puisse corriger.
            $this->addError('quantities', $e->getMessage());

            return;
        }

        /*
         * Declenche le push USSD (section 21.1).
         *
         * Un echec ici ne remet pas la commande en cause : elle existe, sa
         * reservation court, et l'acheteur peut relancer depuis l'ecran de
         * suivi. La faire echouer maintenant lui ferait tout resaisir.
         */
        try {
            $initialization = $payments->driver()->initializePayment($order);

            if ($initialization->providerReference) {
                $order->forceFill(['provider_reference' => $initialization->providerReference])->save();
            }
        } catch (Throwable $e) {
            report($e);
        }

        // Redirection vers un lien signe : l'acheteur n'a pas de compte, ce
        // lien est ce qui le ramenera a ses billets (decision D-004).
        $this->redirect($order->statusUrl(), navigate: true);
    }

    private function ticketType(int $ticketTypeId): ?TicketType
    {
        // Toujours relu depuis l'evenement de la page : un identifiant venu
        // d'ailleurs ne peut pas se glisser dans le panier.
        return $this->event->publicTicketTypes()
            ->firstWhere('id', $ticketTypeId);
    }

    public function render()
    {
        return view('livewire.public.checkout', [
            'ticketTypes' => $this->event->publicTicketTypes(),
            'providers' => PaymentProvider::cases(),
            'total' => $this->total(),
            'ticketCount' => $this->ticketCount(),
        ]);
    }
}
