# Billetterie — Événements & parcours d'achat

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 11. MODULE BILLETTERIE — Création d'événements

L'organisateur peut renseigner : nom, description, image, affiche, date, heure, lieu, adresse, capacité maximale, catégorie, informations pratiques, visibilité.

---

## 12. STATUTS DES ÉVÉNEMENTS

`DRAFT`, `PUBLISHED`, `SOLD_OUT`, `ONGOING`, `COMPLETED`, `CANCELLED`, `ARCHIVED`

Seuls les événements `PUBLISHED` peuvent être achetés publiquement.

---

## 13. URL PUBLIQUE

Chaque événement publié possède une URL unique (ex: `/e/concert-afro-2026`). Le slug doit être lisible, stable, partageable, SEO-friendly. Après publication, éviter de modifier le slug.

---

## 14. SEO ET PARTAGE

Chaque page publique doit générer : title, description, OpenGraph, image, date, lieu. Les liens doivent être optimisés pour le partage sur WhatsApp, Facebook, Instagram, email, SMS.

---

## 15. PARCOURS D'ACHAT

Lien → Page événement → Choix billet → Nom → Prénom → Email (facultatif) → Choix du moyen de paiement → Numéro de téléphone (opérateur correspondant) → Paiement → Confirmation → Billet affiché immédiatement (section 15.1).

Le parcours doit être mobile-first, rapide, simple, rassurant, sans inscription inutile.

### 15.1 Génération et livraison du billet

**Principe : le billet est généré immédiatement après confirmation du paiement, et son accès ne dépend jamais uniquement de l'email — l'email n'est qu'un canal de récupération supplémentaire.**

```text
Paiement confirmé (webhook, section 22)
        │
        ▼
Billet généré immédiatement (statut ACTIVE, section 19)
        │
        ▼
Affiché directement au client (QR Code, nom, type de billet, événement, date)
        │
        ├── consultable/téléchargeable (PDF) depuis « Mes billets »
        ├── ajout ultérieur possible à Apple/Google Wallet (non prioritaire V1)
        └── envoyé par email en copie, uniquement si une adresse a été renseignée
```

Si le paiement échoue (`Order.status = FAILED`, section 20) : aucun billet n'est généré. Le client est informé de l'échec et peut retenter le paiement.

**Accès indépendant de l'email.** Le numéro de téléphone, déjà collecté pour le paiement (section 16.1), sert d'identifiant pour retrouver ses billets dans l'espace « Mes billets », même si aucune adresse email n'a été renseignée — utile si le client change de téléphone, perd son téléchargement, ou n'a simplement pas d'email. C'est un point d'adaptation au marché gabonais : un achat par mobile money ne doit jamais être bloqué par l'absence d'email.

**L'email ne doit jamais être le canal unique.** Le billet doit rester récupérable depuis la plateforme (espace « Mes billets »), pas uniquement via le QR code joint à un email. Cf. section 18 pour les règles de sécurité du QR Code (token signé, jamais un identifiant brut) — elles s'appliquent quel que soit le canal de livraison.

### 15.2 Mise en page du billet

Contenu et ordre, du haut vers le bas (écran « Mon billet » comme version PDF/imprimable) :

1. bandeau marque : logo Pass241 + accroche « Vivez vos événements autrement » ;
2. bannière de l'événement (affiche/image) avec le nom de l'événement en surimpression ;
3. nom de l'événement, catégories/tags (ex. « Musique • Culture • Lifestyle ») ;
4. date et heure (icône calendrier) ;
5. lieu (icône localisation) ;
6. badge type de billet (icône étoile pour VIP) + prix payé ;
7. informations d'accès en puces : **Porte**, et **Rang / Place** *uniquement si l'événement utilise un plan de salle avec places numérotées* — fonctionnalité non spécifiée à ce stade (section 17 ne couvre que des types de billets sans affectation de siège). Tant qu'elle n'existe pas, seule la puce Porte est affichée ;
8. QR Code (encart opaque, section 18) + nom du porteur + numéro de billet + date de génération ;
9. rappel « scannez ce QR code à l'entrée » ;
10. actions : **Télécharger le PDF** et **Partager mon billet**.

Statut du billet (`ACTIVE`/« Valide », section 19) affiché en badge au-dessus du billet, pas dessus — pour ne jamais gêner la lecture du QR Code.

Traitement visuel : voir section 70.5 (élément signature du design system) — même structure de contenu, en bleu azurin et liquid glass, pas en violet/surfaces pleines.

---

## 16. INFORMATIONS PARTICIPANT

