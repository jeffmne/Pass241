<?php

namespace App\Services\Ticketing;

use App\Models\Event;
use App\Models\Ticket;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Version imprimable du billet (sections 15.2, 69, 70.5).
 *
 * Ce document n'est plus telechargeable depuis la plateforme : le bouton de la
 * page billet produit une image, qui est le meme objet que celui affiche a
 * l'ecran. Le PDF ne part plus que par email, ou il repond a un autre besoin —
 * une piece jointe qu'on archive, qu'on transfere, et surtout qu'on imprime.
 *
 * Il ne peut pas etre le jumeau exact du billet a l'ecran : le moteur de rendu
 * ne connait ni flexbox, ni `backdrop-filter`, ni les degrades du verre
 * liquide. La composition est refaite en tableaux et positionnement absolu,
 * seule technique au resultat previsible sur une feuille A4.
 *
 * Le document tient sur une seule page. C'est une contrainte, pas une
 * preference : une deuxieme page sort de l'imprimante avec la moitie du billet
 * dessus, et personne ne sait laquelle presenter a l'entree.
 */
class TicketPdf
{
    public function __construct(private readonly QrRenderer $qr) {}

    /**
     * Rend le billet et renvoie les octets du PDF.
     */
    public function render(Ticket $ticket): string
    {
        return Pdf::loadView('pdf.ticket', [
            'ticket' => $ticket,
            'event' => $ticket->event,
            'item' => $ticket->orderItem,
            // PNG et non SVG : le moteur de rendu n'applique pas les
            // dimensions CSS a un SVG inline. Le QR sortait a sa taille
            // intrinseque, debordait du cadre et disparaissait du document —
            // un billet sans QR n'ouvre aucune porte. Le PNG est genere en
            // haute resolution puis reduit par la mise en page, ce qui laisse
            // les modules nets meme sur une impression laser mediocre.
            'qr' => $this->qr->pngDataUri($ticket->qrToken(), 600),
            'banner' => $this->embeddedBanner($ticket->event),
            // Le lien signe fait cent cinquante caracteres : imprime, il ne se
            // recopie pas, et il etale la signature du billet sur le papier.
            // On imprime ce qui est reellement utilisable au telephone — le
            // domaine, et la reference de commande a saisir.
            'siteHost' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'pass241.ga',
            'orderReference' => $ticket->order?->reference,
            'tz' => config('app.display_timezone'),
        ])->setPaper('a4')->output();
    }

    public function filename(Ticket $ticket): string
    {
        return 'billet-'.$ticket->reference.'.pdf';
    }

    /**
     * Largeur a laquelle la banniere est reencodee.
     *
     * Le cadre fait 110mm de large, soit environ 1300 pixels a 300 points par
     * pouce. 1000 pixels suffisent largement : au-dela, le poids monte sans
     * qu'aucune imprimante ne rende la difference.
     */
    private const BANNER_WIDTH = 1000;

    /**
     * Hauteur de la banniere, deduite du cadre de 110mm sur 36mm.
     *
     * L'image est recadree a ce rapport AVANT d'entrer dans le document, parce
     * que le moteur de rendu ne connait pas `background-size: cover` : une
     * affiche laissee a ses proportions d'origine ressort minuscule dans un
     * coin, et le cadre affiche sa couleur de repli.
     */
    private const BANNER_HEIGHT = 327;

