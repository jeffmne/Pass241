<?php

namespace App\Data;

use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Donnees d'une campagne en cours de creation ou de modification.
 *
 * Objet dedie plutot qu'un tableau, comme `EventDraft` : les actions recoivent
 * des valeurs deja typees et deja converties, ce qui evite que chaque appelant
 * refasse la meme conversion — et l'oublie une fois sur trois.
 *
 * Ne contient volontairement ni statut, ni slug, ni date de publication, NI
 * AUCUN MONTANT COLLECTE : ces valeurs ne viennent jamais d'un formulaire
 * (sections 38, 50, 67).
 */
readonly class CampaignDraft
{
    public function __construct(
        public string $name,
        public int $goalAmount,
        public string $beneficiaryLabel,
        public ?string $description = null,
        public ?CarbonInterface $startsAt = null,
        public ?CarbonInterface $endsAt = null,
        public ?UploadedFile $poster = null,
        public ?UploadedFile $cover = null,
    ) {}

    /** @return array<string, mixed> */
    public function campaignAttributes(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'goal_amount' => $this->goalAmount,
            'beneficiary_label' => $this->beneficiaryLabel,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }

    /**
     * Ce qui reste modifiable apres publication.
     *
     * L'objectif, le beneficiaire et les dates engagent ceux qui ont deja
     * donne : les changer apres coup reviendrait a deplacer la cible. La
     * description et les images, elles, doivent pouvoir etre corrigees — une
     * faute de frappe sur une page partagee se repare.
     *
     * @return array<string, mixed>
     */
    public function publishedAttributes(): array
    {
        return [
            'description' => $this->description,
        ];
    }
}
