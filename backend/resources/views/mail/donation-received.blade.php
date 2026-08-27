{{--
    Confirmation d'un don (sections 53, 69).

    Aucun emoji (regle 80.6). Le contenu tient sans images : beaucoup de
    clients de messagerie les bloquent par defaut.

    AUCUN MESSAGE DE DON n'est repris : il n'en existe pas (section 42).
--}}
<x-mail::message>
# Merci pour votre don

@if ($donation->donor_name)
Bonjour {{ $donation->donor_name }},
@else
Bonjour,
@endif

Votre don de **{{ $donation->formattedSubtotal() }}** est confirme.

@if ($campaign)
**Collecte :** {{ $campaign->name }}
@if ($campaign->beneficiary_label)
**Au profit de :** {{ $campaign->beneficiary_label }}
@endif
@endif
**Date :** {{ $donation->confirmed_at?->timezone($tz)->translatedFormat('j F Y \a\ H\hi') }}
**Reference :** {{ $donation->reference }}

@if ($donation->is_anonymous)
Votre don apparait publiquement sous « Donateur anonyme ». Seul son montant est
visible, comme pour tous les dons.
@endif

<x-mail::button :url="$donation->statusUrl()">
Voir mon don
</x-mail::button>

Votre recu est joint a cet email. Conservez ce message : le lien ci-dessus est
personnel et ne peut pas etre reconstitue.

@if ($campaign)
<x-mail::panel>
Vous pouvez suivre l'avancee de la collecte a tout moment sur
[sa page publique]({{ $campaign->publicUrl() }}). Le montant collecte et le
nombre de dons y sont publics et calcules automatiquement.
</x-mail::panel>
@endif

@if ($organization)
{{ $organization->name }}<br>
@endif
via {{ config('app.name') }}
</x-mail::message>
