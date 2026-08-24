# Design system — Identité visuelle Pass241

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Détaille la section 70 (DESIGN UI/UX). Les règles absolues restent définies dans `CLAUDE.md` ; ce fichier ne les modifie pas.
>
> **Démo visuelle : [10-design-system-preview.html](10-design-system-preview.html)** (à ouvrir dans un navigateur — palette, typographie, billet en verre liquide, contre-exemple sur tableau dense).

---

## 70. DESIGN UI/UX

Interface moderne, premium, élégante, professionnelle, fluide, responsive, mobile-first. Deux piliers : une palette **bleu azurin + blanc cassé**, et un traitement de surface **liquid glass** utilisé avec discernement — jamais comme effet systématique.

---

### 70.1 Palette de couleurs

| Token | Hex | Usage |
|---|---|---|
| `--azure-50` | `#F2F8FE` | Fond de section très légèrement teinté, lavis derrière le verre |
| `--azure-100` | `#E4F1FD` | Badges, chips, fonds de survol discrets |
| `--azure-300` | `#7DB6EF` | États intermédiaires, icônes secondaires |
| `--azure-500` | `#2F8FE6` | **Couleur primaire** — CTA, liens, éléments actifs, accents de marque |
| `--azure-600` | `#1D6FC4` | Hover / état actif des boutons primaires |
| `--azure-700` | `#14549B` | Texte sur fond azuré clair (contraste AA) |
| `--offwhite` | `#F3F5F8` | **Fond de page** — blanc cassé tendant vers le gris, jamais blanc pur |
| `--white` | `#FFFFFF` | Cartes pleines, encarts QR Code, surfaces à contraste maximal |
| `--grey-200` | `#E1E5EB` | Bordures, séparateurs |
| `--grey-400` | `#9AA3B2` | Texte tertiaire, placeholders |
| `--grey-500` | `#6B7280` | Texte secondaire |
| `--ink` | `#10131A` | Texte primaire (noir légèrement bleuté, jamais `#000000` pur) |
| `--success` | `#1FAE68` | Paiement confirmé, don confirmé |
| `--warning` | `#F2A93B` | En attente (paiement, KYC) |
| `--danger` | `#E5484D` | Échec, remboursement, erreur |

Règles :

- Le fond de l'application est `--offwhite`, jamais blanc pur — c'est ce qui donne la profondeur nécessaire pour que les surfaces en verre liquide (plus claires, translucides) se détachent visuellement.
- `--azure-500` est la seule couleur de marque à usage large. Les autres teintes d'azur sont des variations d'état, pas des couleurs d'accent concurrentes.
- Les couleurs sémantiques (succès/attente/erreur) ne sont jamais remplacées par de l'azur, même sur les écrans très bleutés — l'utilisateur doit reconnaître un statut de paiement au premier coup d'œil.

---

### 70.2 Typographie

- **Titres, hero, nom d'événement, montants mis en avant** : `Manrope` (600–800). Caractère géométrique, premium, bonne présence en grande taille.
- **Interface, formulaires, tableaux, corps de texte, montants dans les tableaux** : `Inter` (400–500), chiffres tabulaires activés (`font-variant-numeric: tabular-nums`) — indispensable pour aligner des montants dans les dashboards et exports.

Ne jamais utiliser plus de deux familles. Le contraste vient des graisses et des tailles, pas d'une troisième police.

---

### 70.3 Liquid glass — principe

Traitement de surface translucide et réfractif : flou d'arrière-plan, léger dégradé de saturation, reflet spéculaire qui se déplace au survol, bordure lumineuse en dégradé (plus claire côté haut-gauche, comme un bord de verre qui capte la lumière).

**Jeton de référence (CSS) :**

```css
.glass {
  background: linear-gradient(135deg, rgba(255,255,255,0.65), rgba(255,255,255,0.32));
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border: 1px solid rgba(255,255,255,0.6);
  border-radius: 24px;
  box-shadow:
    0 8px 32px rgba(20, 84, 155, 0.12),
    inset 0 1px 0 rgba(255,255,255,0.5);
}
```