    /**
     * Banniere de l'evenement, encodee directement dans le document.
     *
     * Lue sur le disque et integree en data URI plutot que referencee par URL :
     * le moteur PDF devrait sinon aller la chercher par le reseau, ce qui
     * suppose qu'il puisse joindre l'application depuis l'interieur du
     * conteneur — et ferait echouer le rendu silencieusement si ce n'etait pas
     * le cas.
     *
     * Reencodee en JPEG, et ce n'est pas un detail : l'affiche d'origine est
     * souvent un PNG de plusieurs centaines de kilo-octets. Jointe telle quelle
     * a une commande de dix billets, elle produit un email de plusieurs
     * mega-octets, que les messageries rejettent ou classent en indesirable.
     * L'email est le canal de secours du billet — il doit arriver.
     */
    private function embeddedBanner(Event $event): ?string
    {
        $path = $event->cover_path ?? $event->poster_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('public')->get($path);

        // Si le recadrage echoue, le billet part SANS banniere plutot qu'avec
        // l'original : au format impose par le cadre, une image non recadree
        // sortirait etiree, ce qui se voit plus qu'une absence.
        return Cache::remember(
            'ticket-pdf-banner:'.md5($path.'|'.strlen($contents)),
            now()->addWeek(),
            fn () => $this->downscale($contents),
        );
    }

    /**
     * Recadre la banniere au format du cadre et la reencode en JPEG.
     *
     * Reproduit a la main ce que `object-fit: cover` fait a l'ecran : l'image
     * est agrandie jusqu'a couvrir le cadre, puis rognee. Le point d'interet
     * est place a 32 % de la hauteur, comme sur le billet affiche — sur une
     * affiche de concert, c'est la ou se trouvent les visages.
     *
     * Renvoie null si l'image est illisible : le billet part alors avec
     * l'original, ou sans banniere. Une affiche corrompue ne doit jamais
     * empecher un billet d'arriver.
     */
    private function downscale(string $contents): ?string
    {
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);

            return null;
        }

        // Echelle retenue : la plus grande des deux, donc celle qui couvre.
        $scale = max(
            self::BANNER_WIDTH / $sourceWidth,
            self::BANNER_HEIGHT / $sourceHeight,
        );

        $cropWidth = (int) min($sourceWidth, round(self::BANNER_WIDTH / $scale));
        $cropHeight = (int) min($sourceHeight, round(self::BANNER_HEIGHT / $scale));

        $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
        $cropY = (int) round(($sourceHeight - $cropHeight) * 0.32);

        $target = imagecreatetruecolor(self::BANNER_WIDTH, self::BANNER_HEIGHT);

        // Le JPEG ignore la transparence : sans ce fond, une affiche PNG
        // transparente ressortirait sur du noir.
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));

        imagecopyresampled(
            $target, $source,
            0, 0,
            $cropX, $cropY,
            self::BANNER_WIDTH, self::BANNER_HEIGHT,
            $cropWidth, $cropHeight,
        );

        $this->burnScrim($target);

        ob_start();
        imagejpeg($target, null, 82);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }

    /**
     * Grave le voile sombre dans le bas de l'image.
     *
     * Le voile n'est pas decoratif : sans lui, la lisibilite du titre depend de
     * l'affiche choisie par l'organisateur, et un titre blanc sur une photo
     * claire devient illisible.
     *
     * Dessine ici plutot qu'en CSS parce que le moteur de rendu ne connait pas
     * les degrades : il ne saurait produire qu'un rectangle semi-transparent,
     * dont l'arete franche coupe l'affiche en deux. Peint ligne par ligne,
     * le degrade est reel, et identique a celui du billet affiche.
     */
    private function burnScrim(\GdImage $image): void
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Le voile demarre a mi-hauteur : au-dessus, l'affiche reste intacte.
        $start = (int) round($height * 0.42);

        for ($y = $start; $y < $height; $y++) {
            $progress = ($y - $start) / max(1, $height - 1 - $start);

            // Progression quadratique : l'assombrissement est imperceptible au
            // depart et se concentre sous le texte, la ou il sert.
            $opacity = $progress ** 2 * 0.88;

            // GD compte l'alpha a l'envers : 0 est opaque, 127 transparent.
            $alpha = (int) round(127 - $opacity * 127);

            $colour = imagecolorallocatealpha($image, 16, 19, 26, $alpha);
            imagefilledrectangle($image, 0, $y, $width - 1, $y, $colour);
        }
    }
}
