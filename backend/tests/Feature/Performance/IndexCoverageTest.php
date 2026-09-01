<?php

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/*
 * Couverture des index sur les chemins chauds (section 72).
 *
 * CE FICHIER DEFEND UNE IDEE QUI NE SE VOIT PAS EN DEVELOPPEMENT : PostgreSQL
 * n'indexe pas les colonnes de cle etrangere. Il indexe la colonne REFERENCEE —
 * une cle primaire, deja indexee — et rien du tout sur celle qui reference.
 *
 * Le defaut est invisible tant que les tables sont petites : le planificateur
 * prefere de toute facon un parcours sequentiel sur cent lignes, et tous les
 * tests passent. Il n'apparait qu'en production, progressivement, sur les
 * requetes les plus frequentes — c'est-a-dire au pire endroit et au pire
 * moment.
 *
 * Ces tests ne mesurent donc PAS des temps d'execution, qui ne voudraient rien
 * dire sur un jeu de test. Ils verifient la STRUCTURE : l'index existe, il porte
 * la bonne colonne en tete, et il est partiel la ou il doit l'etre.
 */

/**
 * Les index dont depend un chemin chaud, et la raison de chacun.
 */
dataset('index des chemins chauds', [
    'articles d\'une commande' => [
        'order_items', 'order_id',
        'Ouverture d\'une commande, confirmation de paiement, emission des billets.',
    ],
    'billets d\'une organisation' => [
        'tickets', 'organization_id',
        'Vue generale de l\'organisateur : sans lui, elle parcourt les billets de toute la plateforme.',
    ],
    'commande d\'une invitation' => [
        'invitations', 'order_id',
        'Acceptation et annulation d\'une invitation (section 25).',
    ],
]);

it('indexe la colonne en TETE d\'index, seule position utilisable', function (string $table, string $column, string $why) {
    /*
     * En tete, et c'est la subtilite : un index `(a, b)` ne sert pas une
     * requete qui ne filtre que sur `b`. Verifier la simple presence de la
     * colonne quelque part dans un index laisserait passer exactement le defaut
     * qu'on cherche a fermer.
     */
    $leading = DB::table('pg_index as i')
        ->join('pg_class as c', 'c.oid', '=', 'i.indrelid')
        ->join('pg_attribute as a', function ($join) {
            $join->on('a.attrelid', '=', 'i.indrelid')
                ->whereRaw('a.attnum = i.indkey[0]');
        })
        ->where('c.relname', $table)
        ->pluck('a.attname');

    // `assertContains` plutot que `toContain` : lui seul porte un message, et
    // c'est le message qui dira au prochain lecteur POURQUOI cet index existe.
    Assert::assertContains($column, $leading->all(), $why);
})->with('index des chemins chauds');

it('ne garde en index que les callbacks en souffrance', function () {
    /*
     * `payment_webhooks` est en ajout seul : elle ne cesse jamais de grandir.
     * L'ecran de supervision (decision D-063) n'y cherche que les lignes non
     * traitees — quelques-unes au plus, aucune en marche normale.
     *
     * L'index doit donc etre PARTIEL. Un index complet grossirait comme la
     * table pour indexer une information dont on ne consulte qu'une valeur ;
     * celui-ci reste minuscule a jamais.
     */
    $definition = DB::table('pg_indexes')
        ->where('tablename', 'payment_webhooks')
        ->where('indexname', 'payment_webhooks_unprocessed_idx')
        ->value('indexdef');

    expect($definition)->not->toBeNull()
        ->and($definition)->toContain('WHERE (processed_at IS NULL)');
});

it('n\'a laisse aucune cle etrangere chaude sans index', function () {
    /*
     * LE TEST QUI TIENT DANS LA DUREE.
     *
     * Les trois precedents nomment les index d'aujourd'hui. Celui-ci relit le
     * schema et signale toute NOUVELLE cle etrangere ajoutee sur une table
     * chaude sans index — le defaut se reproduira sinon a la prochaine
     * migration, avec la meme discretion.
     *
     * La liste des tables surveillees est volontairement courte : ce sont
     * celles que la section 72 annonce en millions de lignes. Une table de
     * quelques centaines d'entrees n'a rien a gagner a etre indexee, et l'index
     * se paierait a chaque ecriture.
     */
    $hot = ['orders', 'order_items', 'tickets', 'access_scans', 'ballots', 'donations', 'commissions'];

    $unindexed = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text || '.' || a.attname AS colonne
        FROM pg_constraint c
        JOIN LATERAL unnest(c.conkey) WITH ORDINALITY AS k(attnum, ord) ON true
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum
        WHERE c.contype = 'f'
          AND c.connamespace = 'public'::regnamespace
          AND c.conrelid::regclass::text = ANY(?)
          AND k.ord = 1
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid AND i.indkey[0] = k.attnum
          )
        ORDER BY 1
    SQL, ['{'.implode(',', $hot).'}']);

    $names = array_map(fn ($row) => $row->colonne, $unindexed);

    /*
     * Les exceptions assumees, avec leur raison. Les inscrire ici plutot que de
     * les indexer force a justifier chaque cas — et fait echouer le test le jour
     * ou une colonne apparait sans que personne n'ait tranche.
     */
    $accepted = [
        // Toujours interrogee AVEC son evenement, que `(event_id, scanned_at)`
        // couvre deja ; les scans d'un evenement sont bornes par sa jauge.
        'access_scans.entry_point_id',
        // Rapprochement de scans en conflit : lecture rare, sur un volume
        // deja restreint a un evenement.
        'access_scans.conflict_with',
        // Portee multi-tenant, mais toujours combinee a `event_id` en pratique
        // — l'index `(event_id, scanned_at)` mene la requete.
        'access_scans.organization_id',
        // Recherche uniquement par `whereNull`, que l'index unique partiel
        // `commissions_one_original_per_transaction` sert deja.
        'commissions.parent_commission_id',
        // Le billet porte deja `(event_id, status)` et `order_id` ; ces deux
        // colonnes ne servent que de jointure DEPUIS le billet, donc par cle
        // primaire de l'autre cote.
        'tickets.order_item_id',
        'tickets.ticket_type_id',
    ];

    expect(array_diff($names, $accepted))->toBe(
        [],
        'Nouvelle cle etrangere sans index sur une table chaude : indexez-la, '
        .'ou inscrivez-la dans les exceptions avec sa raison.'
    );
});
