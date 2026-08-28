<?php

namespace Database\Factories;

use App\Enums\PollStatus;
use App\Enums\ResultsVisibility;
use App\Models\Poll;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Poll>
 */
class PollFactory extends Factory
{
    protected $model = Poll::class;

    public function definition(): array
    {
        $name = 'Election '.fake()->unique()->word().' '.fake()->numberBetween(2026, 2028);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            // Les deux bornes sont obligatoires : un concours sans date de fin
            // n'a pas de gagnant.
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'status' => PollStatus::Draft,
            'results_visibility' => ResultsVisibility::AfterClose,
            'poster_path' => 'polls/demo/image.jpg',
        ];
    }

    /** Ouvert au vote maintenant. */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => PollStatus::Active,
            'published_at' => now()->subDay(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    /** Publie, mais dont l'ouverture est a venir. */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => PollStatus::Scheduled,
            'published_at' => now()->subDay(),
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => PollStatus::Closed,
            'published_at' => now()->subMonths(2),
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);
    }

    public function cancelled(string $reason = 'Concours annule par l\'organisateur.'): static
    {
        return $this->state(fn () => [
            'status' => PollStatus::Cancelled,
            'published_at' => now()->subWeek(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    /**
     * Actif en base, mais dont l'echeance est passee.
     *
     * Le cas que la tache planifiee n'a pas encore balaye — celui ou le scrutin
     * doit refuser un bulletin alors que sa colonne `status` dit encore
     * « ACTIVE » (section 86.5, point 8).
     */
    public function lapsed(): static
    {
        return $this->state(fn () => [
            'status' => PollStatus::Active,
            'published_at' => now()->subMonths(2),
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function resultsLive(): static
    {
        return $this->state(fn () => ['results_visibility' => ResultsVisibility::Live]);
    }
}
