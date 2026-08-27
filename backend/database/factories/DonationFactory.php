<?php

namespace Database\Factories;

use App\Enums\DonationStatus;
use App\Enums\PaymentProvider;
use App\Models\Campaign;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'reference' => 'DON-'.strtoupper(fake()->unique()->bothify('????-??#')),
            'donor_name' => 'Marie Nzeng',
            'donor_email' => fake()->safeEmail(),
            'is_anonymous' => false,
            'payment_provider' => PaymentProvider::Airtel,
            'payer_phone' => '074'.fake()->numerify('#####'),
            'psp' => 'fake',
            /*
             * Par defaut, un don sans frais de prestataire : le montant debite
             * est le don lui-meme. `amount` suit donc `subtotal`, y compris
             * quand un test le redefinit — la base exige que l'eclatement tombe
             * juste (decision D-023).
             */
            'subtotal' => 10_000,
            'psp_fee_amount' => 0,
            'amount' => fn (array $attributes) => $attributes['subtotal'],
            'status' => DonationStatus::Pending,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    /** Don portant des frais de prestataire, avances par le donateur. */
    public function withPspFee(int $fee, int $basisPoints = 200): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $attributes['subtotal'] + $fee,
            'psp_fee_amount' => $fee,
            'psp_fee_basis_points' => $basisPoints,
        ]);
    }

    /** Le seul etat qui compte dans le montant collecte (section 39). */
    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => DonationStatus::Confirmed,
            'confirmed_at' => now(),
            'expires_at' => null,
        ]);
    }

    public function anonymous(): static
    {
        return $this->state(fn () => ['is_anonymous' => true]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => DonationStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'Paiement non confirme dans le delai imparti.',
        ]);
    }

    /** Don en attente deja arrive a echeance. */
    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => DonationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
