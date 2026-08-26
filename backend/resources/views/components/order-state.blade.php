@props(['status'])

@php
    use App\Enums\OrderStatus;
@endphp

{{--
    Pastille d'etat d'une commande.

    Extraite parce que la console l'affiche a deux endroits — tableau sur grand
    ecran, carte sur telephone — et qu'une divergence de couleur entre les deux
    ferait douter de ce qu'on lit.
--}}
<span @class([
    'inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap',
    'bg-success/10 text-success' => $status === OrderStatus::Paid,
    'bg-warning/10 text-warning' => $status === OrderStatus::Pending,
    // Un remboursement n'est pas un echec : c'est une operation normale, et
    // le teinter en rouge ferait chercher un incident la ou il n'y en a pas.
    'bg-azure-50 text-azure-700' => in_array($status, [OrderStatus::Refunded, OrderStatus::PartiallyRefunded], true),
    'bg-grey-200/80 text-grey-500' => in_array($status, [OrderStatus::Failed, OrderStatus::Cancelled], true),
])>{{ $status->label() }}</span>
