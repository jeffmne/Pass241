<?php

namespace App\Services\Export;

use App\Enums\PermissionName;
use Generator;

/**
 * Ce qui peut etre exporte (section 59).
 *
 * TROIS REGLES VALENT POUR TOUT EXPORT, et elles vivent ici plutot que dans
 * chaque implementation :
 *
 * 1. LES LIGNES SONT UN GENERATEUR, jamais un tableau. Un evenement de cent
 *    mille billets doit produire un fichier sans jamais tenir en memoire — la
 *    section 72 annonce des millions de billets, et un export qui tombe est
 *    pire qu'un export absent : il tombe au moment ou l'organisateur en a
 *    besoin, apres l'evenement.
 *
 * 2. CHAQUE EXPORT DECLARE SA PERMISSION. Un export contourne les ecrans : il
 *    ne doit jamais sortir ce qu'un ecran refuserait d'afficher (section 59).
 *
 * 3. LA PORTEE MULTI-TENANT S'APPLIQUE AUX REQUETES, comme partout. Aucune
 *    implementation ne leve la portee — un export qui traverserait les
 *    organisations serait la pire fuite possible, parce qu'elle partirait dans
 *    un fichier.
 */
interface Exportable
{
    /** Nom lisible, affiche sur le bouton et dans le journal. */
    public function label(): string;

    /**
     * Nom du fichier, sans extension.
     *
     * Il finit dans le dossier de telechargement de l'organisateur, a cote de
     * dizaines d'autres : il doit dire de quoi il s'agit et de quand.
     */
    public function filename(): string;

    /** @return list<string> */
    public function headings(): array;

    /**
     * Lignes, produites au fil de l'eau.
     *
     * @return Generator<int, list<string|int|float|null>>
     */
    public function rows(): Generator;

    /** Permission exigee pour declencher cet export. */
    public function permission(): PermissionName;

    /** @return array<string, mixed> contexte pour le journal (section 68) */
    public function auditContext(): array;
}
