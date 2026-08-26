@props(['organization'])

@php
    use App\Enums\KycStatus;

    $rejected = $organization->kyc_status === KycStatus::Rejected;
    $blocker = $organization->payoutBlocker();
@endphp

{{--
    Ce qui manque avant de pouvoir etre paye (sections 10.1, 21.2).

    Depuis la decision D-025, plus aucune piece justificative n'est demandee :
    le compte mobile money de destination est deja verifie par Airtel ou Moov.
    Ce bandeau ne reclame donc qu'une chose — le numero de reversement — sauf
    si le Super Admin a explicitement refuse le compte.

    Il informe et incite, il n'interdit rien : la vente reste ouverte, et les
    recettes continuent d'etre comptabilisees. Le reversement n'intervenant
    qu'apres l'evenement, l'organisateur a tout le temps de renseigner son
    numero — d'ou le ton neutre et l'absence de blocage de l'interface.
--}}
@if ($blocker)
    <div @class([
            'mb-5 flex flex-col gap-3 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between',
            'border-danger/30 bg-danger/5' => $rejected,
            'border-warning/30 bg-warning/5' => ! $rejected,
        ])>
        <div class="flex gap-3">
            <x-dynamic-component
                :component="$rejected ? 'lucide-circle-alert' : 'lucide-wallet'"
                @class(['mt-0.5 size-5 shrink-0', 'text-danger' => $rejected, 'text-warning' => ! $rejected])
                stroke-width="1.5" />

            <div class="text-sm">
                <p class="font-semibold text-ink">
                    {{ $rejected ? 'Compte refuse' : 'Numero de reversement a renseigner' }}
                </p>
                <p class="mt-1 text-grey-500">
                    @if ($rejected)
                        {{ $organization->kyc_rejection_reason ?: 'Votre compte a ete refuse.' }}
                        Contactez le support.
                    @else
                        Vous pouvez publier vos evenements et vendre vos billets des maintenant.
                        Indiquez simplement le numero mobile money sur lequel recevoir vos
                        recettes, versees apres votre evenement.
                    @endif
                </p>
            </div>
        </div>

        @unless ($rejected)
            <a href="{{ route('organization.settings') }}" class="btn btn-primary shrink-0 sm:w-auto">
                Renseigner
            </a>
        @endunless
    </div>
@endif
