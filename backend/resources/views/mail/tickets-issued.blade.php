{{--
    Copie du billet par email (section 69).

    Aucun emoji (regle 80.6). Le contenu tient sans images : beaucoup de
    clients de messagerie les bloquent par defaut, et un email de billet doit
    rester utile meme reduit a son texte.
--}}
<x-mail::message>
# Vos billets sont prets

Bonjour {{ $order->first_name }},

Votre paiement pour **{{ $event->name }}** est confirme.
{{ $tickets->count() }} billet{{ $tickets->count() > 1 ? 's ont' : ' a' }} ete emis a votre nom.

**Quand :** {{ $event->starts_at->timezone($tz)->translatedFormat('l j F Y \a\ H\hi') }}
@if ($event->location)
**Ou :** {{ $event->location->fullAddress() }}
@endif
**Reference de commande :** {{ $order->reference }}

@foreach ($tickets as $ticket)
<x-mail::button :url="$ticket->publicUrl()">
Voir mon billet {{ $ticket->orderItem?->ticket_type_name }}
</x-mail::button>
@endforeach

Presentez le QR Code de votre billet a l'entree. Le controle fonctionne sans reseau.

<x-mail::panel>
Cet email n'est qu'une copie. Vos billets restent accessibles a tout moment depuis
[Mes billets]({{ route('tickets.mine') }}), sur l'appareil utilise pour l'achat.
</x-mail::panel>

Merci,<br>
L'equipe {{ config('app.name') }}
</x-mail::message>
