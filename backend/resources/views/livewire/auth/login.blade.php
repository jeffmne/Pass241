<div class="glass glass-panel glass-specular p-6 sm:p-8">
    <h1 class="font-display text-2xl font-extrabold text-ink">Connexion</h1>
    <p class="mt-1.5 text-sm text-grey-500">Accedez a votre espace organisateur.</p>

    <form wire:submit="login" class="mt-7 space-y-4">
        <div>
            <label for="email" class="mb-1.5 block text-sm font-medium text-ink">Adresse email</label>
            <input
                id="email"
                type="email"
                inputmode="email"
                autocomplete="email"
                autocapitalize="none"
                wire:model="email"
                class="field"
                aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                aria-describedby="{{ $errors->has('email') ? 'email-error' : '' }}"
                required>
            @error('email')
                <p id="email-error" class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                    <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        <div>
            <label for="password" class="mb-1.5 block text-sm font-medium text-ink">Mot de passe</label>
            <input
                id="password"
                type="password"
                autocomplete="current-password"
                wire:model="password"
                class="field"
                aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                required>
            @error('password')
                <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                    <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                    <span>{{ $message }}</span>
                </p>
            @enderror
        </div>

        <label class="flex min-h-11 items-center gap-2.5 text-sm text-grey-500">
            <input type="checkbox" wire:model="remember" class="size-4 rounded border-grey-200">
            Rester connecte
        </label>

        <button type="submit" class="btn btn-primary btn-pill w-full" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Se connecter</span>
            <span wire:loading wire:target="login" class="flex items-center gap-2">
                <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                Connexion
            </span>
        </button>
    </form>

    <p class="mt-7 text-center text-sm text-grey-500">
        Pas encore de compte ?
        <a href="{{ route('register') }}" wire:navigate class="font-semibold text-azure-600 hover:text-azure-700">
            Creer un compte
        </a>
    </p>
</div>
