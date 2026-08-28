<?php

namespace Database\Factories;

use App\Models\Candidate;
use App\Models\PollCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Candidate>
 */
class CandidateFactory extends Factory
{
    protected $model = Candidate::class;

    public function definition(): array
    {
        return [
            'poll_category_id' => PollCategory::factory(),
            'name' => fake()->name(),
            'biography' => fake()->sentence(),
            // Unique DANS LA CATEGORIE seulement. La sequence globale de la
            // fabrique evite les collisions quand plusieurs candidats sont
            // crees dans la meme categorie sans numero explicite.
            'number' => (string) fake()->unique()->numberBetween(1, 999999),
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Concours qui ne numerote pas ses candidats. */
    public function unnumbered(): static
    {
        return $this->state(fn () => ['number' => null]);
    }
}
