<?php

namespace App\Livewire\Dashboard\Events;

use App\Enums\AuditAction;
use App\Enums\PermissionName;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Gestion des types de billets d'un evenement (section 17).
 *
 * Aucun filtrage par organisation n'apparait dans les requetes : il est
 * applique par la portee globale du trait BelongsToOrganization, et la liaison
 * de route sur l'evenement a deja verifie l'appartenance (section 9).
 */
#[Layout('components.layouts.app')]
class TicketTypes extends Component
{
    public Event $event;

    public ?int $editingId = null;

    public bool $showForm = false;

    public string $name = '';

    public string $description = '';

    public int $price = 0;

    public int $quantity = 100;

    public int $maxPerOrder = 5;

    public string $salesStartAt = '';

    public string $salesEndAt = '';

    public bool $isVisible = true;

    public bool $isActive = true;

    public function mount(Event $event): void
    {
        abort_unless(auth()->user()->can(PermissionName::TicketingManage->value), 403);

        $this->event = $event;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            // Entier de francs CFA : ni decimales, ni valeur negative
            // (decision D-003). 0 vaut billet gratuit (section 17).
            'price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'maxPerOrder' => ['required', 'integer', 'min:1', 'max:100'],
            'salesStartAt' => ['nullable', 'date'],
            'salesEndAt' => ['nullable', 'date', 'after_or_equal:salesStartAt'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => 'nom du billet',
            'price' => 'prix',
            'quantity' => 'quantite',
            'maxPerOrder' => 'limite par commande',
            'salesStartAt' => 'debut de vente',
            'salesEndAt' => 'fin de vente',
        ];
    }

    protected function messages(): array
    {
        return [
            'salesEndAt.after_or_equal' => 'La fin de vente ne peut pas preceder son debut.',
            'price.min' => 'Un prix ne peut pas etre negatif. Laissez 0 pour un billet gratuit.',
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $ticketTypeId): void
    {
        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        $this->editingId = $ticketType->getKey();
        $this->name = $ticketType->name;
        $this->description = (string) $ticketType->description;
        $this->price = $ticketType->price;
        $this->quantity = $ticketType->quantity;
        $this->maxPerOrder = $ticketType->max_per_order;
        $this->salesStartAt = $this->toLocalInput($ticketType->sales_start_at);
        $this->salesEndAt = $this->toLocalInput($ticketType->sales_end_at);
        $this->isVisible = $ticketType->is_visible;
        $this->isActive = $ticketType->is_active;

        $this->showForm = true;
    }

    public function save(AuditLogger $audit): void
    {
        $this->validate();

        $attributes = [
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'max_per_order' => $this->maxPerOrder,
            'sales_start_at' => $this->salesStartAt !== '' ? $this->fromLocalInput($this->salesStartAt) : null,
            'sales_end_at' => $this->salesEndAt !== '' ? $this->fromLocalInput($this->salesEndAt) : null,
            'is_visible' => $this->isVisible,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            $ticketType = $this->event->ticketTypes()->findOrFail($this->editingId);

            // Section 17 : ne jamais depasser la quantite disponible. Reduire
            // le stock sous le nombre deja vendu creerait des billets sans
            // place, decouverts a l'entree.
            if ($attributes['quantity'] < $ticketType->soldCount()) {
                $this->addError('quantity', sprintf(
                    '%d billets sont deja vendus : la quantite ne peut pas descendre en dessous.',
                    $ticketType->soldCount()
                ));

                return;
            }

            $ticketType->update($attributes);
        } else {
            $attributes['position'] = (int) $this->event->ticketTypes()->max('position') + 1;
            $ticketType = $this->event->ticketTypes()->create($attributes);
        }

        $audit->log(AuditAction::TicketCreated, $ticketType, [
            'event_id' => $this->event->getKey(),
            'name' => $ticketType->name,
            'price' => $ticketType->price,
        ]);

        $this->resetForm();
        $this->showForm = false;
        $this->dispatch('notify', message: 'Billet enregistre.');
    }

    public function delete(int $ticketTypeId): void
    {
        $ticketType = $this->event->ticketTypes()->findOrFail($ticketTypeId);

        // Un type de billet deja vendu n'est pas supprimable : effacer la ligne
        // d'origine d'une commande payee rendrait la comptabilite
        // inexploitable. Le desactiver retire la vente sans perdre l'historique.
        if ($ticketType->soldCount() > 0) {
            $this->dispatch(
                'notify',
                message: 'Ce billet a deja ete vendu : desactivez-le plutot que de le supprimer.',
            );

            return;
        }

        $ticketType->delete();

        $this->dispatch('notify', message: 'Billet supprime.');
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'description', 'price', 'quantity',
            'maxPerOrder', 'salesStartAt', 'salesEndAt', 'isVisible', 'isActive',
        ]);

        $this->resetErrorBag();
    }

    private function fromLocalInput(string $value): Carbon
    {
        return Carbon::parse($value, config('app.display_timezone'))->utc();
    }

    private function toLocalInput(?Carbon $date): string
    {
        return $date?->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i') ?? '';
    }

    public function render()
    {
        $ticketTypes = $this->event->ticketTypes()->get();

        return view('livewire.dashboard.events.ticket-types', [
            'ticketTypes' => $ticketTypes,
            'totalOnSale' => $ticketTypes->sum('quantity'),
            'totalSold' => $ticketTypes->sum(fn (TicketType $type) => $type->soldCount()),
        ]);
    }
}
