@props([
    // stat | card | table | chart | list | text
    'variant' => 'text',
    'rows' => 4,
])

{{--
    Squelette de chargement (regle 17).

    Un squelette plutot qu'un disque tournant, pour une raison mesurable : il
    occupe la place EXACTE du contenu attendu, donc rien ne saute quand celui-ci
    arrive. Un disque tournant centre, lui, est remplace par un tableau de
    quinze lignes et pousse tout ce qui suit — c'est du decalage cumulatif de
    mise en page, et cela se voit a chaque filtre applique.

    `aria-hidden` et `aria-busy` vont ensemble : la zone est annoncee comme en
    cours de chargement, mais les rectangles eux-memes ne sont pas decrits. Un
    lecteur d'ecran n'a rien a dire d'une forme grise.
--}}
<div {{ $attributes }} aria-busy="true" aria-hidden="true">
    @switch ($variant)
        @case ('stat')
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @for ($i = 0; $i < 4; $i++)
                    <div class="glass-stat space-y-3 p-5">
                        <div class="skeleton h-3 w-24"></div>
                        <div class="skeleton h-8 w-32"></div>
                        <div class="skeleton h-3 w-20"></div>
                    </div>
                @endfor
            </div>
            @break

        @case ('card')
            <div class="surface space-y-3 p-5">
                <div class="skeleton h-4 w-40"></div>
                <div class="skeleton h-3 w-full"></div>
                <div class="skeleton h-3 w-3/4"></div>
            </div>
            @break

        @case ('table')
            <div class="surface divide-y divide-grey-200 overflow-hidden">
                <div class="flex gap-4 bg-surface-muted px-4 py-3">
                    <div class="skeleton h-3 flex-1"></div>
                    <div class="skeleton hidden h-3 w-24 sm:block"></div>
                    <div class="skeleton hidden h-3 w-20 sm:block"></div>
                </div>
                @for ($i = 0; $i < $rows; $i++)
                    <div class="flex items-center gap-4 px-4 py-4">
                        <div class="skeleton h-4 flex-1"></div>
                        <div class="skeleton hidden h-4 w-24 sm:block"></div>
                        <div class="skeleton hidden h-4 w-20 sm:block"></div>
                    </div>
                @endfor
            </div>
            @break

        @case ('chart')
            <div class="surface p-5">
                <div class="skeleton mb-6 h-4 w-48"></div>
                {{-- Des hauteurs volontairement irregulieres : une rangee de
                     barres identiques se lit comme un graphique reel et vide,
                     pas comme une attente. --}}
                <div class="flex h-[180px] items-end gap-2 sm:gap-3">
                    @foreach ([45, 70, 30, 85, 55, 40, 65] as $height)
                        <div class="skeleton flex-1" style="height: {{ $height }}%"></div>
                    @endforeach
                </div>
            </div>
            @break

        @case ('list')
            <div class="space-y-4">
                @for ($i = 0; $i < $rows; $i++)
                    <div class="flex items-center gap-3">
                        <div class="skeleton size-11 shrink-0 rounded-full"></div>
                        <div class="flex-1 space-y-2">
                            <div class="skeleton h-3.5 w-2/3"></div>
                            <div class="skeleton h-3 w-1/3"></div>
                        </div>
                    </div>
                @endfor
            </div>
            @break

        @default
            <div class="space-y-2">
                @for ($i = 0; $i < $rows; $i++)
                    <div class="skeleton h-3" style="width: {{ [100, 92, 84, 70][$i % 4] }}%"></div>
                @endfor
            </div>
    @endswitch
</div>
