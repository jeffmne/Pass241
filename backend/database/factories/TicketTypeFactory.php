<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketType>
 */
class TicketTypeFactory extends Factory
{
    protected $model = TicketType::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'name' => 'Standard',
            'description' => 'Acces general a l\'evenement.',
            // Entier XAF (decision D-003).
            'price' => 10_000,
            'quantity' => 100,
            'max_per_order' => 5,
            'is_visible' => true,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['price' => 0, 'name' => 'Invitation']);
    }

    public function vip(): static
    {
        return $this->state(fn () => ['name' => 'VIP', 'price' => 50_000, 'position' => 1]);
    }

    /** Un seul billet restant : le cas ou le hold de la section 17.1 agit. */
    public function lastOne(): static
    {
        return $this->state(fn () => ['quantity' => 1]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => ['is_visible' => false]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function notYetOnSale(): static
    {
        return $this->state(fn () => ['sales_start_at' => now()->addWeek()]);
    }

    public function salesEnded(): static
    {
        return $this->state(fn () => [
            'sales_start_at' => now()->subWeeks(2),
            'sales_end_at' => now()->subDay(),
        ]);
    }
}
