@props([
    /*
     * `ink`   : « Pass » a l'encre du texte courant, « 241 » en bleu. C'est le
     *           defaut, et la composition du logo.
     * `light` : « Pass » en blanc, « 241 » en bleu clair. Pour les fonds
     *           sombres ou azurins — bandeau du billet, en-tete pose sur une
     *           affiche.
     * `mono`  : les deux parties a la couleur du texte. La ou la couleur ne
     *           peut pas etre garantie lisible, ou ne survivrait pas au
     *           support : impression en noir et blanc, capture d'image.
     */
    'variant' => 'ink',
])

{{--
    Mot-symbole « Pass241 ».

    Il reprend la composition du logo : « Pass » a l'encre, « 241 » en bleu. Ce
    n'est pas une decoration — c'est ce qui fait lire le nom comme une marque
    plutot que comme un mot suivi d'un nombre.

    IL RESTE DU TEXTE, jamais une image. Il se selectionne, se copie, se
    recherche dans la page, se lit a la voix, suit la graisse et la taille du
    contexte, et reste net a toutes les densites d'ecran. Un mot-symbole
    vectorise ou rasterise perd les six.

    Le decoupage en deux `<span>` ne casse RIEN pour un lecteur d'ecran : les
    deux fragments sont contigus dans le meme element, donc annonces comme un
    seul mot. Aucun `aria-label` n'est necessaire.

    LES DEUX COULEURS SONT POSEES ICI, jamais heritees. Plusieurs en-tetes
    peignaient tout leur contenu en azur : « Pass » y devenait bleu, et le nom
    perdait le contraste des deux parties qui fait sa lecture. Le composant fixe
    donc ses deux teintes lui-meme, et le contexte n'a plus prise dessus.

    LES TEINTES SONT CELLES DU LOGO, echantillonnees dans le fichier fourni et
    non approchees a l'oeil : `--brand-ink` (#011136) pour « Pass »,
    `--brand-blue` (#0077fc) pour « 241 ». Ce n'est PAS l'encre du texte
    courant : « Pass » est un bleu marine sombre, pas un noir, et la difference
    se voit des qu'on pose le nom a cote du pictogramme.

    Les deux jetons s'eclaircissent en mode sombre. Un marine a #011136 y serait
    purement et simplement invisible : la marque doit rester lisible, pas
    seulement fidele.

    `whitespace-nowrap` : le nom ne doit jamais se couper entre « Pass » et
    « 241 » en fin de ligne etroite.
--}}
<span {{ $attributes->class('whitespace-nowrap') }}><span @class([
    'text-brand-ink' => $variant === 'ink',
    'text-white' => $variant === 'light',
])>Pass</span><span @class([
    'text-brand-blue' => $variant === 'ink',
    'text-azure-300' => $variant === 'light',
])>241</span></span>
