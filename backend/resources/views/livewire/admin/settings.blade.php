{{--
    Reglages de la plateforme (section 58).

    Le taux de commission se regle ici. Les SECRETS, non : cles du prestataire,
    identifiants S3, mot de passe SMTP restent en variables d'environnement, et
    aucune valeur secrete n'apparait sur cette page. La configuration technique
    dit QUI est branche, jamais AVEC QUOI.
--}}
<div>
    <header class="mb-6">
        <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Parametres</h1>
        <p class="mt-1 text-sm text-grey-500">
            Reglages de la plateforme, toutes organisations confondues.
        </p>
    </header>

    <section class="surface p-4 sm:p-6" data-reveal>
        <h2 class="font-display text-lg font-bold text-ink">Commission Pass241</h2>
        <p class="mt-1 text-sm leading-relaxed text-grey-500">
            Prelevee automatiquement sur chaque transaction confirmee, jamais saisie a la
            main (regles 80.1 et 80.2).
        </p>

        {{-- La premiere inquietude qu'on a en changeant un taux, dite avant le
             formulaire plutot qu'apres. --}}
        <div class="mt-4 flex items-start gap-2.5 rounded-xl border border-azure-200 bg-azure-50 px-4 py-3 text-sm">
            <x-lucide-info class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="1.75" />
            <span class="text-grey-500">
                Un changement ne vaut que pour l'avenir. Chaque transaction conserve le taux
                qui lui a ete applique : les commissions passees et les reversements calcules
                a partir d'elles ne bougent pas.
            </span>
        </div>

        <form wire:submit="save" class="mt-5">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="ticketingRate" class="mb-1.5 block text-sm font-medium text-ink">
                        Billetterie <span class="font-normal text-grey-400">(%)</span>
                    </label>
                    <input id="ticketingRate" type="text" inputmode="decimal"
                           wire:model="ticketingRate" class="field tabular">
                    @error('ticketingRate') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-xs text-grey-500">
                        Assise sur le prix des billets, jamais sur le total debite.
                    </p>
                </div>

                <div>
                    <label for="donationRate" class="mb-1.5 block text-sm font-medium text-ink">
                        Collectes <span class="font-normal text-grey-400">(%)</span>
                    </label>
                    <input id="donationRate" type="text" inputmode="decimal"
                           wire:model="donationRate" class="field tabular">
                    @error('donationRate') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                    <p class="mt-1.5 text-xs text-grey-500">
                        Assise sur le don, jamais sur les frais de paiement.
                    </p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-pill mt-5" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="save">Enregistrer</span>
                <span wire:loading wire:target="save">Enregistrement</span>
            </button>

            <p class="mt-3 text-xs text-grey-400">
                Le changement est journalise avec votre nom, l'ancienne et la nouvelle valeur.
            </p>
        </form>
    </section>

    <section class="surface mt-6 p-4 sm:p-6" data-reveal data-reveal-step="1">
        <h2 class="font-display text-lg font-bold text-ink">Configuration technique</h2>
        <p class="mt-1 text-sm text-grey-500">
            En lecture seule. Ces valeurs se changent par variables d'environnement.
        </p>

        <dl class="mt-4 divide-y divide-grey-200 text-sm">
            @foreach ($technical as $label => $value)
                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-grey-500">{{ $label }}</dt>
                    <dd class="text-right font-medium text-ink tabular">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Regle 16 de la demande : un secret ne s'affiche jamais. Le dire
             explicitement evite qu'on cherche ou ils sont passes. --}}
        <p class="mt-4 flex items-start gap-2 text-xs leading-relaxed text-grey-400">
            <x-lucide-shield class="mt-0.5 size-3.5 shrink-0" stroke-width="1.75" />
            <span>
                Les cles du prestataire de paiement, les identifiants de stockage et le mot de
                passe de messagerie ne sont ni affiches ici, ni stockes en base. Ils vivent en
                variables d'environnement, hors de portee d'une requete SQL et des sauvegardes.
            </span>
        </p>
    </section>
</div>
