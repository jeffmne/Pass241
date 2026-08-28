@php
    use App\Enums\KycStatus;

    $kyc = $organization->kyc_status;
@endphp

<div class="space-y-5"
     x-data="{ notice: '' }"
     @notify.window="notice = $event.detail.message; setTimeout(() => notice = '', 4000)">

    <x-ui.page-header
        title="Parametres"
        description="Informations de l'organisation et verification d'identite."
        :eyebrow="$organization->name" />

    <div x-show="notice" x-cloak role="status"
         class="flex items-center gap-2.5 rounded-[var(--radius-md)] border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
        <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
        <span x-text="notice"></span>
    </div>

    @unless ($canManage)
        <div class="flex items-start gap-2.5 rounded-[var(--radius-md)] border border-grey-200 bg-surface px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="2" aria-hidden="true" />
            <span>Consultation seule : votre role ne permet pas de modifier cette organisation.</span>
        </div>
    @endunless

    {{-- Surface pleine, pas de verre : ce sont des donnees administratives,
         la lisibilite prime sur l'effet (section 70.4). --}}
    <x-ui.panel title="Informations">
        <form wire:submit="updateProfile">
            <div class="space-y-1">
                <x-ui.field label="Nom" for="orgName" required :error="$errors->first('name')">
                    <input id="orgName" type="text" wire:model="name" class="field" @disabled(! $canManage)
                           @error('name') aria-invalid="true" aria-describedby="orgName-error" @enderror>
                </x-ui.field>

                <div class="grid gap-x-4 sm:grid-cols-2">
                    <x-ui.field label="Email de contact" for="contactEmail" :error="$errors->first('contactEmail')">
                        <input id="contactEmail" type="email" inputmode="email" autocapitalize="none"
                               wire:model="contactEmail" class="field" @disabled(! $canManage)
                               @error('contactEmail') aria-invalid="true" aria-describedby="contactEmail-error" @enderror>
                    </x-ui.field>

                    <x-ui.field label="Telephone de contact" for="contactPhone" :error="$errors->first('contactPhone')">
                        <input id="contactPhone" type="tel" inputmode="tel" wire:model="contactPhone" class="field"
                               @disabled(! $canManage)
                               @error('contactPhone') aria-invalid="true" aria-describedby="contactPhone-error" @enderror>
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-grey-500">
                    Type : <span class="font-semibold text-ink">{{ $organization->type->label() }}</span>
                </p>
                @if ($canManage)
                    <button type="submit" class="btn btn-primary w-full sm:w-auto"
                            wire:loading.attr="disabled" wire:target="updateProfile">
                        <span wire:loading.remove wire:target="updateProfile">Enregistrer</span>
                        <span wire:loading wire:target="updateProfile">Enregistrement…</span>
                    </button>
                @endif
            </div>
        </form>
    </x-ui.panel>

    {{-- ----------------------------------------------------------------
         Reversement (section 21.2, decision D-025)

         Le numero sur lequel l'organisateur recoit le net de ses ventes.
         Aucune verification par code n'est demandee : le compte mobile money
         est deja verifie par l'operateur, qui reclame une piece d'identite a
         l'ouverture. Le nom du titulaire renvoye par le prestataire est
         affiche ensuite — c'est le seul garde-fou contre une faute de frappe,
         et un virement mobile money ne se rappelle pas.
    ----------------------------------------------------------------- --}}
    <x-ui.panel
        title="Reversement"
        description="Vos recettes sont versees sur ce numero apres votre evenement, deduction faite de la commission Pass241.">

        <x-slot:actions>
            @if ($organization->hasPayoutDestination())
                <x-ui.badge tone="success">Numero enregistre</x-ui.badge>
            @else
                <x-ui.badge tone="warning">A renseigner</x-ui.badge>
            @endif
        </x-slot:actions>

        @if (session('payout-saved'))
            <p role="status"
               class="mb-4 flex items-start gap-2.5 rounded-[var(--radius-sm)] border border-success/30 bg-success/5 px-3 py-2.5 text-sm text-ink">
                <x-lucide-circle-check class="mt-0.5 size-4 shrink-0 text-success" stroke-width="2" aria-hidden="true" />
                <span>Numero de reversement enregistre.</span>
            </p>
        @endif

        @if ($organization->payout_name)
            {{-- Le nom vient de l'operateur, pas de nous : il permet a
                 l'organisateur de reconnaitre son propre compte avant que le
                 premier versement ne parte. --}}
            <p class="mb-4 flex items-start gap-2.5 rounded-[var(--radius-sm)] bg-azure-50 px-3 py-2.5 text-sm text-ink">
                <x-lucide-user-check class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="2" aria-hidden="true" />
                <span>
                    Vous recevrez vos recettes sur le
                    <span class="font-semibold tabular">{{ $organization->payout_phone }}</span>,
                    au nom de <span class="font-semibold">{{ $organization->payout_name }}</span>.
                    Si ce n'est pas votre compte, corrigez le numero.
                </span>
            </p>
        @endif

        <form wire:submit="savePayoutDestination">
            <div class="grid gap-x-4 sm:grid-cols-2">
                <x-ui.field label="Operateur" for="payoutOperator" required :error="$errors->first('payoutOperator')">
                    <select id="payoutOperator" wire:model="payoutOperator" class="field" @disabled(! $canManage)
                            @error('payoutOperator') aria-invalid="true" aria-describedby="payoutOperator-error" @enderror>
                        <option value="">Choisir</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                        @endforeach
                    </select>
                </x-ui.field>

                <x-ui.field label="Numero" for="payoutPhone" required :error="$errors->first('payoutPhone')">
                    <input id="payoutPhone" type="tel" inputmode="numeric" autocomplete="off"
                           wire:model="payoutPhone" class="field tabular" placeholder="074 00 00 00"
                           @disabled(! $canManage)
                           @error('payoutPhone') aria-invalid="true" aria-describedby="payoutPhone-error" @enderror>
                </x-ui.field>
            </div>

            @if ($canManage)
                <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-relaxed text-grey-400">
                        Tout changement de ce numero est journalise et signale par email.
                    </p>
                    <button type="submit" class="btn btn-primary w-full shrink-0 sm:w-auto"
                            wire:loading.attr="disabled" wire:target="savePayoutDestination">
                        Enregistrer le numero
                    </button>
                </div>
            @endif
        </form>
    </x-ui.panel>

    @if ($kycDocumentsRequired)
        <x-ui.panel
            title="Verification d'identite"
            :description="$kyc === KycStatus::Verified
                ? 'Votre identite est verifiee. Les reversements de vos recettes sont autorises.'
                : 'Vos ventes et vos dons sont comptabilises normalement des maintenant. La verification ne conditionne que le versement des fonds sur votre compte.'">

            <x-slot:actions>
                <x-ui.badge :tone="match ($kyc) {
                    KycStatus::Verified => 'success',
                    KycStatus::Rejected => 'danger',
                    default => 'warning',
                }">{{ $kyc->label() }}</x-ui.badge>
            </x-slot:actions>

            @if ($kyc === KycStatus::Rejected && $organization->kyc_rejection_reason)
                <p class="mb-4 rounded-[var(--radius-sm)] border border-danger/30 bg-danger/5 px-3 py-2 text-sm text-ink">
                    <span class="font-semibold">Motif du refus :</span> {{ $organization->kyc_rejection_reason }}
                </p>
            @endif

            @if ($canSubmitKyc && $canManage)
                <form wire:submit="submitKyc" class="space-y-4">
                    {{-- La contrainte est annoncee AVANT les champs : la
                         decouvrir apres l'echec d'un televersement sur une
                         connexion mobile serait la pire facon de l'apprendre. --}}
                    <p class="flex items-start gap-2 rounded-[var(--radius-sm)] bg-surface-muted px-3 py-2.5 text-xs text-grey-500">
                        <x-lucide-info class="mt-0.5 size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                        <span>Formats acceptes : JPG, PNG ou PDF. Chaque piece doit peser 2 Mo au maximum.</span>
                    </p>

                    @foreach ($requiredDocuments as $document)
                        @php $existing = $uploadedDocuments[$document->value] ?? null; @endphp

                        <x-ui.field
                            :label="$document->label()"
                            for="doc-{{ $document->value }}"
                            :hint="$document->hint()"
                            :error="$errors->first('documents.' . $document->value)">

                            @if ($existing)
                                <p class="mb-2 flex items-center gap-2 text-sm text-grey-500">
                                    <x-lucide-paperclip class="size-4 shrink-0" stroke-width="2" aria-hidden="true" />
                                    <span class="truncate">{{ $existing->original_name }}</span>
                                </p>
                            @endif

                            <input id="doc-{{ $document->value }}"
                                   type="file"
                                   accept=".jpg,.jpeg,.png,.pdf"
                                   wire:model="documents.{{ $document->value }}"
                                   class="field file:mr-3 file:rounded-[var(--radius-xs)] file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-azure-700"
                                   @error('documents.'.$document->value)
                                       aria-invalid="true" aria-describedby="doc-{{ $document->value }}-error"
                                   @enderror>

                            <p wire:loading wire:target="documents.{{ $document->value }}" role="status"
                               class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                                <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" aria-hidden="true" />
                                Televersement…
                            </p>
                        </x-ui.field>
                    @endforeach

                    @error('documents')
                        <p role="alert" class="text-sm font-medium text-danger">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn btn-primary w-full sm:w-auto"
                            wire:loading.attr="disabled" wire:target="submitKyc">
                        <span wire:loading.remove wire:target="submitKyc">Transmettre mes pieces</span>
                        <span wire:loading wire:target="submitKyc">Transmission…</span>
                    </button>
                </form>
            @endif
        </x-ui.panel>
    @endif
</div>
