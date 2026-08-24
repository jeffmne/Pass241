{{--
    Mobile-first : une colonne, le formulaire seul.

    A partir de lg, l'espace disponible sert a deux choses plutot qu'a du vide :
    une colonne de reassurance a gauche, et des champs repartis sur deux
    colonnes a droite. Le formulaire d'inscription est long ; sur un grand
    ecran, l'etaler en hauteur dans une colonne de 448 px obligeait a faire
    defiler pour une tache qui tient largement dans un ecran.
--}}
<div class="grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-start lg:gap-12">

    {{-- Colonne de reassurance : masquee sous lg, ou elle repousserait le
         formulaire sous la ligne de flottaison sans rien apporter. --}}
    <aside class="hidden lg:block lg:pt-2">
        <h2 class="font-display text-3xl leading-tight font-extrabold text-ink">
            Votre billetterie<br>ouvre aujourd'hui
        </h2>
        <p class="mt-4 leading-relaxed text-grey-500">
            Aucun abonnement, aucun frais d'ouverture. Vous ne payez qu'une
            commission sur ce que vous vendez reellement.
        </p>

        <ul class="mt-8 space-y-4">
            @foreach ([
                ['rocket', 'Vendez des ce soir', 'Publiez votre evenement et partagez son lien immediatement.'],
                ['smartphone', 'Airtel Money et Moov Money', 'Vos participants paient depuis leur telephone, sans carte bancaire.'],
                ['scan-line', 'Controle hors connexion', 'Vos agents scannent a l\'entree meme quand le reseau tombe.'],
                ['shield-check', 'Verification plus tard', 'Elle n\'intervient qu\'au moment de recevoir vos recettes.'],
            ] as $item)
                <li class="flex gap-3.5">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-azure-100 text-azure-700">
                        <x-dynamic-component :component="'lucide-' . $item[0]" class="size-5" stroke-width="1.5" />
                    </span>
                    <span>
                        <span class="block text-sm font-semibold text-ink">{{ $item[1] }}</span>
                        <span class="mt-0.5 block text-sm leading-relaxed text-grey-500">{{ $item[2] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </aside>

    <div class="glass glass-panel glass-specular p-6 sm:p-8">
        <h1 class="font-display text-2xl leading-tight font-extrabold text-ink">
            Creer un compte organisateur
        </h1>
        <p class="mt-2 text-sm leading-relaxed text-grey-500 lg:hidden">
            Publiez vos evenements et vendez vos billets immediatement. La verification
            d'identite n'interviendra qu'au moment de recevoir vos recettes.
        </p>
        <p class="mt-2 hidden text-sm text-grey-500 lg:block">
            Quelques informations, et votre espace est pret.
        </p>

        <form wire:submit="register" class="mt-7 space-y-5">
            {{-- Vous --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="name" class="mb-1.5 block text-sm font-medium text-ink">Votre nom</label>
                    <input id="name" type="text" autocomplete="name" wire:model="name" class="field"
                           aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}" required>
                    @error('name')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div>
                    <label for="email" class="mb-1.5 block text-sm font-medium text-ink">Adresse email</label>
                    <input id="email" type="email" inputmode="email" autocomplete="email" autocapitalize="none"
                           wire:model="email" class="field"
                           aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}" required>
                    @error('email')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div>
                    <label for="phone" class="mb-1.5 block text-sm font-medium text-ink">Telephone</label>
                    <input id="phone" type="tel" inputmode="tel" autocomplete="tel" wire:model="phone" class="field"
                           placeholder="074 00 00 00"
                           aria-invalid="{{ $errors->has('phone') ? 'true' : 'false' }}" required>
                    @error('phone')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Votre organisation --}}
            <fieldset class="border-t border-white/70 pt-5">
                <legend class="sr-only">Votre organisation</legend>

                <div>
                    <label for="organizationName" class="mb-1.5 block text-sm font-medium text-ink">
                        Nom de votre organisation
                    </label>
                    <input id="organizationName" type="text" wire:model="organizationName" class="field"
                           placeholder="Association des jeunes de Libreville"
                           aria-invalid="{{ $errors->has('organizationName') ? 'true' : 'false' }}" required>
                    @error('organizationName')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                {{--
                    La selection est geree par Alpine en local, et la valeur
                    envoyee au serveur par un `wire:model` differe.

                    Un `wire:model.live` ferait un aller-retour reseau a chaque
                    clic, juste pour repeindre un bouton : sur une connexion
                    mobile, cela se voit tout de suite sous forme de latence sur
                    un geste qui devrait etre instantane.
                --}}
                <div class="mt-4" x-data="{ type: $wire.organizationType }">
                    <span class="mb-1.5 block text-sm font-medium text-ink">Type d'organisation</span>

                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ($organizationTypes as $type)
                            <label
                                class="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl border px-3 py-2.5 text-sm transition-colors"
                                :class="type === '{{ $type->value }}'
                                    ? 'border-azure-500 bg-azure-50 font-semibold text-azure-700'
                                    : 'border-grey-200 bg-white/80 text-grey-500 hover:border-azure-300'">
                                <input type="radio" name="organizationType" value="{{ $type->value }}"
                                       wire:model="organizationType"
                                       @change="type = '{{ $type->value }}'"
                                       class="size-4">
                                {{ $type->label() }}
                            </label>
                        @endforeach
                    </div>

                    @error('organizationType') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </fieldset>

            {{-- Votre mot de passe --}}
            <div class="grid gap-4 border-t border-white/70 pt-5 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-1.5 block text-sm font-medium text-ink">Mot de passe</label>
                    <input id="password" type="password" autocomplete="new-password" wire:model="password" class="field"
                           aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" required>
                    @error('password')
                        <p class="mt-1.5 flex items-start gap-1.5 text-sm text-danger">
                            <x-lucide-circle-alert class="mt-0.5 size-4 shrink-0" stroke-width="1.75" />
                            <span>{{ $message }}</span>
                        </p>
                    @enderror
                </div>

                <div>
                    <label for="passwordConfirmation" class="mb-1.5 block text-sm font-medium text-ink">
                        Confirmer
                    </label>
                    <input id="passwordConfirmation" type="password" autocomplete="new-password"
                           wire:model="passwordConfirmation" class="field" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-pill w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="register">Creer mon compte</span>
                <span wire:loading wire:target="register" class="flex items-center gap-2">
                    <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                    Creation
                </span>
            </button>
        </form>

        <p class="mt-7 text-center text-sm text-grey-500">
            Deja un compte ?
            <a href="{{ route('login') }}" wire:navigate class="font-semibold text-azure-600 hover:text-azure-700">
                Se connecter
            </a>
        </p>
    </div>
</div>
