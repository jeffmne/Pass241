@props([
    // Titre annonce aux lecteurs d'ecran, jamais affiche : il dit de quoi
    // parle le tableau a quelqu'un qui ne voit pas l'en-tete de section.
    'caption' => null,
    // `stack` : chaque ligne devient une carte sous `md` (defaut, mobile-first).
    // `scroll` : le tableau garde sa forme et defile lateralement — pour les
    //            tableaux dont les colonnes se comparent entre elles (un
    //            journal d'audit, une suite de montants).
    'responsive' => 'stack',
])

{{--
    Tableau de donnees. UNE seule ecriture pour tout le produit (regle 34) :
    commandes, participants, transactions, reversements, journal d'audit.

    Surface pleine et non du verre : un tableau est ce qu'on relit ligne a
    ligne, et le flou lui retire precisement le contraste dont cette lecture
    depend (section 70.4).

    Le cadre porte `scroll-x` meme en mode empile : il garantit qu'un contenu
    trop large defile DANS son cadre et n'emporte jamais la page avec lui.
    C'est la cause numero un de debordement horizontal sur telephone.
--}}
<div {{ $attributes->class('scroll-x -mx-4 sm:mx-0') }}>
    <table @class([
        'data-table min-w-full',
        'data-table-stack' => $responsive === 'stack',
    ])>
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif

        <thead>
            <tr>{{ $head }}</tr>
        </thead>

        <tbody>
            {{ $slot }}
        </tbody>
    </table>
</div>
