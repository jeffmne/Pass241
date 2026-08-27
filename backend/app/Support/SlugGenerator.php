<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Fabrique de slugs uniques.
 *
 * L'unicite est garantie ici plutot que laissee a la contrainte de base : deux
 * organisateurs choisissent souvent le meme titre (« Gala annuel », « BDE »),
 * et une violation de contrainte en pleine creation produirait une erreur
 * illisible au lieu d'un lien legerement different.
 *
 * Le slug reste lisible et partageable (section 13) : il vient du nom, sans
 * identifiant technique accole tant qu'aucune collision ne l'impose.
 */
class SlugGenerator
{
    /**
     * @param  class-string<Model>  $model
     * @param  int|null  $ignoreId  ligne a ignorer lors d'une mise a jour
     * @param  string  $fallback  base du slug quand le nom n'en produit aucune —
     *                            un titre entierement compose de ponctuation ou
     *                            d'ideogrammes ne laisse rien apres `Str::slug`
     */
    public function unique(
        string $model,
        string $source,
        string $column = 'slug',
        ?int $ignoreId = null,
        string $fallback = 'evenement',
    ): string {
        $base = Str::slug($source);

        if ($base === '') {
            $base = $fallback;
        }

        // Marge pour le suffixe numerique, sous la limite d'une colonne string.
        $base = Str::limit($base, 180, '');

        $slug = $base;
        $suffix = 2;

        while ($this->taken($model, $column, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /** @param  class-string<Model>  $model */
    private function taken(string $model, string $column, string $slug, ?int $ignoreId): bool
    {
        $query = $model::query()->where($column, $slug);

        // Les slugs sont uniques sur toute la plateforme, pas par
        // organisation : l'adresse publique ne porte pas de segment
        // d'organisation (section 6). La portee doit donc etre levee.
        if (method_exists($model, 'scopeAcrossOrganizations')) {
            $query->acrossOrganizations();
        }

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query->exists();
    }
}
