@php
    use App\Enums\KycStatus;
    use App\Support\Money;
@endphp

{{--
    Annuaire des organisations (section 58).

    Chaque carte ouvre la fiche de l'organisation. La suspension vit la-bas, et
    pas ici : elle coupe les ventes en cours et bloque les reversements, elle
    merite un ecran ou l'on voit ce qu'on ferme avant de le fermer.
--}}
<div class="space-y-5">

    <x-ui.page-header
        title="Organisations"
        :description="$organizations->total() . ' organisation' . ($organizations->total() > 1 ? 's' : '') . ' sur la plateforme.'"
        eyebrow="Administration Pass241">
        <x-slot:actions>
            <x-ui.search-input
                id="organizations-search"
                class="w-full sm:w-72"
                label="Rechercher une organisation"
                placeholder="Nom, email de contact"
                wire:model.live.debounce.400ms="search" />
        </x-slot:actions>
    </x-ui.page-header>

    <div class="scroll-x no-scrollbar -mx-1 px-1">
        <div class="seg" role="tablist" aria-label="Filtrer les organisations">
            @foreach ([
                'all' => 'Toutes',
                'no-payout' => 'Sans numero de reversement',
                'suspended' => 'Suspendues',
            ] as $value => $label)
                <button type="button"
                        role="tab"
                        wire:click="$set('filter', '{{ $value }}')"
                        aria-selected="{{ $filter === $value ? 'true' : 'false' }}"
                        class="seg-item">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div wire:loading.delay wire:target="search, filter, gotoPage, previousPage, nextPage">
        <x-ui.skeleton variant="list" :rows="5" class="surface p-4 sm:p-5" />
    </div>

    <div wire:loading.delay.class="opacity-40"
         wire:target="search, filter, gotoPage, previousPage, nextPage"
         class="space-y-3 transition-opacity">

        @forelse ($organizations as $index => $organization)
            {{-- Chaque carte se revele a son entree dans l'ecran, avec un
                 decalage en cascade : l'ordre de lecture porte ici une
                 information, les organisations etant classees par volume. --}}
            <a href="{{ route('admin.organization', $organization) }}" wire:navigate
               wire:key="org-{{ $organization->id }}"
               class="glass-card glass-specular card-lift block p-4 sm:p-5"
               data-reveal data-reveal-step="{{ $index % 5 }}">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-display text-lg font-bold text-ink">{{ $organization->name }}</p>

                            @if ($organization->isSuspended())
                                <x-ui.badge tone="danger">Suspendue</x-ui.badge>
                            @elseif ($organization->kyc_status === KycStatus::Rejected)
                                <x-ui.badge tone="danger">Compte refuse</x-ui.badge>
                            @elseif (! $organization->hasPayoutDestination())
                                {{-- Le seul etat sur lequel il y a quelque chose
                                     a faire : sans numero, aucun reversement ne
                                     peut partir. --}}
                                <x-ui.badge tone="warning">Sans numero de reversement</x-ui.badge>
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
                            {{ Money::number((int) $organization->tickets_sold) }}
                            billet{{ (int) $organization->tickets_sold > 1 ? 's' : '' }} vendu{{ (int) $organization->tickets_sold > 1 ? 's' : '' }}
                        </p>
                    </div>
                </div>
            </a>
        @empty
            <div class="surface">
                <x-ui.empty-state
                    icon="search-x"
                    tone="filtered"
                    title="Aucune organisation"
                    description="Aucune organisation ne correspond." />
            </div>
        @endforelse
    </div>

    @if ($organizations->hasPages())
        <div>{{ $organizations->links() }}</div>
    @endif

    <p class="text-xs leading-relaxed text-grey-400">
        Les volumes portent sur les billets reellement vendus : un billet
        rembourse, annule ou expire n'est pas compte. Ouvrez une organisation pour
        voir son detail, son activite, et suspendre ou reactiver son compte.
    </p>
</div>