Le formulaire demande : nom, prénom, adresse email (facultative — cf. section 15.1 ; le billet est livré et récupérable sans elle). Les données sont enregistrées telles que fournies, sans logique de validation métier complexe inutile. Elles restent accessibles uniquement aux utilisateurs autorisés.

### 16.1 Choix du moyen de paiement et numéro associé

Avant de finaliser sa commande, l'acheteur doit :

1. **choisir son moyen de paiement** parmi les providers actifs (Airtel Money, Moov Money) ;
2. **renseigner le numéro de téléphone correspondant à l'opérateur choisi**.

```
Choix billet
      ↓
Nom / Prénom / Email
      ↓
Choix du moyen de paiement
   ├── Airtel Money
   └── Moov Money
      ↓
Numéro de téléphone (associé à l'opérateur sélectionné)
      ↓
initializePayment() → push USSD vers ce numéro
```

Règles :

- Le champ numéro de téléphone doit être **validé selon le préfixe de l'opérateur sélectionné** (ex: préfixes Airtel vs préfixes Moov au Gabon) — validation côté serveur, pas seulement côté client.
- Si l'acheteur choisit Airtel Money mais saisit un numéro Moov (ou inversement), la commande doit être rejetée avant tentative de paiement, avec un message clair invitant à corriger le numéro ou changer de moyen de paiement.
- Le numéro de téléphone de paiement peut différer du numéro de contact du participant si un jour un champ contact est ajouté — mais pour le MVP, un seul numéro sert aux deux (paiement et identification du billet si besoin).
- Ce numéro est stocké comme donnée de paiement (privée), au même titre que l'email — cf. section 52 (séparation données privées/publiques).

---

## 17. TYPES DE BILLETS

Prévoir : Standard, VIP, Premium, Early Bird, personnalisé.

Chaque type possède : nom, description, prix, quantité, période de vente, limite par commande (champ configuré par l'organisateur au moment de créer le type), visibilité, statut.

Un billet peut être gratuit ou payant. Ne jamais dépasser la quantité disponible.

### 17.1 Réservation temporaire (hold)

Le hold ne s'applique que lorsqu'il ne reste **qu'un seul billet disponible** pour un type donné (pour ne pas bloquer inutilement du stock abondant).

```
Stock TicketType = 1 restant
        │
        ▼
Acheteur A entame le paiement
        │
        ▼
Hold posé (TTL 10 minutes) — billet retiré du stock affiché
        │
   ┌────┴────┐
   ▼         ▼
Paiement    Timeout / échec
confirmé    → hold relâché automatiquement, billet redevient disponible
   │
   ▼
Billet émis, stock définitivement décrémenté
```

Implémentation via TTL Redis plutôt qu'un verrou base de données bloquant.

---

## 18. QR CODE — TOKEN SIGNÉ

Chaque billet possède un QR Code unique. Ne jamais encoder uniquement un ID incrémental (mauvais : `ticketId=123`).

Le QR code encode un **token signé** représentant le ticket :

```
TICKET
 ├── ticket_id
 ├── event_id
 ├── order_id
 ├── ticket_type / seat
 ├── status
 └── signature
        │
        ▼
     QR CODE
```

- Payload signé côté serveur (HMAC-SHA256 minimum, ou signature asymétrique si vérification offline sans exposer le secret sur le device agent).
- Le QR encode payload + signature — jamais de données sensibles en clair (pas de nom, pas d'email).

Le serveur vérifie : authenticité, existence, événement, statut, utilisation, autorisation.

### 18.1 Flux de scan

```
Scanner (app mobile agent)
        │
        ▼
API Laravel (Sanctum)
        │
        ▼
Vérification signature
        │
        ▼
Vérification événement (le ticket appartient-il à l'événement sélectionné ?)
        │
        ▼
Vérification paiement (commande = PAID/CONFIRMED)
        │
        ▼
Ticket déjà utilisé (status) ?
        │
        ▼
VALID ✓ / INVALID ✗
```

---

## 19. STATUTS DES BILLETS

`ACTIVE`, `USED`, `CANCELLED`, `REFUNDED`, `EXPIRED`

Un billet utilisé ne peut jamais être utilisé une deuxième fois.

---

## 20. COMMANDES

Une commande peut contenir plusieurs billets. Elle contient : référence, participant, événement, montant, devise, moyen de paiement choisi (Airtel Money / Moov Money), numéro de téléphone payeur, statut paiement, billets, date.

Statuts : `PENDING`, `PAID`, `FAILED`, `CANCELLED`, `REFUNDED`, `PARTIALLY_REFUNDED`

---
