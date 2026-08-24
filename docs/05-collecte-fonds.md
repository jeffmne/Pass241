# Collecte de fonds

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 34. MODULE COLLECTE DE FONDS

La plateforme permet à : associations, ONG, particuliers, organisations, groupes, organisateurs d'événements, de créer des campagnes de collecte.

Une campagne contient : nom, description, image, objectif financier, date de début, date de fin, bénéficiaire, organisateur, slug, statut.

### 34.1 Bénéficiaire — évolutivité

Pour le MVP, le bénéficiaire reste un champ texte libre (pas d'entité séparée, pas de KYC). C'est presque toujours l'organisateur lui-même actuellement, avec une évolution prévue vers un bénéficiaire tiers désigné.

```
Campaign
├── organizerId (toujours requis)
├── beneficiaryLabel (texte libre, ex: "Famille Ndong" — MVP actuel)
└── beneficiaryId (nullable — réservé pour une future entité
                    Beneficiary avec vérification d'identité,
                    sans casser les campagnes existantes)
```

---

## 35. STATUTS DES CAMPAGNES

`DRAFT`, `PUBLISHED`, `PAUSED`, `COMPLETED`, `CANCELLED`, `EXPIRED`

---

## 36. PAGE PUBLIQUE DE COLLECTE

Une campagne publiée possède une page publique affichant : image, titre, description, objectif, montant collecté, pourcentage, nombre de dons, progression, date de fin, bénéficiaire, bouton "Faire un don", QR Code de campagne, historique public des dons.

---

## 37. TRANSPARENCE FINANCIÈRE

**PRINCIPE ABSOLU** : le montant collecté et le pourcentage de progression doivent être visibles publiquement, calculés automatiquement.

```
OBJECTIF        1 000 000 FCFA
COLLECTÉ          650 000 FCFA
PROGRESSION              65 %
█████████████░░░░░░░
```

---

## 38. AUCUNE MODIFICATION MANUELLE DU MONTANT COLLECTÉ

Le créateur de la campagne ne doit jamais pouvoir saisir manuellement `collectedAmount`, `percentage`, `numberOfDonations`.

Le système calcule : `collectedAmount = SUM(confirmed donations)`, puis `percentage = (collectedAmount / goalAmount) × 100`. Le frontend n'est jamais la source de vérité.

---

## 39. DONS CONFIRMÉS UNIQUEMENT

Seuls les dons réellement confirmés sont comptabilisés : `PENDING` → non comptabilisé, `FAILED` → non comptabilisé, `CANCELLED` → non comptabilisé, `CONFIRMED` → comptabilisé.

---

## 40. DON ANONYME OU NOMINATIF — VISIBILITÉ

Lorsqu'une personne effectue un don, elle choisit entre **don public** et **don anonyme**. Dans les deux cas, le **montant** du don est visible dans l'historique public — seule l'identité change.

```
Donation
├── Donateur
│   ├── Public   → nom affiché publiquement à côté du montant
│   └── Anonyme  → "Donateur anonyme" affiché, identité réelle conservée en base (privée)
│
└── Montant du don → toujours visible publiquement (identifié ou anonyme)

Total de la campagne (collectedAmount) → toujours public, calculé automatiquement
```

**Don public** — la page affiche : "Jean Dupont — 50 000 FCFA"
**Don anonyme** — la page affiche : "Donateur anonyme — 50 000 FCFA"

---

## 41. ANONYMAT

L'anonymat concerne uniquement l'affichage public. La plateforme peut conserver les informations nécessaires à : traitement du paiement, sécurité, audit, lutte contre la fraude, obligations légales éventuelles, rapprochement financier.

**L'organisateur ne doit pas pouvoir révéler l'identité d'un donateur ayant choisi l'anonymat.**

---

## 42. AUCUN MESSAGE DE DON

Le donateur ne peut ajouter : message, commentaire, témoignage, note, texte libre, réaction. Le modèle de données ne doit pas prévoir de champ message pour les dons.

---

## 43. HISTORIQUE PUBLIC DES DONS

La page publique affiche l'historique des dons, avec le montant systématiquement visible :

```
Derniers dons
Jean Dupont            50 000 FCFA
Donateur anonyme        25 000 FCFA
Marie N.                10 000 FCFA
Donateur anonyme         5 000 FCFA
```

La liste publique doit respecter l'anonymat choisi par le donateur.

---

## 44. LE CRÉATEUR NE PEUT PAS SUPPRIMER UN DON

Le créateur d'une collecte ne peut JAMAIS : supprimer un don, masquer un don, modifier son montant, modifier sa date, modifier son statut, modifier l'identité d'un donateur, révéler un don anonyme, modifier un don nominatif, supprimer un donateur de l'historique, augmenter/diminuer artificiellement le montant collecté.

---

## 45. LE CRÉATEUR NE PEUT PAS RETIRER UNE PERSONNE DE LA COLLECTE

Une personne ayant effectué un don confirmé fait partie de l'historique financier de la campagne. L'organisateur ne peut pas supprimer le donateur, supprimer son don, masquer son don, ni supprimer son entrée de l'historique — même si le donateur est anonyme publiquement, le don existe toujours dans le registre financier interne.

---

## 46. IMMUTABILITÉ DES DONNÉES FINANCIÈRES

Les données financières critiques doivent être considérées comme immuables. Un don confirmé ne doit pas être modifié directement. En cas d'erreur ou de remboursement, créer une nouvelle opération financière liée au don initial. Ne jamais modifier silencieusement l'ancien enregistrement.

---

## 47. REMBOURSEMENT D'UN DON — PARTIEL AUTORISÉ

Un don peut être remboursé **partiellement ou totalement**.

```
Donation confirmée (ex: 100 000 FCFA)
        │
        ▼
DonationRefund créé (ex: 30 000 FCFA), lié à la Donation initiale
        │
        ▼
Recalcul automatique de collectedAmount de la campagne
```

Exemple : Objectif 1 000 000 FCFA, Collecté 600 000 FCFA (60 %). Remboursement de 100 000 FCFA → nouveau total 500 000 FCFA (50 %).

- Un `DonationRefund` partiel n'annule pas la `Donation` (elle reste `CONFIRMED`) — opération liée, jamais d'update direct.
- `collectedAmount` = SUM(donations confirmées) − SUM(remboursements liés).
- Le montant affiché publiquement pour ce don individuel doit refléter le montant net après remboursement partiel.
- Le remboursement doit être autorisé, traçable, journalisé, associé au don initial.

---

## 48. AUCUNE TRICHE POSSIBLE SUR LES STATISTIQUES

L'organisateur ne peut pas modifier : montant total, pourcentage, nombre de dons, historique, montant moyen, évolution, montant d'un don. Toutes ces informations sont calculées côté serveur.

---

## 49. SOURCE DE VÉRITÉ FINANCIÈRE

La source de vérité est : **transactions financières confirmées** — et non le frontend, ni le dashboard organisateur. Le dashboard ne fait qu'afficher les données calculées par le backend. Ce principe s'applique aussi aux mises à jour temps réel (Reverb, cf. section 60.2) : une diffusion en direct n'est jamais qu'une retransmission d'une valeur déjà calculée et validée côté serveur.

---

## 50. PROTECTION CONTRE LES MODIFICATIONS FRONTEND

Une requête comme `{"collectedAmount": 5000000, "percentage": 100}` doit être rejetée ou ignorée. Le backend doit recalculer les valeurs.

---

## 51. AUDIT DES COLLECTES

Journaliser : `CAMPAIGN_CREATED`, `CAMPAIGN_UPDATED`, `CAMPAIGN_PUBLISHED`, `DON_CREATED`, `DON_CONFIRMED`, `DON_FAILED`, `DON_REFUNDED`, `CAMPAIGN_PAUSED`, `CAMPAIGN_COMPLETED`, `CAMPAIGN_CANCELLED`.

Chaque log contient : acteur, action, ressource, resourceId, timestamp, IP, metadata.

---

## 52. SÉPARATION DONNÉES PRIVÉES / PUBLIQUES

**Données privées** : identité réelle, email, référence transaction, informations de paiement, metadata, données nécessaires à l'audit.

**Données publiques** : montant du don, identité si le donateur l'autorise, "Donateur anonyme" si nécessaire, total collecté, objectif, pourcentage, nombre de dons.

---

## 53. REÇU DE DON

Après confirmation, générer un reçu : référence unique, campagne, montant, date, méthode de paiement, identité si applicable. Aucun message personnel ne doit être inclus.

---

## 54. QR CODE DE CAMPAGNE

Chaque campagne publiée peut disposer d'un QR Code redirigeant vers sa page publique.

```
Affiche → QR Code → Page collecte → Faire un don
```

---

## 55. STATISTIQUES DES COLLECTES

Afficher : objectif, montant collecté, pourcentage, nombre de dons, nombre de donateurs, montant moyen, évolution, derniers dons, historique. Toutes les données sont calculées automatiquement.

---

