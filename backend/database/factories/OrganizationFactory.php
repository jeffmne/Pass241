<?php

namespace Database\Factories;

use App\Enums\KycStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'type' => fake()->randomElement(OrganizationType::cases()),
            'contact_email' => fake()->safeEmail(),
            'contact_phone' => '074'.fake()->numerify('######'),
            'kyc_status' => KycStatus::Pending,
            'owner_id' => User::factory(),
        ];
    }

    public function kycVerified(): static
    {
        return $this->state(fn () => [
            'kyc_status' => KycStatus::Verified,
            'kyc_submitted_at' => now()->subDays(3),
            'kyc_verified_at' => now()->subDay(),
        ]);
    }

    public function kycSubmitted(): static
    {
        return $this->state(fn () => [
            'kyc_status' => KycStatus::Submitted,
            'kyc_submitted_at' => now()->subDay(),
        ]);
    }

    public function kycRejected(string $reason = 'Document illisible.'): static
    {
        return $this->state(fn () => [
            'kyc_status' => KycStatus::Rejected,
            'kyc_submitted_at' => now()->subDays(2),
            'kyc_rejection_reason' => $reason,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['suspended_at' => now()]);
    }

    public function individual(): static
    {
        return $this->state(fn () => ['type' => OrganizationType::Individual]);
    }
}
