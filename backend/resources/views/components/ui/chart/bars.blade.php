@props([
    // [['label' => 'Lun', 'value' => 12, 'display' => '12 billets'], ...]
    // `display` est le libelle lu a voix haute et affiche en infobulle. Quand
    // il manque, la valeur brute est utilisee.
    'series' => [],
    'height' => 180,
    // Indice de la barre a mettre en avant — le jour en cours, en general.
    'highlight' => null,
    'emptyLabel' => 'Aucune donnee sur cette periode',
    // Legende du tableau de repli, lue par les lecteurs d'ecran.
    'ariaLabel' => 'Donnees du graphique',
])

@php
    $values = array_map(static fn (array $point) => (float) ($point['value'] ?? 0), $series);
    $max = $values === [] ? 0 : max($values);
@endphp

{{--
    Histogramme. Aucune bibliotheque de graphiques.

    Sept a douze points ne justifient pas des dizaines de kilo-octets de
    JavaScript sur une connexion mobile gabonaise (section 72). Des div dont la
    hauteur est un pourcentage font exactement le meme travail, s'impriment,
    survivent a l'absence de script, et coutent zero octet de plus.

    Le graphique est double d'un TABLEAU masque visuellement. Un lecteur
    d'ecran n'a rien a faire d'une suite de rectangles : il lui faut les
    chiffres. C'est le meme tableau qui rend le graphique utilisable au clavier.

    Les hauteurs sont ecrites par le serveur, jamais calculees par le
    navigateur : la regle vaut pour toute donnee chiffree du produit
    (sections 49, 50).
--}}
<div {{ $attributes->merge(['class' => 'w-full']) }}>
    @if ($series === [] || $max <= 0)
        <div class="flex items-center justify-center rounded-[var(--radius-md)] border border-dashed border-grey-200 text-sm text-grey-400"
             style="height: {{ $height }}px">
            {{ $emptyLabel }}
        </div>
    @else
        <div aria-hidden="true"
             class="chart-grid flex items-end justify-between gap-1.5 border-b border-grey-200 sm:gap-3"
             style="height: {{ $height }}px">
            @foreach ($series as $index => $point)
                @php
                    $value = (float) ($point['value'] ?? 0);
                    $isEmpty = $value <= 0;
                    // Plancher a 3 % : une barre a 1 sur 400 tomberait sous le
                    // pixel et se confondrait avec un jour vide.
                    $ratio = $isEmpty ? 0 : max(3, round($value / $max * 100));
                    $isHighlighted = $highlight !== null && $index === $highlight;
                @endphp
                <div class="group flex min-w-0 flex-1 flex-col justify-end self-stretch"
                     title="{{ $point['display'] ?? $point['label'] . ' : ' . $value }}">
                    @if ($isEmpty)
                        {{--
                            Un jour SANS vente garde un socle de trois pixels.

                            Sans lui, la colonne disparait entierement et le
                            graphique se lit comme une barre isolee flottant
                            dans un cadre vide — on ne sait plus si les autres
                            jours valent zero ou n'existent pas. Le socle dit
                            « ce jour a eu lieu, il n'a rien rapporte », ce qui
                            n'est pas la meme information.
                        --}}
                        <div class="h-[3px] w-full rounded-full bg-grey-200"></div>
                    @else
                        <div @class([
                                'chart-bar w-full',
                                'chart-bar-muted' => $highlight !== null && ! $isHighlighted,
                            ])
                             style="height: {{ $ratio }}%"></div>
                    @endif
                </div>
            @endforeach
        </div>

        <div aria-hidden="true" class="mt-2 flex justify-between gap-1.5 sm:gap-3">
            @foreach ($series as $index => $point)
                <span @class([
                    'min-w-0 flex-1 truncate text-center text-[11px] sm:text-xs',
                    'font-bold text-azure-700' => $highlight !== null && $index === $highlight,
                    'text-grey-500' => $highlight === null || $index !== $highlight,
                ])>{{ $point['label'] }}</span>
            @endforeach
        </div>

        {{-- Le meme jeu de donnees, en chiffres, pour qui n'y voit rien. --}}
        <table class="sr-only">
            <caption>{{ $ariaLabel }}</caption>
            <thead>
                <tr><th scope="col">Periode</th><th scope="col">Valeur</th></tr>
            </thead>
            <tbody>
                @foreach ($series as $point)
                    <tr>
                        <th scope="row">{{ $point['label'] }}</th>
                        <td>{{ $point['display'] ?? $point['value'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
