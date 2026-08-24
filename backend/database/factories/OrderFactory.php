<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Models\Event;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'reference' => 'PS241-'.strtoupper(fake()->unique()->bothify('????-??#')),
            'first_name' => 'Awa',
            'last_name' => 'Ndong',
            'email' => fake()->safeEmail(),
            'payment_provider' => PaymentProvider::Airtel,
            'payer_phone' => '074'.fake()->numerify('#####'),
            'status' => OrderStatus::Pending,
            'amount' => 10_000,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'Paiement non confirme dans le delai imparti.',
        ]);
    }

    /** Commande en attente deja arrivee a echeance. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
