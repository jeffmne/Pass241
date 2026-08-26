@php
    use App\Enums\KycStatus;
    use App\Support\Money;
@endphp

{{--
    Annuaire des organisations (section 58).

    Consultation seule. La suspension est prevue par la section 10, mais elle
    coupe les ventes en cours et bloque les reversements : elle merite son
    propre chemin, audite et confirme, pas un bouton dans une liste.
--}}
<div>
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink sm:text-3xl">Organisations</h1>
            <p class="mt-1 text-sm text-grey-500">
                {{ $organizations->total() }} organisation{{ $organizations->total() > 1 ? 's' : '' }}
                sur la plateforme.
            </p>
        </div>

        <div class="relative w-full sm:w-72">
            <x-lucide-search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-grey-400"
                             stroke-width="1.75" />
            <input type="search" wire:model.live.debounce.400ms="search" class="field pl-10"
                   placeholder="Nom, email de contact" aria-label="Rechercher une organisation">
        </div>
    </header>

    <div class="mb-4 flex flex-wrap gap-1 rounded-xl border border-white/60 bg-white/60 p-1 backdrop-blur">
        @foreach ([
            'all' => 'Toutes',
            'no-payout' => 'Sans numero de reversement',
            'suspended' => 'Suspendues',
        ] as $value => $label)
            <button type="button" wire:click="$set('filter', '{{ $value }}')" @class([
                'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                'bg-azure-600 text-white' => $filter === $value,
                'text-grey-500 hover:text-ink' => $filter !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($organizations as $index => $organization)
            {{-- Chaque carte se revele a son entree dans l'ecran, avec un
                 decalage en cascade : l'ordre de lecture porte ici une
                 information, les organisations etant classees par volume. --}}
            <div class="glass-stat glass-specular p-4 sm:p-5"
                 data-reveal data-reveal-step="{{ $index % 5 }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-display text-lg font-bold text-ink">{{ $organization->name }}</p>

                            @if ($organization->isSuspended())
                                <span class="rounded-full bg-danger/10 px-2.5 py-0.5 text-xs font-semibold text-danger">
                                    Suspendue
                                </span>
                            @elseif ($organization->kyc_status === KycStatus::Rejected)
                                <span class="rounded-full bg-danger/10 px-2.5 py-0.5 text-xs font-semibold text-danger">
                                    Compte refuse
                                </span>
                            @elseif (! $organization->hasPayoutDestination())
                                {{-- Le seul etat sur lequel il y a quelque chose
                                     a faire : sans numero, aucun reversement ne
                                     peut partir. --}}
                                <span class="rounded-full bg-warning/10 px-2.5 py-0.5 text-xs font-semibold text-warning">
                                    Sans numero de reversement
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 text-sm text-grey-500">
                            {{ $organization->type->label() }}
                            @if ($organization->contact_email)
                                · {{ $organization->contact_email }}
                            @endif
                            · {{ $organization->members_count }}
                            membre{{ $organization->members_count > 1 ? 's' : '' }}
                        </p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="font-display text-lg font-extrabold text-ink tabular">
                            {{ Money::format((int) $organization->gross) }}
                        </p>
                        <p class="mt-0.5 text-sm text-grey-500 tabular">
                            {{ number_format((int) $organization->tickets_sold, 0, ',', "\u{202F}") }}
                            billet{{ (int) $organization->tickets_sold > 1 ? 's' : '' }} vendu{{ (int) $organization->tickets_sold > 1 ? 's' : '' }}
                        </p>
                    </div>
                </div>
            </div>
        @empty
            <div class="surface p-10 text-center text-sm text-grey-500">
                Aucune organisation ne correspond.
            </div>
        @endforelse
    </div>

    @if ($organizations->hasPages())
        <div class="mt-6">{{ $organizations->links() }}</div>
    @endif

    <p class="mt-6 text-xs leading-relaxed text-grey-400">
        Les volumes portent sur les billets reellement vendus : un billet
        rembourse, annule ou expire n'est pas compte. La suspension d'une
        organisation ne se fait pas depuis cet ecran — elle coupe des ventes en
        cours et merite un chemin dedie.
    </p>
</div>
