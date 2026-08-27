<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $name = 'Collecte '.fake()->unique()->word().' '.fake()->numberBetween(2026, 2028);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            // Entiers XAF (decision D-003).
            'goal_amount' => 1_000_000,
            'beneficiary_label' => 'Famille Ndong',
            'ends_at' => now()->addMonth(),
            'status' => CampaignStatus::Draft,
            'poster_path' => 'campaigns/demo/image.jpg',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Paused,
            'published_at' => now()->subWeek(),
        ]);
    }

    public function cancelled(string $reason = 'Collecte arretee par l\'organisateur.'): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Cancelled,
            'published_at' => now()->subWeek(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /** Publiee, mais dont la date de fin est passee. */
    public function lapsed(): static
    {
        return $this->state(fn () => [
            'status' => CampaignStatus::Published,
            'published_at' => now()->subMonths(2),
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }
}
