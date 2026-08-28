@props([
    /*
     * Hauteur du pictogramme, en millimetres : le PDF raisonne en mesures
     * physiques, pas en pixels.
     *
     * La taille du mot-symbole est declaree SEPAREMENT, en points, et non
     * deduite de cette hauteur. Les deux unites ne se convertissent pas
     * naivement — une premiere version ecrivait `font-size: {hauteur * 1,9}mm`,
     * ce qui donnait du 37pt a cote d'un pictogramme de 7mm : un nom trois fois
     * trop gros pose contre un logo minuscule.
     */
    'height' => 10,
    'size' => 15,
    // `ink`   : « Pass » en encre, « 241 » en bleu. Sur fond clair.
    // `light` : « Pass » en blanc, « 241 » en bleu clair. Sur le bandeau azur
    //           du billet.
    'variant' => 'ink',
    'note' => null,
])

@php
    /*
     * Le pictogramme est INTEGRE EN BASE64, pas reference par un chemin.
     *
     * Le moteur de rendu PDF ne partage ni le contexte HTTP ni la racine web :
     * un `src="/brand/..."` ne resout nulle part, et un chemin de fichier
     * dependrait de la configuration `chroot` du moteur — qui differe entre le
     * conteneur de developpement et la production. Un recu qui sortirait sans
     * logo une fois deploye serait decouvert par le premier client a le
     * reclamer.
     *
     * Le fichier est lu une fois par processus : un envoi de cent recus en file
     * d'attente ne le relit pas cent fois.
     */
    $logo = \Illuminate\Support\Facades\Cache::driver('array')->rememberForever(
        'pdf:brand-mark',
        fn () => base64_encode((string) @file_get_contents(public_path('brand/pass241-mark.png'))),
    );

    /*
     * Couleurs ecrites en dur, et c'est necessaire : un PDF n'a pas de theme,
     * et le moteur de rendu ne resout pas les variables CSS. Ce sont les
     * memes valeurs que `--brand-ink` et `--brand-blue`, relevees sur le logo.
     */
    $ink = $variant === 'light' ? '#FFFFFF' : '#011136';
    $blue = $variant === 'light' ? '#8FC6FF' : '#0077FC';
@endphp

{{--
    Bloc de marque des documents imprimes : billet, recu de paiement, recu de
    don.

    Le meme couple pictogramme + mot-symbole que l'interface, et la meme
    composition — « Pass » en encre, « 241 » en bleu. Un recu qui ne ressemble
    pas au produit qui l'a emis se fait prendre pour une contrefacon, et c'est
    la piece qu'un client transmet a son comptable ou a sa banque.

    Mise en page en tableau : le moteur de rendu PDF ne connait ni flexbox ni
    grid. `vertical-align` sur les cellules est le seul alignement fiable.
--}}
<table cellpadding="0" cellspacing="0">
    <tr>
        @if ($logo !== '')
            <td style="vertical-align: middle; padding-right: 2.5mm;">
                <img src="data:image/png;base64,{{ $logo }}"
                     style="height: {{ $height }}mm; width: auto;" alt="">
            </td>
        @endif
        <td style="vertical-align: middle;">
            <span style="font-size: {{ $size }}pt; font-weight: bold; color: {{ $ink }};">Pass</span><span
                  style="font-size: {{ $size }}pt; font-weight: bold; color: {{ $blue }};">241</span>
            @if ($note)
                <br><span style="font-size: 7.5pt; color: #8A94A3;">{{ $note }}</span>
            @endif
        </td>
    </tr>
</table>
