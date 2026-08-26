{{--
    Recu de paiement (section 53 adaptee, decision D-029).

    Document comptable, pas un billet : ni affiche, ni QR, ni couleur
    superflue. Ce qui compte est qu'un montant se retrouve sur un relevé
    mobile money.

    Le vendeur est l'ORGANISATEUR. Pass241 encaisse pour son compte et n'est
    jamais depositaire des fonds (section 21.2) — le pied de page le dit
    explicitement, parce qu'un recu qui laisserait croire l'inverse dirait le
    contraire de ce que le produit est.

    La commission n'apparait nulle part : elle se joue entre la plateforme et
    l'organisateur, l'acheteur ne l'a pas payee.

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

        .doc-title {
            font-size: 13pt;
            font-weight: bold;
            margin: 0;
            text-align: right;
        }

        .doc-ref {
            font-size: 9pt;
            color: #6B7280;
            margin: 1mm 0 0;
            text-align: right;
        }

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

        /* --- Lignes --- */
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

        /* --- Totaux --- */
        .totals { width: 62mm; margin-left: auto; margin-top: 4mm; }
        .totals td { padding: 1.5mm 0; }
        .totals .num { text-align: right; }
        .totals .sub { color: #6B7280; font-size: 9pt; }

        .grand {
            border-top: 1px solid #10131A;
            font-size: 12pt;
            font-weight: bold;
        }

        .refund-line { color: #B3282C; }

        /* --- Paiement --- */
        .payment {
            background-color: #F3F5F8;
            border-radius: 8px;
            padding: 4mm 5mm;
            margin-top: 6mm;
        }

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

    $paidAt = $order->paid_at?->timezone($tz);
    $refundedTotal = $refunds->sum('amount');
    $netPaid = $order->amount - $refundedTotal;
@endphp

{{-- En-tete --}}
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="vertical-align: top;">
            <p class="brand">Pass241</p>
            <p class="brand-note">Billetterie en ligne</p>
        </td>
        <td style="vertical-align: top;">
            <p class="doc-title">Recu de paiement</p>
            <p class="doc-ref">
                {{ $reference }}<br>
                @if ($paidAt)
                    {{ $paidAt->translatedFormat('j F Y \a\ H\hi') }}
                @endif
            </p>
        </td>
    </tr>
</table>

<div class="rule"></div>

{{-- Vendeur et acheteur.
     Le vendeur est l'organisateur : Pass241 encaisse pour son compte
     (section 21.2). --}}
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td width="50%" style="vertical-align: top; padding-right: 8mm;">
            <p class="label">Vendeur</p>
            <p class="party-name">{{ $organization?->name }}</p>
            <p class="party-line">{{ $organization?->type?->label() }}</p>
            @if ($organization?->contact_email)
                <p class="party-line">{{ $organization->contact_email }}</p>
            @endif
        </td>

        <td style="vertical-align: top;">
            <p class="label">Acheteur</p>
            <p class="party-name">{{ $order->first_name }} {{ $order->last_name }}</p>
            @if ($order->email)
                <p class="party-line">{{ $order->email }}</p>
            @endif
        </td>
    </tr>
</table>

<div class="rule-light"></div>

<p class="label">Objet</p>
<p class="party-name">{{ $event?->name }}</p>
<p class="party-line">
    {{ $event?->starts_at?->timezone($tz)?->translatedFormat('l j F Y \a\ H\hi') }}
    @if ($event?->location)
        — {{ $event->location->name }}
    @endif
</p>
<p class="party-line">Commande {{ $order->reference }}</p>

{{-- Lignes --}}
<table class="items">
    <tr>
        <th width="52%">Designation</th>
        <th class="num" width="12%">Qte</th>
        <th class="num" width="18%">Prix unitaire</th>
        <th class="num" width="18%">Total</th>
    </tr>
    @foreach ($order->items as $item)
        <tr>
            <td>{{ $item->ticket_type_name }}</td>
            <td class="num tabular">{{ $item->quantity }}</td>
            <td class="num tabular">{{ Money::format($item->unit_price) }}</td>
            <td class="num tabular">{{ Money::format($item->subtotal) }}</td>
        </tr>
    @endforeach
</table>

{{-- Totaux.
     Les frais de paiement ont leur propre ligne : sans elle, l'acheteur ne
     peut pas reconcilier avec son relevé mobile money, et un ecart
     inexplique se lit comme une tromperie (decision D-023).

     La commission Pass241 n'apparait nulle part : elle se joue entre la
     plateforme et l'organisateur, l'acheteur ne l'a pas payee. --}}
<table class="totals" cellpadding="0" cellspacing="0">
    <tr>
        <td class="sub">Billets</td>
        <td class="num sub tabular">{{ Money::format($order->subtotal) }}</td>
    </tr>
    @if ($order->psp_fee_amount > 0)
        <tr>
            <td class="sub">Frais de paiement</td>
            <td class="num sub tabular">{{ Money::format($order->psp_fee_amount) }}</td>
        </tr>
    @endif
    <tr>
        <td class="grand">Total paye</td>
        <td class="num grand tabular">{{ Money::format($order->amount) }}</td>
    </tr>
</table>

@if ($refunds->isNotEmpty())
    {{-- Un recu qui continuerait a attester un paiement integral apres
         remboursement serait faux. --}}
    <div class="rule-light"></div>

    <p class="label">Remboursements</p>

    <table class="items">
        @foreach ($refunds as $refund)
            <tr>
                <td>
                    {{ $refund->reference }}
                    @if ($refund->origin?->label())
                        — {{ $refund->origin->label() }}
                    @endif
                </td>
                <td class="num tabular refund-line">
                    &minus;{{ Money::format($refund->amount) }}
                </td>
            </tr>
        @endforeach
    </table>

    <table class="totals" cellpadding="0" cellspacing="0">
        <tr>
            <td class="grand">Reste acquis</td>
            <td class="num grand tabular">{{ Money::format($netPaid) }}</td>
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
                    {{ $order->payment_provider?->label() }}
                    @if ($order->payer_phone)
                        — {{ str_repeat('*', max(0, mb_strlen($order->payer_phone) - 4)) }}{{ mb_substr($order->payer_phone, -4) }}
                    @endif
                </p>
            </td>
            <td style="vertical-align: top;">
                <p class="label">Statut</p>
                <p class="party-line">{{ $order->status->label() }}</p>
            </td>
        </tr>
    </table>
</div>

<div class="foot">
    Pass241 encaisse ce paiement pour le compte de {{ $organization?->name }} et
    reverse le montant net apres l'evenement. La plateforme n'est jamais
    depositaire definitive des fonds.
    <br>
    Ce recu est genere automatiquement et ne necessite pas de signature.
</div>

</body>
</html>
