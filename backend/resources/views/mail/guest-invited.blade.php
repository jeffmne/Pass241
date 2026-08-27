@php
    $ticketTypeName = $invitation->order?->items->first()?->ticket_type_name;
@endphp

{{--
    Lien d'invitation (section 25).

    Aucun emoji (regle 80.6). Le message doit tenir sans images : l'essentiel
    est le lien, et beaucoup de clients de messagerie bloquent tout le reste.

    Trois choses, dans cet ordre : qui invite, a quoi, et jusqu'a quand la place
    reste retenue. La date limite n'est pas une formalite — c'est elle qui fait
    agir, et une place rendue a la vente sans prevenir se decouvre a la porte.
--}}
<x-mail::message>
# Une place vous est reservee

Bonjour {{ $invitation->guest_name }},

@if ($organization)
**{{ $organization->name }}** vous invite a **{{ $event->name }}**.
@else
Vous etes invite a **{{ $event->name }}**.
@endif

**Quand :** {{ $event->starts_at?->timezone($tz)->translatedFormat('l j F Y \a\ H\hi') }}
@if ($event->location)
**Ou :** {{ $event->location->fullAddress() }}
@endif
@if ($ticketTypeName)
**Votre place :** {{ $ticketTypeName }}
@endif

<x-mail::button :url="$invitation->publicUrl()">
Confirmer ma place
</x-mail::button>

@if ($invitation->expires_at)
Cette place vous est gardee jusqu'au
**{{ $invitation->expires_at->timezone($tz)->translatedFormat('j F \a\ H\hi') }}**.
Passe cette date, elle repart a la vente.
@endif

Votre billet vous sera remis des la confirmation, avec le QR Code a presenter
a l'entree.

<x-mail::panel>
Cette invitation est gratuite : elle ne vous engage a aucun paiement. Le lien
vous est personnel, ne le transmettez pas — une place ne peut etre prise qu'une
fois.
</x-mail::panel>

@if ($organization)
{{ $organization->name }}<br>
@endif
via {{ config('app.name') }}
</x-mail::message>
