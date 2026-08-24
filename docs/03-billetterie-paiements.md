# Billetterie — Paiements, remboursements & promotions

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 21. PAIEMENTS

Moyens initiaux : Airtel Money, Moov Money. L'architecture doit permettre d'ajouter d'autres providers.

Abstraction :

```
PaymentProviderInterface
├── AirtelMoneyProvider
└── MoovMoneyProvider
```

Fonctions : `initializePayment()`, `checkPaymentStatus()`, `processCallback()`, `refund()`

### 21.1 Flux USSD et timeout

```
initializePayment()
        │
        ▼
Push USSD envoyé au numéro du client → Order = PENDING
        │
        ▼
Client valide via code USSD sur son téléphone
        │
        ▼
Provider envoie webhook → processCallback() → Order = PAID / FAILED
```

- **Timeout : 5 minutes.** Passé ce délai sans callback, l'`Order` passe automatiquement à `FAILED` (job planifié).
- `checkPaymentStatus()` appelé en polling de secours (ex: toutes les 30 secondes pendant la fenêtre des 5 minutes), car les webhooks mobile money peuvent être en retard ou manquants selon l'opérateur.
- Le hold de stock (section 17.1) suit le même TTL que le timeout de paiement.
- Le provider (`AirtelMoneyProvider` ou `MoovMoneyProvider`) est déterminé par le choix explicite de l'acheteur au moment du formulaire (section 16.1) — jamais déduit automatiquement du numéro seul. Le numéro renseigné doit néanmoins être cohérent avec le provider choisi (validation serveur).

### 21.2 Commission et reversement

La plateforme n'est **jamais dépositaire définitive des fonds** : elle encaisse, retient sa commission, et reverse le net à l'organisateur.

**Taux :** 5 % sur la billetterie, 2,5 % sur les collectes.

Flux :

```
Paiement (client / donateur)
        │
        ▼
Confirmation (webhook / callback)
        │
        ▼
Calcul automatique de la commission (5 % ou 2,5 %)
        │
        ▼
Enregistrement (entité Commission, snapshot du taux)
        │
        ▼
Reversement automatique à l'organisateur
(montant net = montant brut − commission)
```

**Architecture technique recommandée : compte marchand / agrégateur plateforme, avec split payment ou logique de marketplace.**

- Le compte marchand Airtel/Moov qui encaisse est celui de la **plateforme** (agrégateur), pas directement celui de chaque organisateur.
- La plateforme retient sa commission au moment de l'encaissement (ou immédiatement après confirmation), puis déclenche un **reversement automatique** du montant net vers le compte de l'organisateur (mobile money ou virement, selon ce que permettent les providers).
- Si Airtel Money / Moov Money proposent un vrai split payment natif (encaissement direct partagé entre plateforme et organisateur), c'est préférable — sinon, la plateforme encaisse l'intégralité puis effectue un reversement différé via une API de transfert/paiement sortant.
- Cette architecture nécessite une **abstraction de règlement** (`CommissionSettlementStrategy`, à implémenter aux côtés de `CommissionCalculationService`, cf. section 61) car le mécanisme exact dépend des capacités réelles des deux providers, à valider techniquement avant l'implémentation finale.
- Le reversement automatique est lui-même une transaction traçable, journalisée, liée à la commande/don d'origine (immutabilité, section 46).

---

## 22. WEBHOOKS

Les webhooks doivent être sécurisés, vérifiés, idempotents, journalisés, supporter les répétitions, gérer les erreurs.

Le frontend ne doit jamais décider seul qu'un paiement est confirmé.

---

## 23. IDEMPOTENCE

Les paiements doivent être idempotents. Si le même webhook est reçu plusieurs fois, seul le premier déclenche une confirmation ; les suivants sont ignorés. Ne jamais créer plusieurs billets pour un même paiement confirmé.

---

## 24. REMBOURSEMENTS (billets)

Prévoir : remboursement total, remboursement partiel si nécessaire, raison, statut, date, transaction associée.

Un billet remboursé devient inutilisable. Toutes les opérations doivent être journalisées.

### 24.1 Modèle hybride — le client demande, l'organisateur décide

```
Client
   │
   ▼
Demande de remboursement (self-service, depuis son espace/billet)
   │
   ▼
Vérification automatique : demande faite ≥ 48h avant l'événement ?
   │
   ├── Non → demande refusée automatiquement (hors délai)
   │
   └── Oui → demande transmise à l'organisateur
                 │
                 ▼
         Organisateur : Accepter / Refuser (avec raison)
                 │
                 ▼
         Si accepté → remboursement traité (section 24), billet → REFUNDED
```

Règles :

- **Le client ne déclenche pas lui-même le remboursement** — il soumet une demande. C'est toujours l'organisateur (ou un CO_ORGANIZER autorisé, cf. section 10) qui valide ou refuse.
- **Délai : 48h avant l'événement.** Passé ce délai, la demande est automatiquement bloquée côté serveur (le bouton reste visible mais explique pourquoi la demande n'est plus possible), sans attendre l'organisateur.
- Chaque demande garde un statut propre (`REQUESTED`, `APPROVED`, `REJECTED`, `EXPIRED`) distinct du statut du billet, pour permettre l'audit du processus de décision.
- Toute décision (approbation ou refus) est journalisée avec l'acteur, la raison, et l'horodatage.
- La commission déjà prélevée (section 21.2) est recalculée au prorata lors d'un remboursement, comme pour les dons (section 47).

---

## 25. INVITATIONS

Permettre : invitations individuelles, invitations multiples, QR Code, suivi.

Statuts : `CREATED`, `SENT`, `ACCEPTED`, `USED`, `EXPIRED`, `CANCELLED`

---

## 26. CODES PROMO

Permettre : réduction en pourcentage, montant fixe, date de début, expiration, nombre maximum, limite par utilisateur, billets concernés, activation/désactivation.

La validation doit toujours être faite côté serveur.

---

