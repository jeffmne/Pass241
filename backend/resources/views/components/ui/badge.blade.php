@props([
    // success | warning | danger | info | neutral
    'tone' => 'neutral',
    // Retire le point de couleur. A n'utiliser que la ou la pastille est deja
    // accompagnee d'une icone qui porte le sens.
    'bare' => false,
])

{{--
    Pastille de statut. Le composant unique de tous les etats du produit
    (regle 12 et regle 34) : commande, paiement, reversement, evenement,
    collecte, invitation, membre d'equipe.

    Elle porte TOUJOURS un point en plus de sa couleur. Un deuteranope ne
    distingue pas le vert du orange, et ces deux couleurs separent ici « Payee »
    de « En attente » — l'information la plus consultee de la billetterie. Le
    point donne une seconde marque, et le libelle une troisieme : le sens ne
    repose jamais sur la seule couleur.
--}}
<span {{ $attributes->class([
    'badge',
    'badge-bare' => $bare,
    'badge-success' => $tone === 'success',
    'badge-warning' => $tone === 'warning',
    'badge-danger' => $tone === 'danger',
    'badge-info' => $tone === 'info',
    'badge-neutral' => $tone === 'neutral',
]) }}>{{ $slot }}</span>
