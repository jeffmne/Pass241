@php
    use App\Enums\RefundStatus;
@endphp

<x-mail::message>
# {{ $request->isApproved() ? 'Remboursement accepte' : 'Reponse a votre demande' }}

Bonjour,

@if ($request->isApproved())
Votre demande de remboursement **{{ $request->reference }}** pour
« {{ $event?->name }} » a ete acceptee.

**Montant rembourse :** {{ $request->formattedAmount() }}

@if ($request->psp_fee_amount > 0 && ! $request->includes_psp_fee)
{{-- Les frais restent chez l'operateur : le dire evite que le client
     croie a une erreur en comparant avec son releve (decision D-023). --}}
Les {{ $request->formattedPspFee() }} de frais de paiement ne sont pas
rembourses : ils ont ete verses a l'operateur mobile money au moment de
l'achat.
@endif

Le versement est en cours sur le numero qui a servi au paiement. Selon
l'operateur, il peut prendre quelques heures.

@elseif ($request->status === RefundStatus::Expired)
Votre demande de remboursement **{{ $request->reference }}** pour
« {{ $event?->name }} » n'a pas ete traitee dans les delais.

Les demandes se ferment automatiquement 48 heures avant l'evenement.

@else
Votre demande de remboursement **{{ $request->reference }}** pour
« {{ $event?->name }} » n'a pas ete acceptee par l'organisateur.

@if ($request->decision_reason)
**Motif :** {{ $request->decision_reason }}
@endif

Votre billet reste valide : vous pouvez toujours vous presenter a l'entree.
@endif

@if ($order)
<x-mail::button :url="$order->statusUrl()">
Voir ma commande
</x-mail::button>
@endif

Cet email concerne la commande {{ $order?->reference }}.

L'equipe Pass241
</x-mail::message>
