<?php

namespace Database\Factories;

use App\Enums\EventCategory;
use App\Enums\EventStatus;
use App\Enums\EventVisibility;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $name = 'Concert '.fake()->unique()->word().' '.fake()->numberBetween(2026, 2028);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'description' => fake()->paragraph(),
            'categories' => [EventCategory::Music->value],
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonth()->addHours(5),
            'max_capacity' => 500,
            'status' => EventStatus::Draft,
            'visibility' => EventVisibility::Listed,
            'poster_path' => 'events/demo/affiche.jpg',
        ];
    }

    public function configure(): static
    {
        // Un evenement sans lieu n'existe pas dans le produit : la factory en
        // cree un systematiquement, sinon chaque test devrait y penser.
        return $this->afterCreating(function (Event $event) {
            if ($event->location === null) {
                $event->location()->create([
                    'name' => 'Institut francais du Gabon',
                    'address' => 'Boulevard Triomphal',
                    'city' => 'Libreville',
                ]);
            }
        });
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => EventStatus::Published,
            'published_at' => now()->subDay(),
        ]);
    }

    public function cancelled(string $reason = 'Report a une date ulterieure.'): static
    {
        return $this->state(fn () => [
            'status' => EventStatus::Cancelled,
            'published_at' => now()->subWeek(),
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    public function unlisted(): static
    {
        return $this->state(fn () => ['visibility' => EventVisibility::Unlisted]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => EventStatus::Archived]);
    }

    public function past(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeeks(2)->addHours(4),
        ]);
    }
}
