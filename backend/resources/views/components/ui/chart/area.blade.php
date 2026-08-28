@props([
    // [['label' => 'Fev', 'value' => 120000, 'display' => '120 000 FCFA'], ...]
    'series' => [],
    'height' => 180,
    'emptyLabel' => 'Aucune donnee sur cette periode',
    'ariaLabel' => 'Donnees du graphique',
])

@php
    $points = array_values($series);
    $count = count($points);
    $values = array_map(static fn (array $point) => (float) ($point['value'] ?? 0), $points);
    $max = $values === [] ? 0 : max($values);

    /*
     * Le trace est calcule ICI, en PHP, et non par le navigateur.
     *
     * Ce n'est pas un detail d'implementation : c'est la meme regle que pour
     * tout chiffre du produit (sections 49, 50). Une courbe de revenus est une
     * representation de donnees financieres — elle ne se calcule pas cote
     * client, ou n'importe qui pourrait la faire raconter autre chose.
     *
     * Repere de 0 a 100 sur les deux axes, avec `preserveAspectRatio="none"` :
     * le SVG s'etire a la largeur disponible sans qu'on ait a connaitre celle-ci.
     * En contrepartie, l'epaisseur du trait serait etiree elle aussi — d'ou
     * `vector-effect: non-scaling-stroke` sur `.chart-line`, qui la fige.
     *
     * Une marge haute de 8 unites empeche le sommet de la courbe d'etre rase
     * par le bord du cadre.
     */
    $coordinates = [];
    foreach ($values as $index => $value) {
        $x = $count > 1 ? $index / ($count - 1) * 100 : 50;
        $y = $max > 0 ? 100 - ($value / $max * 92) : 100;
        $coordinates[] = [round($x, 2), round($y, 2)];
    }

    $linePath = '';
    foreach ($coordinates as $index => [$x, $y]) {
        $linePath .= ($index === 0 ? 'M' : ' L') . $x . ',' . $y;
    }

    // La surface sous la courbe : le meme trace, referme sur la ligne de base.
    $areaPath = $linePath !== ''
        ? $linePath . ' L' . end($coordinates)[0] . ',100 L' . $coordinates[0][0] . ',100 Z'
        : '';

    // Un identifiant unique par instance : deux graphiques sur la meme page
    // partageraient sinon le meme degrade, et le second effacerait le premier.
    $gradientId = 'chart-fade-' . Str::random(6);
@endphp

{{--
    Courbe d'evolution. Un SVG ecrit par le serveur, sans une ligne de
    JavaScript ni une bibliotheque de graphiques (section 72).

    Comme l'histogramme, la courbe est doublee d'un TABLEAU masque
    visuellement : une forme ne dit rien a un lecteur d'ecran, des chiffres si.
--}}
<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($count < 2 || $max <= 0)
        <div class="flex items-center justify-center rounded-[var(--radius-md)] border border-dashed border-grey-200 text-sm text-grey-400"
             style="height: {{ $height }}px">
            {{ $emptyLabel }}
        </div>
    @else
        <div class="chart-grid border-b border-grey-200" style="height: {{ $height }}px">
            <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                 class="h-full w-full overflow-visible" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="{{ $gradientId }}" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--azure-500)" stop-opacity="0.22" />
                        <stop offset="100%" stop-color="var(--azure-500)" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <path d="{{ $areaPath }}" fill="url(#{{ $gradientId }})" />
                <path d="{{ $linePath }}" class="chart-line" />
            </svg>
        </div>

        <div aria-hidden="true" class="mt-2 flex justify-between gap-1.5 sm:gap-3">
            @foreach ($points as $point)
                <span class="min-w-0 flex-1 truncate text-center text-[11px] text-grey-500 sm:text-xs">
                    {{ $point['label'] }}
                </span>
            @endforeach
        </div>

        <table class="sr-only">
            <caption>{{ $ariaLabel }}</caption>
            <thead>
                <tr><th scope="col">Periode</th><th scope="col">Valeur</th></tr>
            </thead>
            <tbody>
                @foreach ($points as $point)
                    <tr>
                        <th scope="row">{{ $point['label'] }}</th>
                        <td>{{ $point['display'] ?? $point['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
