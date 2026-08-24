<div class="glass p-6 sm:p-8">
    <h1 class="text-xl font-bold text-ink">
        {{ $hasOrganizations ? 'Nouvelle organisation' : 'Creez votre organisation' }}
    </h1>
    <p class="mt-1 text-sm text-grey-500">
        @if ($hasOrganizations)
            Vous pourrez basculer entre vos organisations a tout moment.
        @else
            Votre compte n'est rattache a aucune organisation. Creez-en une pour continuer.
        @endif
    </p>

    <form wire:submit="save" class="mt-6 space-y-4">
        <div>
            <label for="name" class="mb-1.5 block text-sm font-medium text-ink">Nom de l'organisation</label>
            <input id="name" type="text" wire:model="name" class="field"
                   placeholder="Association des jeunes de Libreville"
                   aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" required>
            @error('name') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="mb-1.5 block text-sm font-medium text-ink">Type d'organisation</legend>
            <div class="grid gap-2 sm:grid-cols-3">
                @foreach ($organizationTypes as $organizationType)
                    <label @class([
                        'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl border px-3 py-2.5 text-sm transition',
                        'border-azure-500 bg-azure-50 text-azure-700 font-medium' => $type === $organizationType->value,
                        'border-grey-200 bg-white text-grey-500' => $type !== $organizationType->value,
                    ])>
                        <input type="radio" wire:model.live="type" value="{{ $organizationType->value }}"
                               class="size-4 text-azure-500" name="type">
                        {{ $organizationType->label() }}
                    </label>
                @endforeach
            </div>
            @error('type') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
        </fieldset>

        <button type="submit" class="btn btn-primary w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="save">Creer l'organisation</span>
            <span wire:loading wire:target="save" class="flex items-center gap-2">
                <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                Creation
            </span>
        </button>
    </form>

    @if ($hasOrganizations)
        <p class="mt-6 text-center text-sm">
            <a href="{{ route('dashboard') }}" wire:navigate class="font-semibold text-azure-600">Retour au tableau de bord</a>
        </p>
    @endif
</div>
