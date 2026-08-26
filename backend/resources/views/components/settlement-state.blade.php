@props(['status'])

@php
    use App\Enums\PayoutState;
@endphp

{{--
    Pastille d'etat d'un reversement.

    Extraite parce que la console l'affiche a deux endroits — tableau sur grand
    ecran, carte sur telephone — et qu'une divergence de couleur entre les deux
    ferait douter de ce qu'on lit.

    ECHEC et INCONNU partagent le rouge, volontairement : dans les deux cas
    quelqu'un doit se pencher dessus. Donner a INCONNU une couleur rassurante
    laisserait passer un virement dont personne ne connait le sort.
--}}
<span @class([
    'inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
    'bg-success/10 text-success' => $status === PayoutState::Succeeded,
    'bg-azure-50 text-azure-700' => $status === PayoutState::Scheduled,
    'bg-warning/10 text-warning' => $status === PayoutState::Pending,
    'bg-danger/10 text-danger' => in_array($status, [PayoutState::Failed, PayoutState::Unknown], true),
])>{{ $status->label() }}</span>
