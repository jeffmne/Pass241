<?php

namespace App\Data;

use App\Enums\ResultsVisibility;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Donnees d'un scrutin en cours de creation ou de modification.
 *
 * Objet dedie plutot qu'un tableau, comme `CampaignDraft` : les actions
 * recoivent des valeurs deja typees et deja converties, ce qui evite que chaque
 * appelant refasse la meme conversion — et l'oublie une fois sur trois.
 *
 * Ne contient volontairement ni statut, ni slug, ni date de publication, NI
 * AUCUN TOTAL DE VOTES : ces valeurs ne viennent jamais d'un formulaire
 * (sections 86.4, 67).
 */
readonly class PollDraft
{
    public function __construct(
        public string $name,
        public CarbonInterface $startsAt,
        public CarbonInterface $endsAt,
        public ?string $description = null,
        public ResultsVisibility $resultsVisibility = ResultsVisibility::AfterClose,
        public ?UploadedFile $poster = null,
        public ?UploadedFile $banner = null,
    ) {}

    /** @return array<string, mixed> */
    public function pollAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'results_visibility' => $this->resultsVisibility,
        ];
    }

    /**
     * Ce qui reste modifiable une fois le vote ouvert.
     *
     * La fenetre de vote et la visibilite des resultats sont les REGLES DU JEU :
     * les changer pendant la partie reviendrait a rallonger un scrutin qui se
     * joue mal, ou a devoiler un classement qu'on avait promis scelle. Ceux qui
     * ont vote — et surtout ceux qui ont paye pour voter — l'ont fait sous les
     * regles annoncees.
     *
     * La description, elle, doit pouvoir etre corrigee : une faute de frappe sur
     * une page partagee se repare.
     *
     * @return array<string, mixed>
     */
    public function openedAttributes(): array
    {
        return [
            'description' => $this->description,
        ];
    }
}
