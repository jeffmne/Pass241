<?php

namespace App\Services\Ticketing;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Support\Facades\Cache;

/**
 * Rendu des QR Codes.
 *
 * SVG et non PNG : un QR est un dessin de rectangles, il reste net a toute
 * taille, pese moins qu'une image, et surtout s'imprime correctement — un
 * billet finit souvent sur une feuille A4 a l'entree d'un evenement.
 *
 * Correction d'erreur au niveau haut : a l'entree d'un concert, le telephone
 * est tenu a la main, l'ecran est raye et la lumiere est mauvaise. La marge de
 * reconstruction compte plus que la compacite du dessin (sections 18, 28).
 *
 * Le contenu encode n'est jamais un identifiant brut mais un jeton signe
 * (section 18). Ce service ne s'occupe que du dessin ; la signature reviendra
 * a TicketTokenService au jalon du controle d'acces.
 */
class QrRenderer
{
    /**
     * Rend un QR Code en SVG, sans en-tete XML pour pouvoir etre insere
     * directement dans une page.
     */
    public function svg(string $data, int $size = 320): string
    {
        return Cache::remember(
            'qr:'.md5($data.'|'.$size),
            now()->addDay(),
            fn () => $this->render($data, $size),
        );
    }

    /**
     * Rend un QR Code en PNG, encode en data URI.
     *
     * Reserve au PDF. Le moteur de rendu PDF ne dessine pas le SVG de facon
     * fiable — le code peut disparaitre completement du document, ce qui rend
     * le billet inutilisable a l'entree. Le PNG, lui, est toujours rendu.
     *
     * Genere a haute resolution puis reduit par la mise en page : a la taille
     * d'impression, les modules restent nets.
     */
    public function pngDataUri(string $data, int $size = 600): string
    {
        return Cache::remember(
            'qr-png:'.md5($data.'|'.$size),
            now()->addDay(),
            function () use ($data, $size) {
                $result = new Builder(
                    writer: new PngWriter,
                    data: $data,
                    errorCorrectionLevel: ErrorCorrectionLevel::High,
                    size: $size,
                    margin: 0,
                    roundBlockSizeMode: RoundBlockSizeMode::Margin,
                    foregroundColor: new Color(16, 19, 26),
                    backgroundColor: new Color(255, 255, 255),
                )->build();

                return $result->getDataUri();
            },
        );
    }

    private function render(string $data, int $size): string
    {
        $result = new Builder(
            writer: new SvgWriter,
            writerOptions: [
                SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
                // Le fond est gere par l'encart blanc opaque qui accueille le
                // QR (regle 70.4) : le dessin lui-meme reste transparent.
                SvgWriter::WRITER_OPTION_FORCE_XLINK_HREF => false,
            ],
            data: $data,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
            // Noir legerement bleute de la palette plutot que du noir pur : le
            // contraste reste maximal pour un lecteur, et le QR appartient au
            // meme monde visuel que le reste (section 70.1).
            foregroundColor: new Color(16, 19, 26),
            backgroundColor: new Color(255, 255, 255, 127),
        )->build();

        return $result->getString();
    }
}
