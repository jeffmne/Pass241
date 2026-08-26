{{--
    Le billet imprimable (sections 15.2, 69, 70.5).

    Ce document ne se telecharge plus depuis la plateforme : le bouton de la
    page billet produit une image. Le PDF part par email, pour etre archive,
    transfere, et surtout imprime.

    Composition reprise du modele fourni (docs/design/Model-billet-pdf.jpg) :
    bandeau de marque sombre, mention « billet officiel », encoches laterales,
    banniere de l'evenement, date et lieu, carte de categorie, QR sur encart
    blanc, bloc de regles, pied de page.

    Trois ecarts assumes par rapport au modele :

    - la palette est le bleu azurin de la section 70.1, non le violet du
      modele : c'est l'identite du produit entier ;
    - les puces « Rang » et « Place » sont absentes. La section 15.2 ne les
      autorise que si l'evenement utilise un plan de salle numerote, ce que la
      section 17 ne prevoit pas. Les afficher indiquerait une place inexistante ;
    - aucun badge de magasin d'applications : l'application de la phase 5 est
      destinee aux agents, pas aux acheteurs.

    DEUX CONTRAINTES DE MISE EN PAGE, a ne pas defaire :

    1. Tout tient sur UNE page. Une seconde page sort de l'imprimante avec la
       moitie du billet dessus et personne ne sait laquelle presenter a
       l'entree. Les hauteurs ci-dessous sont budgetees pour cela : le contenu
       occupe environ 225mm sur les 297mm d'une A4, le reste etant la marge qui
       centre le billet sur la feuille. Ajouter un bloc impose d'en retirer un.

    2. Le QR est une IMAGE PNG, jamais un SVG inline. Le moteur de rendu
       n'applique pas les dimensions CSS a un SVG inline : le code sortait a sa
       taille intrinseque, debordait du cadre et disparaissait du document.

    Mise en page en tableaux et positionnement absolu, non en flexbox : le
    moteur de rendu PDF ne connait pas les mises en page modernes.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Billet {{ $ticket->reference }}</title>
    <style>
        @page { margin: 0; }

        /* Le billet mesure environ 242mm de haut : 26mm au-dessus le placent au
           milieu de la feuille, en laissant assez dessous pour qu'un titre
           d'evenement passant a la ligne ne fasse pas basculer le document sur
           une seconde page. */
        body {
            margin: 0;
            padding: 26mm 0 18mm;
            font-family: DejaVu Sans, sans-serif;
            color: #10131A;
            font-size: 9pt;
            line-height: 1.35;
            background-color: #EEF1F7;
        }

        /* 118mm : la largeur d'un vrai billet, pas celle d'une feuille. Un
           bloc plein cadre se lit comme une facture. */
        .ticket {
            width: 118mm;
            margin: 0 auto;
            background-color: #FFFFFF;
            border-radius: 16px;
        }

        /* --- Bandeau de marque --- */
        .head {
            background-color: #14549B;
            border-radius: 16px 16px 0 0;
            padding: 5mm 7mm 3.5mm;
            position: relative;
        }

        .brand-name { font-size: 13pt; font-weight: bold; color: #FFFFFF; }
        .brand-rule { width: 1px; background-color: #4A85C4; }
        .brand-tagline { font-size: 7pt; color: #CFE5FF; padding-left: 4mm; }

        .official {
            margin: 3mm 0 0;
            font-size: 6pt;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #99CBFF;
            text-align: center;
        }

        /* Pas d'encoches ici, contrairement au billet affiche : le moteur de
           rendu les rogne au rayon du bandeau, et sur du papier un demi-cercle
           peint de la couleur du fond ne se lit comme une dechirure que si la
           feuille est de cette couleur. La ligne de dechirure plus bas joue ce
           role, et elle, s'imprime. */

        .warning {
            background-color: #E5484D;
            color: #FFFFFF;
            padding: 2.5mm 7mm;
            font-weight: bold;
            font-size: 9pt;
            text-align: center;
        }

        /* --- Banniere --- */
        .banner-wrap { padding: 4mm 4mm 0; }

        /*
         * Aucune hauteur imposee au cadre ni a l'image : c'est l'image qui la
         * donne. Fixer les deux fait cohabiter deux contraintes que le moteur
         * de rendu concilie mal — l'affiche ressortait a 28mm dans un cadre de
         * 36mm, laissant une bande de couleur sous elle.
         *
         * L'image arrive deja recadree au rapport du cadre par
         * TicketPdf::downscale : sa largeur suffit donc a tout determiner.
         * `background-size: cover` n'existe pas ici, d'ou ce recadrage en
         * amont.
         */
        /*
         * Fond sombre et non azur : l'espace de ligne reserve sous l'image
         * laisse un lisere d'environ un millimetre que le moteur de rendu ne
         * permet pas de supprimer proprement — `line-height: 0` et
         * `font-size: 0` font disparaitre le titre avec lui. Cette teinte est
         * celle du bas du degrade grave dans l'image : le lisere s'y fond.
         */
        .banner {
            /* Hauteur explicite indispensable : le bloc de titre est
               positionne en absolu sur `bottom: 0`, et sans hauteur de
               reference le moteur de rendu le place hors du cadre, ou
               `overflow: hidden` le fait disparaitre.
               L'IMAGE, elle, n'est dimensionnee que par sa largeur : lui
               imposer aussi une hauteur la faisait ressortir a 28mm. */
            height: 36mm;
            border-radius: 9px;
            background-color: #151A22;
            position: relative;
            overflow: hidden;
        }

        .banner img {
            display: block;
        }

        /* Boite de positionnement du titre, sans fond : le voile sombre est
           grave dans l'image elle-meme (TicketPdf::burnScrim), parce que le
           moteur de rendu ne sait pas dessiner un degrade et qu'un rectangle
           semi-transparent coupe l'affiche d'une arete franche. */
        .banner-scrim {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 0 5mm 3.5mm;
        }

        .banner-categories {
            margin: 0 0 1mm;
            font-size: 6pt;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: #CFE5FF;
        }

        .banner-title {
            margin: 0;
            font-size: 14pt;
            font-weight: bold;
            color: #FFFFFF;
            line-height: 1.08;
        }

        /* --- Date et lieu --- */
        .facts { padding: 5mm 7mm 0; }

        .fact-label {
            font-size: 6pt;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #8A94A3;
            margin: 0;
        }

        .fact-title { font-size: 10.5pt; font-weight: bold; margin: 0.8mm 0 0; }
        .fact-sub { font-size: 8pt; color: #6B7280; margin: 0.5mm 0 0; }

        /* --- Categorie --- */
        .category-wrap { padding: 4mm 7mm 0; }

        .category-card {
            background-color: #F2F8FE;
            border: 1px solid #CFE5FF;
            border-radius: 10px;
            padding: 3.5mm 4mm;
        }

        .category-label {
            font-size: 6pt;
            letter-spacing: 1.4px;
            text-transform: uppercase;
            color: #6B7280;
            margin: 0;
        }

        .category-value {
            font-size: 14pt;
            font-weight: bold;
            color: #10131A;
            margin: 0.8mm 0 0;
            line-height: 1;
        }

        .category-price {
            font-size: 12pt;
            font-weight: bold;
            color: #1D6FC4;
            text-align: right;
        }

        /* --- Ligne de dechirure --- */
        .tear {
            border-top: 1px dashed #C9D2DE;
            margin: 5mm 7mm 0;
        }

        /* --- QR, reference, titulaire, statut --- */
        .stub { padding: 4.5mm 7mm 0; }

        .qr-cell { width: 42mm; vertical-align: top; }

        /* Encart blanc pur, aucune teinte : c'est la condition du scan
           (regle 70.4). */
        .qr-box {
            background-color: #FFFFFF;
            border: 1px solid #E1E5EB;
            border-radius: 10px;
            padding: 2.5mm;
            width: 36mm;
        }

        /* Dimensions portees par l'attribut ET par le style : c'est ce qui
           garantit que le code sort a la bonne taille. */
        .qr-box img { width: 31mm; height: 31mm; display: block; }

        .qr-hint {
            font-size: 6.5pt;
            color: #8A94A3;
            text-align: center;
            margin: 1.5mm 0 0;
            width: 41mm;
        }

        .info-cell { vertical-align: top; padding-left: 5mm; }

        .ref-label { font-size: 6pt; letter-spacing: 1.4px; text-transform: uppercase; color: #8A94A3; margin: 0; }
        .ref-value { font-size: 14pt; font-weight: bold; letter-spacing: 0.6px; margin: 0.8mm 0 0; }

        .holder-card {
            background-color: #F3F5F8;
            border-radius: 8px;
            padding: 2.5mm 3mm;
            margin-top: 3mm;
        }

        .holder-label { font-size: 6pt; letter-spacing: 1.2px; text-transform: uppercase; color: #6B7280; margin: 0; }
        .holder-value { font-size: 10pt; font-weight: bold; margin: 0.8mm 0 0; }

        .status-card { border-radius: 8px; padding: 2.5mm 3mm; margin-top: 3mm; }
        .status-valid { background-color: #E8F7EF; }
        .status-invalid { background-color: #FDECEC; }

        .status-title { font-size: 9.5pt; font-weight: bold; margin: 0; }
        .status-title-valid { color: #147A48; }
        .status-title-invalid { color: #B3282C; }
        .status-note { font-size: 7.5pt; color: #6B7280; margin: 0.5mm 0 0; }

        /* --- Regles --- */
        .rules-wrap { padding: 5mm 7mm 0; }

        .rules {
            background-color: #F3F5F8;
            border-radius: 10px;
            padding: 3.5mm 4mm;
        }

        .rules td { vertical-align: top; width: 50%; padding: 1.2mm 0; }
        .rule-strong { font-size: 8pt; font-weight: bold; display: block; }
        .rule-note { font-size: 7pt; color: #6B7280; }
        .rule-pad { padding-right: 4mm; }

        /* --- Pied --- */
        .foot {
            padding: 4.5mm 7mm 5mm;
        }

        .foot-brand { font-size: 11pt; font-weight: bold; color: #14549B; margin: 0; }
        .foot-right { text-align: right; vertical-align: top; }
        .foot-label { font-size: 7pt; color: #8A94A3; margin: 0; }
        .foot-site { font-size: 9pt; font-weight: bold; color: #1D6FC4; margin: 0.8mm 0 0; }
        .foot-ref { font-weight: bold; color: #10131A; }
    </style>
</head>
<body>

@php
    $valid = $ticket->status === App\Enums\TicketStatus::Active;
    $start = $event->starts_at->timezone($tz);
    $end = $event->ends_at?->timezone($tz);
    $location = $event->location;
@endphp

<div class="ticket">

    {{-- Bandeau de marque --}}
    <div class="head">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="42%" class="brand-name">Pass241</td>
                <td class="brand-rule">&nbsp;</td>
                <td class="brand-tagline">Vivez vos evenements autrement</td>
            </tr>
        </table>

        <p class="official">Billet officiel</p>

        <div class="notch notch-left"></div>
        <div class="notch notch-right"></div>
    </div>

    @unless ($valid)
        <div class="warning">
            Billet {{ mb_strtolower($ticket->status->label()) }} — ne permet pas l'entree.
        </div>
    @endunless

    {{-- Banniere de l'evenement --}}
    <div class="banner-wrap">
        <div class="banner">
            @if ($banner)
                {{-- Largeur seule : 416 pixels valent 110mm a 96 points par
                     pouce. La hauteur suit le rapport de l'image, deja recadre
                     a 1000 x 327 en amont. --}}
                <img src="{{ $banner }}" width="416" alt="">
            @endif

            <div class="banner-scrim">
                @if ($event->categoryLine())
                    <p class="banner-categories">{{ $event->categoryLine() }}</p>
                @endif
                <p class="banner-title">{{ $event->name }}</p>
            </div>
        </div>
    </div>

    {{-- Date puis lieu, chacun sur toute la largeur.
         En deux colonnes, un nom de salle un peu long — « Institut francais du
         Gabon » — passe a la ligne et casse l'alignement des deux blocs.
         Des libelles plutot que des icones : un pictogramme de 4mm imprime en
         niveaux de gris ne se lit plus. --}}
    <div class="facts">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="34%" style="vertical-align: top;">
                    <p class="fact-label">Date</p>
                </td>
                <td style="vertical-align: top;">
                    <p class="fact-title">{{ ucfirst($start->translatedFormat('l j F Y')) }}</p>
                    <p class="fact-sub">
                        {{ $start->format('H\hi') }}@if ($end) – {{ $end->format('H\hi') }}@endif
                    </p>
                </td>
            </tr>
            <tr>
                <td style="vertical-align: top; padding-top: 3.5mm;">
                    <p class="fact-label">Lieu</p>
                </td>
                <td style="vertical-align: top; padding-top: 3.5mm;">
                    @if ($location)
                        <p class="fact-title">{{ $location->name }}</p>
                        <p class="fact-sub">{{ trim(implode(', ', array_filter([$location->address, $location->city]))) }}</p>
                    @else
                        <p class="fact-title">A preciser</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- Categorie et prix paye --}}
    <div class="category-wrap">
        <div class="category-card">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="vertical-align: middle;">
                        <p class="category-label">Categorie</p>
                        <p class="category-value">{{ $item?->ticket_type_name }}</p>
                    </td>
                    <td class="category-price" style="vertical-align: middle;">
                        {{ $item?->formattedUnitPrice() }}
                    </td>
                </tr>
            </table>
        </div>
    </div>

    <div class="tear"></div>

    {{-- Talon : QR Code, numero, titulaire et statut --}}
    <div class="stub">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td class="qr-cell">
                    <div class="qr-box">
                        <img src="{{ $qr }}" width="117" height="117" alt="">
                    </div>
                    <p class="qr-hint">A presenter a l'entree</p>
                </td>

                <td class="info-cell">
                    <p class="ref-label">N° de billet</p>
                    <p class="ref-value">{{ $ticket->reference }}</p>

                    <div class="holder-card">
                        <p class="holder-label">Titulaire</p>
                        <p class="holder-value">{{ $ticket->holder_name }}</p>
                    </div>

                    <div class="status-card {{ $valid ? 'status-valid' : 'status-invalid' }}">
                        <p class="status-title {{ $valid ? 'status-title-valid' : 'status-title-invalid' }}">
                            {{ $valid ? 'Billet valide' : 'Billet '.mb_strtolower($ticket->status->label()) }}
                        </p>
                        <p class="status-note">
                            {{ $valid ? 'A presenter au controle d\'acces' : 'Ne permet pas l\'entree' }}
                        </p>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    {{-- Regles d'usage. Chacune correspond a une regle reelle du produit :
         un seul scan possible (section 19), billet nominatif (section 16),
         controle fonctionnant hors connexion (section 31). --}}
    <div class="rules-wrap">
        <div class="rules">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="rule-pad">
                        <span class="rule-strong">Un seul acces par billet</span>
                        <span class="rule-note">le premier scan le consomme</span>
                    </td>
                    <td>
                        <span class="rule-strong">Controle hors connexion</span>
                        <span class="rule-note">le scan fonctionne sans reseau</span>
                    </td>
                </tr>
                <tr>
                    <td class="rule-pad">
                        <span class="rule-strong">Billet nominatif</span>
                        <span class="rule-note">non transferable sans autorisation</span>
                    </td>
                    <td>
                        <span class="rule-strong">Ouverture des portes</span>
                        <span class="rule-note">{{ $start->format('H\hi') }}, presentez-vous en avance</span>
                    </td>
                </tr>
            </table>
        </div>
    </div>

    {{-- Pied de page --}}
    <div class="foot">
        <table width="100%" cellpadding="0" cellspacing="0">
            <tr>
                <td width="38%" style="vertical-align: top;">
                    <p class="foot-brand">Pass241</p>
                </td>
                <td class="foot-right" style="vertical-align: top;">
                    <p class="foot-label">Retrouvez vos billets sur</p>
                    <p class="foot-site">{{ $siteHost }}/mes-billets</p>
                    @if ($orderReference)
                        <p class="foot-label" style="margin-top: 1.2mm;">
                            Commande <span class="foot-ref">{{ $orderReference }}</span>
                        </p>
                    @endif
                </td>
            </tr>
        </table>
    </div>
</div>

</body>
</html>
