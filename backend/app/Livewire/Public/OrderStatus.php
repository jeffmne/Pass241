<?php

namespace App\Livewire\Public;

use App\Actions\Orders\ConfirmPayment;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Services\Payments\PaymentManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;

/**
 * Suivi d'une commande apres le declenchement du paiement (section 21.1).
 *
 * L'acheteur voit ce qu'il doit faire — valider sur son telephone — et combien
 * de temps il lui reste. La page se rafraichit d'elle-meme tant que la
 * commande est en attente.
 *
 * Le rafraichissement fait aussi office de POLLING DE SECOURS : les webhooks
 * mobile money peuvent etre en retard ou manquants selon l'operateur
 * (section 21.1). Interroger le provider pendant que l'acheteur regarde son
 * ecran est le moment ou cela coute le moins et sert le plus.
 *
 * Le frontend ne decide jamais qu'un paiement est confirme (section 22) : cette
 * page lit l'etat, et c'est ConfirmPayment — cote serveur — qui tranche.
 */
#[Layout('components.layouts.public')]
class OrderStatus extends Component
{
    #[Locked]
    public Order $order;

    public function mount(string $reference): void
    {
        // La portee par organisation est levee : l'acheteur n'appartient a
        // aucune organisation. L'acces est protege par la signature de l'URL
        // (decision D-004), verifiee par le middleware de la route.
        $this->order = Order::query()
            ->acrossOrganizations()
            ->with(['items', 'event.location', 'tickets'])
            ->where('reference', $reference)
            ->first() ?? abort(404);
    }

    /**
     * Interroge l'operateur, si la commande est encore en attente.
     *
     * Appelee par le rafraichissement automatique de la page. Ne fait rien
     * lorsque la commande a deja un etat definitif : inutile de solliciter
     * l'operateur pour une transaction close.
     */
    public function refreshStatus(PaymentManager $payments, ConfirmPayment $confirmPayment): void
    {
        $this->order->refresh();

        if (! $this->order->isPending()) {
            return;
        }

        $result = $payments->driver()->checkPaymentStatus($this->order);

        if ($result->state === PaymentState::Succeeded) {
            try {
                $this->order = $confirmPayment->handle($this->order, $result->providerReference);
            } catch (RuntimeException $e) {
                // Le stock ne suffit plus : l'argent est encaisse, la commande
                // devra etre remboursee. On ne masque pas la situation.
                $this->addError('payment', $e->getMessage());
            }

            return;
        }

        if ($result->state === PaymentState::Failed) {
            $this->order = $confirmPayment->markFailed(
                $this->order,
                $result->failureReason ?? 'Paiement refuse par l\'operateur.'
            );
        }
    }

    public function render()
    {
        $this->order->loadMissing(['items', 'event.location', 'tickets']);

        return view('livewire.public.order-status', [
            'event' => $this->order->event,
            'secondsLeft' => $this->order->secondsBeforeExpiry(),
            // Ecran de simulation reserve au provider de developpement : il
            // n'existe pas des lors qu'un vrai operateur est branche.
            'canSimulate' => app(PaymentManager::class)->isFake() && app()->environment('local'),
        ])->layoutData([
            'title' => 'Commande '.$this->order->reference.' — Pass241',
            'indexable' => false,
        ]);
    }
}
