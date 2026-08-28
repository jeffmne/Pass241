{{--
    Reglages de la plateforme (section 58).

    Le taux de commission se regle ici. Les SECRETS, non : cles du prestataire,
    identifiants S3, mot de passe SMTP restent en variables d'environnement, et
    aucune valeur secrete n'apparait sur cette page. La configuration technique
    dit QUI est branche, jamais AVEC QUOI.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Parametres"
        description="Reglages de la plateforme, toutes organisations confondues."
        eyebrow="Administration Pass241" />

    <x-ui.panel
        title="Commission Pass241"
        description="Prelevee automatiquement sur chaque transaction confirmee, jamais saisie a la main (regles 80.1 et 80.2)."
        data-reveal>

        {{-- La premiere inquietude qu'on a en changeant un taux, dite AVANT le
             formulaire plutot qu'apres. --}}
        <div class="mb-5 flex items-start gap-2.5 rounded-[var(--radius-sm)] border border-azure-300/50 bg-azure-50 px-4 py-3 text-sm">
            <x-lucide-info class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="2" aria-hidden="true" />
            <span class="text-grey-500">
                Un changement ne vaut que pour l'avenir. Chaque transaction conserve le taux
                qui lui a ete applique : les commissions passees et les reversements calcules
                a partir d'elles ne bougent pas.
            </span>
        </div>

        <form wire:submit="save">
            <div class="grid gap-x-5 sm:grid-cols-2">
                <x-ui.field label="Billetterie" for="ticketingRate" required
                            hint="Assise sur le prix des billets, jamais sur le total debite."
                            :error="$errors->first('ticketingRate')">
                    <div class="relative">
                        <input id="ticketingRate" type="text" inputmode="decimal"
                               wire:model="ticketingRate" class="field tabular pr-8"
                               @error('ticketingRate') aria-invalid="true" aria-describedby="ticketingRate-error" @enderror>
                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-grey-400">
                            %
                        </span>
                    </div>
                </x-ui.field>

                <x-ui.field label="Collectes" for="donationRate" required
                            hint="Assise sur le don, jamais sur les frais de paiement."
                            :error="$errors->first('donationRate')">
                    <div class="relative">
                        <input id="donationRate" type="text" inputmode="decimal"
                               wire:model="donationRate" class="field tabular pr-8"
                               @error('donationRate') aria-invalid="true" aria-describedby="donationRate-error" @enderror>
                        <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-semibold text-grey-400">
                            %
                        </span>
                    </div>
                </x-ui.field>
            </div>

            <button type="submit" class="btn btn-primary mt-2" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">Enregistrer</span>
                <span wire:loading wire:target="save">Enregistrement…</span>
            </button>
        </form>

        <x-slot:footer>
            Le changement est journalise avec votre nom, l'ancienne et la nouvelle valeur.
        </x-slot:footer>
    </x-ui.panel>

    <x-ui.panel
        title="Configuration technique"
        description="En lecture seule. Ces valeurs se changent par variables d'environnement."
        data-reveal data-reveal-step="1">

        <dl class="divide-y divide-grey-200 text-sm">
            @foreach ($technical as $label => $value)
                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-grey-500">{{ $label }}</dt>
                    <dd class="text-right font-semibold text-ink tabular">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Un secret ne s'affiche jamais. Le dire explicitement evite qu'on
             cherche ou ils sont passes. --}}
        <x-slot:footer>
            <span class="flex items-start gap-2">
                <x-lucide-shield class="mt-0.5 size-3.5 shrink-0" stroke-width="2" aria-hidden="true" />
                <span>
                    Les cles du prestataire de paiement, les identifiants de stockage et le mot de
                    passe de messagerie ne sont ni affiches ici, ni stockes en base. Ils vivent en
                    variables d'environnement, hors de portee d'une requete SQL et des sauvegardes.
                </span>
            </span>
        </x-slot:footer>
    </x-ui.panel>
</div>
