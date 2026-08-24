@props(['organization'])

@php
    use App\Enums\KycStatus;

    $rejected = $organization->kyc_status === KycStatus::Rejected;
@endphp

{{--
    Section 10.1 : la verification d'identite ne bloque ni la publication ni la
    vente. Ce bandeau informe et incite, il n'interdit rien — d'ou le ton neutre
    et l'absence de blocage de l'interface.
--}}
<div @class([
        'mb-5 flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between',
        'border-danger/30 bg-danger/5' => $rejected,
        'border-warning/30 bg-warning/5' => ! $rejected,
    ])>
    <div class="flex gap-3">
        <x-dynamic-component
            :component="$rejected ? 'lucide-circle-alert' : 'lucide-shield-check'"
            @class(['mt-0.5 size-5 shrink-0', 'text-danger' => $rejected, 'text-warning' => ! $rejected])
            stroke-width="1.5" />

        <div class="text-sm">
            <p class="font-semibold text-ink">
                {{ $rejected ? 'Verification d\'identite refusee' : 'Verification d\'identite a completer' }}
            </p>
            <p class="mt-1 text-grey-500">
                @if ($rejected)
                    {{ $organization->kyc_rejection_reason ?: 'Les documents fournis n\'ont pas pu etre valides.' }}
                    Vous pouvez soumettre de nouveaux documents.
                @else
                    Vous pouvez publier vos evenements et vendre vos billets des maintenant.
                    Le versement de vos recettes sera possible une fois votre identite verifiee.
                @endif
            </p>
        </div>
    </div>

    <a href="{{ route('organization.settings') }}" class="btn btn-primary shrink-0 sm:w-auto">
        Completer
    </a>
</div>
