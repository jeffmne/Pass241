@php
    use App\Enums\KycStatus;

    $kyc = $organization->kyc_status;
@endphp

<div x-data="{ notice: '' }"
     @notify.window="notice = $event.detail.message; setTimeout(() => notice = '', 4000)">

    <header class="mb-6">
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">Parametres</h1>
        <p class="mt-1 text-sm text-grey-500">Informations de l'organisation et verification d'identite.</p>
    </header>

    <div x-show="notice" x-cloak
         class="mb-5 flex items-center gap-2.5 rounded-xl border border-success/30 bg-success/5 px-4 py-3 text-sm text-ink">
        <x-lucide-circle-check class="size-4 shrink-0 text-success" stroke-width="1.5" />
        <span x-text="notice"></span>
    </div>

    @unless ($canManage)
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-grey-200 bg-white px-4 py-3 text-sm text-grey-500">
            <x-lucide-lock class="mt-0.5 size-4 shrink-0" stroke-width="1.5" />
            <span>Consultation seule : votre role ne permet pas de modifier cette organisation.</span>
        </div>
    @endunless

    {{-- Surface pleine, pas de verre : ce sont des donnees administratives,
         la lisibilite prime sur l'effet (section 70.4). --}}
    <section class="surface p-4 sm:p-6">
        <h2 class="font-display text-lg font-bold text-ink">Informations</h2>

        <form wire:submit="updateProfile" class="mt-4 space-y-4">
            <div>
                <label for="orgName" class="mb-1.5 block text-sm font-medium text-ink">Nom</label>
                <input id="orgName" type="text" wire:model="name" class="field" @disabled(! $canManage)>
                @error('name') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="contactEmail" class="mb-1.5 block text-sm font-medium text-ink">Email de contact</label>
                    <input id="contactEmail" type="email" inputmode="email" autocapitalize="none"
                           wire:model="contactEmail" class="field" @disabled(! $canManage)>
                    @error('contactEmail') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="contactPhone" class="mb-1.5 block text-sm font-medium text-ink">Telephone de contact</label>
                    <input id="contactPhone" type="tel" inputmode="tel" wire:model="contactPhone" class="field"
                           @disabled(! $canManage)>
                    @error('contactPhone') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-grey-500">
                    Type : <span class="font-medium text-ink">{{ $organization->type->label() }}</span>
                </p>
                @if ($canManage)
                    <button type="submit" class="btn btn-primary w-full sm:w-auto" wire:loading.attr="disabled">
                        Enregistrer
                    </button>
                @endif
            </div>
        </form>
    </section>

    {{-- ----------------------------------------------------------------
         Reversement (section 21.2, decision D-025)

         Le numero sur lequel l'organisateur recoit le net de ses ventes.
         Aucune verification par code n'est demandee : le compte mobile money
         est deja verifie par l'operateur, qui reclame une piece d'identite a
         l'ouverture. Le nom du titulaire renvoye par le prestataire est
         affiche ensuite — c'est le seul garde-fou contre une faute de frappe,
         et un virement mobile money ne se rappelle pas.
    ----------------------------------------------------------------- --}}
    <section class="surface mt-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-display text-lg font-bold text-ink">Reversement</h2>
            @if ($organization->hasPayoutDestination())
                <span class="rounded-full bg-success/10 px-3 py-1 text-xs font-semibold text-success">
                    Numero enregistre
                </span>
            @else
                <span class="rounded-full bg-warning/10 px-3 py-1 text-xs font-semibold text-warning">
                    A renseigner
                </span>
            @endif
        </div>

        <p class="mt-2 text-sm leading-relaxed text-grey-500">
            Vos recettes sont versees sur ce numero apres votre evenement,
            deduction faite de la commission Pass241.
        </p>

        @if (session('payout-saved'))
            <p class="mt-4 flex items-start gap-2.5 rounded-xl border border-success/30 bg-success/5 px-3 py-2.5 text-sm text-ink">
                <x-lucide-circle-check class="mt-0.5 size-4 shrink-0 text-success" stroke-width="1.75" />
                <span>Numero de reversement enregistre.</span>
            </p>
        @endif

        @if ($organization->payout_name)
            {{-- Le nom vient de l'operateur, pas de nous : il permet a
                 l'organisateur de reconnaitre son propre compte avant que le
                 premier versement ne parte. --}}
            <p class="mt-4 flex items-start gap-2.5 rounded-xl bg-azure-50 px-3 py-2.5 text-sm text-ink">
                <x-lucide-user-check class="mt-0.5 size-4 shrink-0 text-azure-600" stroke-width="1.75" />
                <span>
                    Vous recevrez vos recettes sur le
                    <span class="font-semibold tabular">{{ $organization->payout_phone }}</span>,
                    au nom de <span class="font-semibold">{{ $organization->payout_name }}</span>.
                    Si ce n'est pas votre compte, corrigez le numero.
                </span>
            </p>
        @endif

        <form wire:submit="savePayoutDestination" class="mt-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="payoutOperator" class="mb-1.5 block text-sm font-medium text-ink">Operateur</label>
                    <select id="payoutOperator" wire:model="payoutOperator" class="field" @disabled(! $canManage)>
                        <option value="">Choisir</option>
                        @foreach ($operators as $operator)
                            <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                        @endforeach
                    </select>
                    @error('payoutOperator') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="payoutPhone" class="mb-1.5 block text-sm font-medium text-ink">Numero</label>
                    <input id="payoutPhone" type="tel" inputmode="numeric" autocomplete="off"
                           wire:model="payoutPhone" class="field" placeholder="074 00 00 00"
                           @disabled(! $canManage)>
                    @error('payoutPhone') <p class="mt-1.5 text-sm text-danger">{{ $message }}</p> @enderror
                </div>
            </div>

            @if ($canManage)
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs leading-relaxed text-grey-400">
                        Tout changement de ce numero est journalise et signale par email.
                    </p>
                    <button type="submit" class="btn btn-primary w-full shrink-0 sm:w-auto"
                            wire:loading.attr="disabled">
                        Enregistrer le numero
                    </button>
                </div>
            @endif
        </form>
    </section>

    @if ($kycDocumentsRequired)
    <section class="surface mt-5 p-4 sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-display text-lg font-bold text-ink">Verification d'identite</h2>
            <span @class([
                'rounded-full px-3 py-1 text-xs font-semibold',
                'bg-success/10 text-success' => $kyc === KycStatus::Verified,
                'bg-warning/10 text-warning' => $kyc === KycStatus::Pending || $kyc === KycStatus::Submitted,
                'bg-danger/10 text-danger' => $kyc === KycStatus::Rejected,
            ])>
                {{ $kyc->label() }}
            </span>
        </div>

        <p class="mt-2 text-sm text-grey-500">
            @if ($kyc === KycStatus::Verified)
                Votre identite est verifiee. Les reversements de vos recettes sont autorises.
            @else
                Vos ventes et vos dons sont comptabilises normalement des maintenant.
                La verification ne conditionne que le versement des fonds sur votre compte.
            @endif
        </p>

        @if ($kyc === KycStatus::Rejected && $organization->kyc_rejection_reason)
            <p class="mt-3 rounded-xl border border-danger/30 bg-danger/5 px-3 py-2 text-sm text-ink">
                <span class="font-semibold">Motif du refus :</span> {{ $organization->kyc_rejection_reason }}
            </p>
        @endif

        @if ($canSubmitKyc && $canManage)
            <form wire:submit="submitKyc" class="mt-5 space-y-4">
                {{-- La contrainte est annoncee avant les champs : la decouvrir
                     apres l'echec d'un televersement sur une connexion mobile
                     serait la pire facon de l'apprendre. --}}
                <p class="flex items-start gap-2 text-xs text-grey-500">
                    <x-lucide-info class="mt-0.5 size-4 shrink-0" stroke-width="1.5" />
                    <span>Formats acceptes : JPG, PNG ou PDF. Chaque piece doit peser 2 Mo au maximum.</span>
                </p>

                @foreach ($requiredDocuments as $document)
                    @php $existing = $uploadedDocuments[$document->value] ?? null; @endphp
                    <div>
                        <label for="doc-{{ $document->value }}" class="mb-1 block text-sm font-medium text-ink">
                            {{ $document->label() }}
                        </label>
                        <p class="mb-2 text-xs text-grey-500">{{ $document->hint() }}</p>

                        @if ($existing)
                            <p class="mb-2 flex items-center gap-2 text-sm text-grey-500">
                                <x-lucide-paperclip class="size-4 shrink-0" stroke-width="1.5" />
                                <span class="truncate">{{ $existing->original_name }}</span>
                            </p>
                        @endif

                        <input id="doc-{{ $document->value }}"
                               type="file"
                               accept=".jpg,.jpeg,.png,.pdf"
                               wire:model="documents.{{ $document->value }}"
                               class="field file:mr-3 file:rounded-lg file:border-0 file:bg-azure-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-azure-700">

                        <p wire:loading wire:target="documents.{{ $document->value }}"
                           class="mt-1.5 flex items-center gap-1.5 text-sm text-grey-500">
                            <x-lucide-loader-circle class="size-4 animate-spin" stroke-width="2" />
                            Televersement
                        </p>

                        @error('documents.'.$document->value)
                            <p class="mt-1.5 text-sm text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                @endforeach

                @error('documents') <p class="text-sm text-danger">{{ $message }}</p> @enderror

                <button type="submit" class="btn btn-primary w-full sm:w-auto" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="submitKyc">Transmettre mes pieces</span>
                    <span wire:loading wire:target="submitKyc">Transmission</span>
                </button>
            </form>
        @endif
    </section>
    @endif
</div>
