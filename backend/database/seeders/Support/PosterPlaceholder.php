<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Affiche de remplacement, generee localement.
 *
 * Un aplat azurin avec le nom de l'evenement : ce n'est pas du design, mais
 * cela evite deux choses — dependre d'une image telechargee depuis l'exterieur,
 * et publier un evenement sans affiche alors que le modele l'interdit.
 *
 * Extraite du seeder principal le jour ou un second seeder en a eu besoin :
 * deux copies auraient diverge, et l'affiche des donnees de demonstration doit
 * rester la meme partout.
 */
class PosterPlaceholder
{
    public static function for(string $slug, string $name): string
    {
        $path = "events/demo/{$slug}.png";

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        $width = 800;
        $height = 1000;

        $image = imagecreatetruecolor($width, $height);

        // Degrade vertical dans la famille azurine (section 70.1).
        for ($y = 0; $y < $height; $y++) {
            $ratio = $y / $height;
            $color = imagecolorallocate(
                $image,
                (int) (47 + (20 - 47) * $ratio),
                (int) (143 + (84 - 143) * $ratio),
                (int) (230 + (155 - 230) * $ratio),
            );
            imagefilledrectangle($image, 0, $y, $width, $y, $color);
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $line = 0;
        foreach (explode("\n", wordwrap($name, 16, "\n", true)) as $text) {
            imagestring($image, 5, 60, 420 + ($line * 40), $text, $white);
            $line++;
        }

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $binary);

        return $path;
    }
}
