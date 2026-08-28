<?php

namespace Database\Factories;

use App\Enums\ResultsVisibility;
use App\Models\Poll;
use App\Models\PollCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PollCategory>
 */
class PollCategoryFactory extends Factory
{
    protected $model = PollCategory::class;

    public function definition(): array
    {
        return [
            'poll_id' => Poll::factory(),
            'name' => 'Categorie '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            // Gratuite par defaut : c'est l'etat qui n'engage aucun argent, donc
            // celui qu'un test qui ne parle pas de paiement doit obtenir.
            'is_paid' => false,
            'unit_price' => 0,
            'max_votes_per_voter' => 1,
            'is_active' => true,
            'position' => 0,
        ];
    }

    /**
     * Categorie payante.
     *
     * Sans plafond par defaut : dans un vote payant, chaque vote paye compte, et
     * c'est le paiement qui fait la limite (section 86.3).
     */
    public function paid(int $unitPrice = 500): static
    {
        return $this->state(fn () => [
            'is_paid' => true,
            'unit_price' => $unitPrice,
            'max_votes_per_voter' => null,
        ]);
    }

    public function limitedTo(int $votes): static
    {
        return $this->state(fn () => ['max_votes_per_voter' => $votes]);
    }

    /** Illimite : l'organisateur n'a pose aucun plafond. */
    public function unlimited(): static
    {
        return $this->state(fn () => ['max_votes_per_voter' => null]);
    }

    /** Fenetre propre, plus etroite que celle du scrutin. */
    public function window(\DateTimeInterface $startsAt, \DateTimeInterface $endsAt): static
    {
        return $this->state(fn () => [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function resultsLive(): static
    {
        return $this->state(fn () => ['results_visibility' => ResultsVisibility::Live]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
