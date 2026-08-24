<?php

namespace App\Data;

use App\Enums\EventVisibility;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Donnees d'un evenement en cours de creation ou de modification.
 *
 * Objet dedie plutot qu'un tableau : les actions recoivent des valeurs deja
 * typees et deja converties (dates en objets, visibilite en enum), ce qui evite
 * que chaque appelant refasse la meme conversion — et l'oublie une fois sur
 * trois.
 *
 * Ne contient volontairement ni statut, ni slug, ni date de publication : ces
 * valeurs ne sont jamais fournies par un formulaire (section 67).
 */
readonly class EventDraft
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public string $name,
        public CarbonInterface $startsAt,
        public string $locationName,
        public ?string $description = null,
        public ?string $programme = null,
        public ?string $practicalInfo = null,
        public array $categories = [],
        public ?CarbonInterface $endsAt = null,
        public ?int $maxCapacity = null,
        public EventVisibility $visibility = EventVisibility::Listed,
        public ?string $locationAddress = null,
        public ?string $locationCity = null,
        public ?UploadedFile $poster = null,
        public ?UploadedFile $cover = null,
    ) {}

    /** @return array<string, mixed> */
    public function eventAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'programme' => $this->programme,
            'practical_info' => $this->practicalInfo,
            'categories' => $this->categories,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'max_capacity' => $this->maxCapacity,
            'visibility' => $this->visibility,
        ];
    }

    /** @return array<string, mixed> */
    public function locationAttributes(): array
    {
        return array_filter([
            'name' => $this->locationName,
            'address' => $this->locationAddress,
            'city' => $this->locationCity,
        ], fn ($value) => $value !== null);
    }
}
