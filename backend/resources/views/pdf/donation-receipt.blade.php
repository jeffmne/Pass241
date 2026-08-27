{{--
    Recu de don (section 53).

    Document comptable : ni image, ni couleur superflue. Ce qui compte est
    qu'un montant se retrouve sur un relevé mobile money.

    Le beneficiaire est l'ORGANISATEUR. Pass241 encaisse pour son compte et
    n'est jamais depositaire des fonds (section 21.2) — le pied de page le dit
    explicitement.

    La commission n'apparait nulle part : elle se joue entre la plateforme et
    l'organisateur, le donateur ne l'a pas payee.

    AUCUN MESSAGE (section 42) : il n'existe aucun champ ou l'ecrire.

    L'anonymat ne s'applique pas ici : ce document est celui du donateur, remis
    a lui seul. Il porte sur l'affichage public (sections 40, 41).

    Mise en page en tableaux : le moteur de rendu PDF ne connait pas les mises
    en page modernes.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Recu {{ $reference }}</title>
    <style>
        @page { margin: 0; }

        body {
            margin: 0;
            padding: 18mm 16mm;
            font-family: DejaVu Sans, sans-serif;
            color: #10131A;
            font-size: 9.5pt;
            line-height: 1.45;
        }

        .brand { font-size: 15pt; font-weight: bold; color: #14549B; margin: 0; }
        .brand-note { font-size: 7.5pt; color: #8A94A3; margin: 1mm 0 0; }

        .doc-title { font-size: 13pt; font-weight: bold; margin: 0; text-align: right; }
        .doc-ref { font-size: 9pt; color: #6B7280; margin: 1mm 0 0; text-align: right; }

        .rule { border-top: 2px solid #14549B; margin: 6mm 0 5mm; }
        .rule-light { border-top: 1px solid #E1E5EB; margin: 5mm 0; }

        .label {
            font-size: 6.5pt;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #8A94A3;
            margin: 0 0 1.5mm;
        }

        .party-name { font-size: 10.5pt; font-weight: bold; margin: 0; }
        .party-line { font-size: 9pt; color: #4B5563; margin: 0.8mm 0 0; }

        .items { width: 100%; border-collapse: collapse; margin-top: 2mm; }

        .items th {
            font-size: 6.5pt;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: #8A94A3;
            text-align: left;
            padding: 0 0 2mm;
            border-bottom: 1px solid #E1E5EB;
        }

        .items td {
            padding: 2.5mm 0;
            border-bottom: 1px solid #F0F2F5;
            vertical-align: top;
        }

        .num { text-align: right; }
        .refund-line { color: #B4232A; }

        .totals { width: 62mm; margin-left: auto; margin-top: 4mm; }
        .totals td { padding: 1.5mm 0; }
        .totals .sub { color: #6B7280; }

        .totals .grand {
            font-size: 11pt;
            font-weight: bold;
            border-top: 1px solid #E1E5EB;
            padding-top: 2.5mm;
        }

        .payment { margin-top: 8mm; }

        .foot {
            margin-top: 10mm;
            padding-top: 4mm;
            border-top: 1px solid #E1E5EB;
            font-size: 7.5pt;
            color: #8A94A3;
            line-height: 1.5;
        }
    </style>
</head>
<body>

@php
    use App\Support\Money;

    $confirmedAt = $donation->confirmed_at?->timezone($tz);
    $refundedTotal = $refunds->sum('amount');
@endphp

{{-- En-tete --}}
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="vertical-align: top;">
            <p class="brand">Pass241</p>
            <p class="brand-note">Collecte de fonds</p>
        </td>
        <td style="vertical-align: top;">
            <p class="doc-title">Recu de don</p>
            <p class="doc-ref">
                {{ $reference }}<br>
                @if ($confirmedAt)
                    {{ $confirmedAt->translatedFormat('j F Y \a\ H\hi') }}
                @endif
            </p>
        </td>
    </tr>
</table>

<div class="rule"></div>

{{-- Beneficiaire et donateur.
     Le beneficiaire est l'organisateur : Pass241 encaisse pour son compte
     (section 21.2). --}}
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="50%" style="vertical-align: top; padding-right: 8mm;">
            <p class="label">Beneficiaire</p>
            <p class="party-name">{{ $organization?->name }}</p>
            <p class="party-line">{{ $organization?->type?->label() }}</p>
            @if ($campaign?->beneficiary_label)
                <p class="party-line">Au profit de {{ $campaign->beneficiary_label }}</p>
            @endif
            @if ($organization?->contact_email)
                <p class="party-line">{{ $organization->contact_email }}</p>
            @endif
        </td>

        <td style="vertical-align: top;">
            <p class="label">Donateur</p>
            <p class="party-name">{{ $donation->donor_name ?: 'Non renseigne' }}</p>
            @if ($donation->donor_email)
                <p class="party-line">{{ $donation->donor_email }}</p>
            @endif
            @if ($donation->is_anonymous)
                {{-- L'anonymat porte sur l'affichage public, pas sur ce
                     document : le dire evite au donateur de croire que son
                     choix n'a pas ete pris en compte. --}}
                <p class="party-line">Don anonyme publiquement</p>
            @endif
        </td>
    </tr>
</table>

<div class="rule-light"></div>

<p class="label">Objet</p>
<p class="party-name">{{ $campaign?->name }}</p>
<p class="party-line">Don {{ $donation->reference }}</p>

{{-- Lignes --}}
<table class="items">
    <tr>
        <th width="70%">Designation</th>
        <th class="num" width="30%">Montant</th>
    </tr>
    <tr>
        <td>Don a la collecte</td>
        <td class="num tabular">{{ Money::format($donation->subtotal) }}</td>
    </tr>
</table>

{{-- Totaux.
     Les frais de paiement ont leur propre ligne : sans elle, le donateur ne
     peut pas reconcilier avec son relevé mobile money (decision D-023).

     La commission Pass241 n'apparait nulle part : le donateur ne l'a pas
     payee. --}}
<table class="totals" cellpadding="0" cellspacing="0">
    <tr>
        <td class="sub">Don</td>
        <td class="num sub tabular">{{ Money::format($donation->subtotal) }}</td>
    </tr>
    @if ($donation->psp_fee_amount > 0)
        <tr>
            <td class="sub">Frais de paiement</td>
            <td class="num sub tabular">{{ Money::format($donation->psp_fee_amount) }}</td>
        </tr>
    @endif
    <tr>
        <td class="grand">Total paye</td>
        <td class="num grand tabular">{{ Money::format($donation->amount) }}</td>
    </tr>
</table>

@if ($refunds->isNotEmpty())
    {{-- Un recu qui continuerait a attester un don integral apres
         remboursement serait faux (section 47). --}}
    <div class="rule-light"></div>

    <p class="label">Remboursements</p>

    <table class="items">
        @foreach ($refunds as $refund)
            <tr>
                <td>{{ $refund->reference }} — {{ $refund->reason }}</td>
                <td class="num tabular refund-line">
                    &minus;{{ Money::format($refund->amount) }}
                </td>
            </tr>
        @endforeach
    </table>

    <table class="totals" cellpadding="0" cellspacing="0">
        <tr>
            <td class="grand">Reste acquis a la collecte</td>
            <td class="num grand tabular">{{ Money::format($donation->netAmount()) }}</td>
        </tr>
    </table>
@endif

{{-- Moyen de paiement --}}
<div class="payment">
    <table width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td width="50%" style="vertical-align: top;">
                <p class="label">Moyen de paiement</p>
                <p class="party-line">
                    {{ $donation->payment_provider?->label() }}
                    @if ($donation->payer_phone)
                        — {{ str_repeat('*', max(0, mb_strlen($donation->payer_phone) - 4)) }}{{ mb_substr($donation->payer_phone, -4) }}
                    @endif
                </p>
            </td>
            <td style="vertical-align: top;">
                <p class="label">Statut</p>
                <p class="party-line">{{ $donation->status->label() }}</p>
            </td>
        </tr>
    </table>
</div>

<div class="foot">
    Pass241 encaisse ce don pour le compte de {{ $organization?->name }} et reverse
    le montant net a la cloture de la collecte. La plateforme n'est jamais
    depositaire definitive des fonds.
    <br>
    Ce recu est genere automatiquement et ne necessite pas de signature.
</div>

</body>
</html>
