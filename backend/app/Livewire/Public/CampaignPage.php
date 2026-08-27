<?php

namespace App\Livewire\Public;

use App\Models\Campaign;
use App\Models\Donation;
use App\Services\Fundraising\CampaignProgress;
use App\Services\Ticketing\QrRenderer;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Page publique d'une collecte (sections 36, 37, 54).
 *
 * Rendue cote serveur, sans authentification. C'est la page que l'organisateur
 * partage lui-meme : elle doit s'ouvrir vite sur un telephone et produire un bel
 * apercu quand le lien est transfere dans un message.
 *
 * LA TRANSPARENCE EST LE PRODUIT (section 37). Montant collecte, pourcentage et
 * nombre de dons sont affiches publiquement, et calcules cote serveur a chaque
 * rendu — jamais lus sur une colonne que l'organisateur pourrait toucher
 * (regle 80.1). Le visiteur voit la meme chose que l'organisateur.
 */
#[Layout('components.layouts.public')]
class CampaignPage extends Component
{
    public Campaign $campaign;

    /**
     * Nombre de dons affiches dans l'historique public (section 43).
     *
     * Une page qui chargerait dix mille dons d'un coup serait illisible et
     * couteuse sur un telephone. Le visiteur en demande davantage s'il le
     * souhaite ; le TOTAL, lui, est toujours annonce — c'est lui qui fait la
     * transparence, pas la longueur de la liste.
     */
    public int $visible = self::PAGE;

    private const PAGE = 10;

    /** Plafond de securite : au-dela, plus personne ne fait defiler. */
    private const MAX_VISIBLE = 200;

    public function mount(string $slug): void
    {
        // `publicBySlug` leve la portee par organisation — un visiteur n'est
        // membre d'aucune — et exclut les brouillons, qui doivent renvoyer 404
        // et non une page vide.
        $this->campaign = Campaign::publicBySlug($slug) ?? abort(404);
    }

    public function loadMore(): void
    {
        $this->visible = min($this->visible + self::PAGE, self::MAX_VISIBLE);
    }

    /**
     * Historique public des dons (sections 40, 41, 43).
     *
     * Le montant est TOUJOURS visible ; seule l'identite suit le choix du
     * donateur, et c'est `publicDonorName()` qui l'applique — a un seul
     * endroit, pour que la page publique et le tableau de bord de
     * l'organisateur disent exactement la meme chose.
     *
     * La portee par organisation est levee : un visiteur n'appartient a aucune
     * organisation. Il n'y a aucune fuite possible, la requete etant bornee aux
     * dons de CETTE campagne.
     *
     * @return \Illuminate\Support\Collection<int, Donation>
     */
    private function history()
    {
        return Donation::query()
            ->acrossOrganizations()
            ->where('campaign_id', $this->campaign->getKey())
            ->confirmed()
            ->orderByDesc('confirmed_at')
            ->orderByDesc('id')
            ->limit($this->visible)
            ->get();
    }

    public function render(CampaignProgress $progress, QrRenderer $qr)
    {
        $campaign = $this->campaign;

        /*
         * L'apercu de partage reprend l'objectif et le beneficiaire : c'est ce
         * que cherche quelqu'un a qui on vient de transferer un lien de
         * collecte — pour qui, et pour quel montant.
         */
        $social = trim(sprintf(
            'Collecte au profit de %s. %s',
            $campaign->beneficiary_label,
            Str::of((string) $campaign->description)->stripTags()->squish()->limit(120)
        ));

        $donations = $this->history();

        return view('livewire.public.campaign-page', [
            'progress' => $progress->forCampaign($campaign),
            'donations' => $donations,
            'hasMore' => $donations->count() >= $this->visible,
            // Section 54 : le QR ramene a cette page. Rendu en SVG, sans appel
            // reseau, pour qu'une affiche puisse etre montee depuis un
            // telephone.
            'qrCode' => $qr->svg($campaign->publicUrl()),
        ])->layoutData([
            'title' => $campaign->name.' — Pass241',
            'description' => $social,
            'image' => $campaign->coverUrl(),
            'canonical' => $campaign->publicUrl(),
            'indexable' => true,
            'structuredData' => $this->structuredData($social),
            // Le heros n'est sombre que s'il porte une image : sans banniere,
            // la page ouvre sur la nappe azurine claire et l'en-tete doit
            // rester en bleu, faute de quoi il deviendrait invisible.
            'heroDark' => $campaign->coverUrl() !== null,
        ]);
    }

    /**
     * Donnees structurees schema.org, deja encodees.
     *
     * Construites ici et non dans la vue : les cles du vocabulaire commencent
     * par `@` (`@context`, `@type`), que Blade prendrait pour des directives.
     */
    private function structuredData(string $description): string
    {
        $campaign = $this->campaign;

        $schema = array_filter([
            '@context' => 'https://schema.org',
            // `DonateAction` decrit exactement ce que la page propose, et se
            // lit mieux qu'un `WebPage` generique par les moteurs.
            '@type' => 'DonateAction',
            'name' => $campaign->name,
            'description' => $description,
            'url' => $campaign->publicUrl(),
            'startTime' => $campaign->starts_at?->toIso8601String(),
            'endTime' => $campaign->ends_at?->toIso8601String(),
            'image' => array_values(array_filter([$campaign->coverUrl()])),
            'recipient' => array_filter([
                '@type' => 'Organization',
                'name' => $campaign->organization?->name,
            ]),
        ], fn ($value) => $value !== null && $value !== []);

        return json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
