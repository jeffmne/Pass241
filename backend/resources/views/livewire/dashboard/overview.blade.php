@php
    use App\Enums\KycStatus;

    $kyc = $organization->kyc_status;
@endphp

<div>
    <header class="mb-6">
        <h1 class="text-2xl font-bold text-ink sm:text-3xl">Bonjour {{ Str::before($user->name, ' ') }}</h1>
        <p class="mt-1 text-sm text-grey-500">{{ $organization->name }}</p>
    </header>

    {{-- Cartes empilees sur telephone, en grille a partir de sm --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-building-2 class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Organisation</span>
            </div>
            <p class="mt-2 truncate font-display text-lg font-bold text-ink">{{ $organization->name }}</p>
            <p class="mt-0.5 text-sm text-grey-500">{{ $organization->type->label() }}</p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-shield-check class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Verification</span>
            </div>
            <p @class([
                'mt-2 font-display text-lg font-bold',
                'text-success' => $kyc === KycStatus::Verified,
                'text-warning' => $kyc === KycStatus::Pending || $kyc === KycStatus::Submitted,
                'text-danger' => $kyc === KycStatus::Rejected,
            ])>
                {{ $kyc->label() }}
            </p>
            <p class="mt-0.5 text-sm text-grey-500">
                {{ $organization->canReceivePayouts()
                    ? 'Reversements autorises.'
                    : 'Ventes et dons possibles, reversement en attente.' }}
            </p>
        </div>

        <div class="surface p-4">
            <div class="flex items-center gap-2 text-grey-500">
                <x-lucide-users class="size-4" stroke-width="1.5" />
                <span class="text-xs font-semibold tracking-wide uppercase">Equipe</span>
            </div>
            <p class="mt-2 font-display text-lg font-bold text-ink tabular">
                {{ $memberCount }} {{ $memberCount > 1 ? 'membres' : 'membre' }}
            </p>
            <p class="mt-0.5 truncate text-sm text-grey-500">{{ implode(', ', $roles) }}</p>
        </div>
    </div>

    {{-- Etat vide : le produit dit ou il en est plutot que d'afficher des
         indicateurs vides qui ne renseignent sur rien. --}}
    <div class="surface mt-6 p-6 text-center sm:p-10">
        <x-lucide-calendar-plus class="mx-auto size-8 text-azure-300" stroke-width="1.5" />
        <h2 class="mt-3 font-display text-lg font-bold text-ink">Aucun evenement pour le moment</h2>
        <p class="mx-auto mt-1.5 max-w-md text-sm text-grey-500">
            La creation d'evenements arrive avec le prochain module. Votre compte,
            votre organisation et vos acces sont deja en place.
        </p>
    </div>
</div>