Le verre a besoin de quelque chose à réfracter : toujours poser les surfaces `.glass` sur un fond avec un peu de matière (dégradé azur doux, forme organique en arrière-plan) — jamais sur un aplat uni, sinon l'effet est invisible et coûte du rendu pour rien.

### 70.4 Liquid glass — où l'utiliser, où l'éviter

**Oui** : hero et pages publiques (mini-site événementiel, page de collecte), affichage du billet (« Mon billet », section 15.1), modales, barre de navigation, cartes de mise en avant (statistiques clés du dashboard).

**Non** : tableaux denses (participants, transactions, exports), formulaires de saisie de montants, tout écran où la lisibilité d'une donnée financière prime sur l'esthétique. Sur ces écrans : surfaces pleines `--white` avec bordure `--grey-200`, sans flou.

**Règle non négociable : le QR Code n'est jamais posé sur une surface en verre.** Il vit toujours dans un encart `--white` totalement opaque, contraste maximal — un flou ou une transparence résiduelle derrière le QR peut faire échouer le scan à l'entrée (section 18, 28).

Autres garde-fous :

- Texte sur verre : si le contraste avec l'arrière-plan variable tombe sous les seuils AA, ajouter un fond plein (scrim) derrière le texte plutôt que d'assombrir tout le panneau.
- Respecter `prefers-reduced-motion` : désactiver le reflet spéculaire animé pour les utilisateurs qui le demandent (cf. section 70, animations qui ne doivent jamais ralentir l'expérience).
- Un seul niveau de verre visible à la fois par écran (pas de carte en verre imbriquée dans une autre carte en verre) — au-delà, l'interface devient illisible et lente sur mobile bas de gamme, marché prioritaire de Pass241.

### 70.5 Élément signature

**Le billet en verre liquide.** Le composant `Mon billet` (contenu détaillé en section 15.2) est le point de repère visuel de Pass241 : panneau `.glass` avec reflet spéculaire au survol/tap, bordure dégradée évoquant un bord de verre, QR Code sur encart blanc opaque intégré. C'est un rappel du billet plastifié physique, traduit dans le vocabulaire du glassmorphism — un seul endroit où l'audace visuelle est maximale, pour que le reste de l'interface reste sobre et lisible.

Démo à jour avec cette mise en page : [10-design-system-preview.html](10-design-system-preview.html).

### 70.6 Icônes — aucun emoji

**Aucun emoji, nulle part dans le logiciel** : ni dans l'interface, ni dans les notifications (push, SMS), ni dans les emails, ni dans les exports, ni dans les messages d'erreur. Seules des **icônes de bibliothèque** sont utilisées.

Bibliothèque retenue : **Lucide**, sans exception sur les trois surfaces du produit — un seul et même jeu de traits garantit une vraie cohérence visuelle de bout en bout :

- **Web (Blade/Livewire)** : package `blade-lucide-icons`.
- **Super Admin (Filament)** : package d'intégration dédié `filament-lucide-icons`.
- **Application mobile agents (React Native/Expo)** : package officiel `lucide-react-native`.

Règles d'usage :

- Trait fin et constant (`stroke-width` 1.5–2), jamais d'icône remplie à outrance ni de style incohérent avec le reste du set.
- Taille selon le contexte : 16px en ligne dans du texte, 20–24px en navigation et boutons, 32px et plus pour les états vides ou illustratifs.
- Couleur alignée sur la palette (`--azure-500`, `--grey-500`, `--ink`, ou les couleurs sémantiques pour les statuts) — jamais la palette bariolée par défaut d'un jeu d'emojis.
- Une icône accompagne un texte de statut critique (paiement, erreur, confirmation), elle ne le remplace jamais seule.

---
