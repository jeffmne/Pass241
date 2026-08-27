@props(['label', 'csv', 'xlsx', 'hint' => null])

{{--
    Deux formats, cote a cote (section 59).

    Un menu deroulant aurait cache le second format derriere un clic de plus,
    pour deux choix. Les liens sont des `<a>` et non des boutons : un export est
    un telechargement, il doit pouvoir s'ouvrir dans un nouvel onglet et se
    reprendre si la connexion lache.
--}}
<div class="flex flex-wrap items-center gap-2">
    <div class="min-w-0">
        <p class="text-sm font-medium text-ink">{{ $label }}</p>
        @if ($hint)
            <p class="text-xs text-grey-500">{{ $hint }}</p>
        @endif
    </div>

    <div class="ml-auto flex shrink-0 gap-2">
        <a href="{{ $csv }}" class="btn btn-ghost btn-pill text-sm">
            <x-lucide-download class="size-4" stroke-width="1.75" />
            CSV
        </a>
        <a href="{{ $xlsx }}" class="btn btn-ghost btn-pill text-sm">
            <x-lucide-download class="size-4" stroke-width="1.75" />
            Excel
        </a>
    </div>
</div>
