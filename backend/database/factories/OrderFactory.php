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
            /*
             * Par defaut, une commande sans frais de prestataire : le montant
             * debite est le prix des billets. `subtotal` suit donc `amount`,
             * y compris quand un test le redefinit — la base exige que
             * l'eclatement tombe juste (decision D-023).
             *
             * Un test qui veut des frais passe par l'etat `withPspFee`.
             */
            'subtotal' => fn (array $attributes) => $attributes['amount'],
            'psp_fee_amount' => 0,
            'psp' => 'fake',
            'expires_at' => now()->addMinutes(5),
        ];
    }

    /**
     * Commande portant des frais de prestataire.
     *
     * Le montant debite monte, le prix des billets ne bouge pas : c'est
     * exactement ce que la decision D-023 decrit.
     */
    public function withPspFee(int $fee, int $basisPoints = 200): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $attributes['subtotal'] + $fee,
            'psp_fee_amount' => $fee,
            'psp_fee_basis_points' => $basisPoints,
        ]);
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
