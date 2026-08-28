{{--
    Creation d'une organisation.

    Cet ecran vit sur le gabarit invite, sur la nappe ambiante et sans rail
    lateral : c'est le seul endroit du produit ou un utilisateur connecte n'a
    encore acces a rien. Un panneau en verre pose sur la nappe convient ici —
    il n'y a ni tableau ni donnee dense a lire (section 70.4).
--}}
<div class="glass glass-panel glass-specular p-6 sm:p-8">
    <h1 class="font-display text-xl font-extrabold tracking-tight text-ink">
        {{ $hasOrganizations ? 'Nouvelle organisation' : 'Creez votre organisation' }}
    </h1>
    <p class="mt-1 text-sm text-grey-500">
        @if ($hasOrganizations)
            Vous pourrez basculer entre vos organisations a tout moment.
        @else
            Votre compte n'est rattache a aucune organisation. Creez-en une pour continuer.
        @endif
    </p>

    <form wire:submit="save" class="mt-6 space-y-1">
        <x-ui.field label="Nom de l'organisation" for="name" required :error="$errors->first('name')">
            <input id="name" type="text" wire:model="name" class="field"
                   placeholder="Association des jeunes de Libreville"
                   @error('name') aria-invalid="true" aria-describedby="name-error" @enderror
                   required>
        </x-ui.field>

        {{-- Selection geree en local par Alpine : un `wire:model.live` ferait
             un aller-retour reseau a chaque clic, juste pour repeindre un
             bouton. --}}
        <fieldset x-data="{ selected: $wire.type }">
            <legend class="mb-1.5 text-sm font-semibold text-ink">Type d'organisation</legend>

            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($organizationTypes as $organizationType)
                    <label
                        class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[var(--radius-sm)] border px-3 py-2.5 text-sm transition-colors"
                        :class="selected === '{{ $organizationType->value }}'
                            ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                            : 'border-grey-200 bg-surface/80 text-grey-500 hover:border-azure-300'">
                        <input type="radio" name="type" value="{{ $organizationType->value }}"
                               wire:model="type"
                               @change="selected = '{{ $organizationType->value }}'"
                               class="size-4 border-grey-200 text-azure-600">
                        {{ $organizationType->label() }}
                    </label>
                @endforeach
            </div>

            <div class="mt-1.5 min-h-5">
                @error('type')
                    <p class="text-xs font-medium text-danger">{{ $message }}</p>
                @enderror
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary btn-pill mt-2 w-full"
                wire:loading.attr="disabled" wire:target="save">
            <span wire:loading.remove wire:target="save">Creer l'organisation</span>
            <span wire:loading wire:target="save" class="flex items-center gap-2">
                <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                Creation…
            </span>
        </button>
    </form>

    @if ($hasOrganizations)
        <p class="mt-6 text-center text-sm">
            <a href="{{ route('dashboard') }}" wire:navigate class="font-semibold text-azure-600 hover:text-azure-700">
                Retour au tableau de bord
            </a>
        </p>
    @endif
</div>
