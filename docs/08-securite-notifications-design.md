# Sécurité, autorisations, notifications & design

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 66. SÉCURITÉ

Priorités : authentification, autorisation, RBAC, isolation multi-tenant, protection IDOR, validation serveur, rate limiting, protection XSS, protection SQL injection, sessions sécurisées, secrets hors repository, audit logs.

Ne jamais stocker les mots de passe en clair.

---

## 67. AUTORISATIONS FINANCIÈRES

Aucune personne disposant simplement du rôle ORGANIZER (ou CO_ORGANIZER) ne doit pouvoir : modifier directement un paiement confirmé, modifier directement un don confirmé, modifier le montant collecté, modifier le pourcentage, supprimer un don, supprimer l'historique financier.

Les opérations sensibles doivent utiliser des workflows dédiés.

---

## 68. AUDIT LOGS

Journaliser notamment : `LOGIN`, `LOGOUT`, `EVENT_CREATED`, `EVENT_UPDATED`, `EVENT_PUBLISHED`, `EVENT_CANCELLED`, `TICKET_CREATED`, `TICKET_USED`, `TICKET_REFUNDED`, `PAYMENT_CREATED`, `PAYMENT_CONFIRMED`, `PAYMENT_FAILED`, `PAYMENT_REFUNDED`, `DON_CREATED`, `DON_CONFIRMED`, `DON_FAILED`, `DON_REFUNDED`, `AGENT_CREATED`, `AGENT_DISABLED`, `CAMPAIGN_CREATED`, `CAMPAIGN_UPDATED`, `CAMPAIGN_PUBLISHED`, `CAMPAIGN_PAUSED`, `CAMPAIGN_COMPLETED`, `EXPORT_GENERATED`.

---

## 69. NOTIFICATIONS

Prévoir une architecture pour : email, SMS, push — mais **le canal SMS n'est pas activé au lancement** ; le fournisseur SMS reste à définir. Prévoir l'abstraction dès le départ (interface `NotificationChannel`) pour ajouter le SMS sans refonte le jour où le fournisseur sera choisi.

**V1 : affichage immédiat dans l'application + email en copie facultative.** Le billet (avec QR code) est généré et affiché instantanément après confirmation du paiement, consultable depuis « Mes billets » (section 15.1). Il est en plus envoyé par email si une adresse a été renseignée au moment de l'achat — l'email n'est jamais le seul moyen de récupérer un billet.

Événements : billet acheté, paiement confirmé, paiement échoué, remboursement (demande reçue / acceptée / refusée), invitation, don confirmé, campagne terminée.

---

## 70. DESIGN UI/UX

Interface moderne, premium, élégante, fluide, responsive, mobile-first.

**Détail complet (palette, typographie, principes du liquid glass) : [docs/10-design-system.md](10-design-system.md).**

Les animations doivent améliorer l'expérience et non la ralentir.

---

## 71. PAGES PUBLIQUES

**Événement** : Affiche → Titre → Date/Heure/Lieu → Description → Billets → Formulaire → Paiement → Confirmation

**Collecte** : Image → Titre → Objectif → Montant collecté → Pourcentage → Progression → Description → Historique des dons → Faire un don → Paiement → Confirmation

---

## 72. PERFORMANCE

La plateforme doit pouvoir évoluer vers : milliers d'organisateurs, milliers d'événements, millions de billets, nombreux scans/paiements simultanés, nombreuses campagnes/dons.

Prévoir : pagination, index, cache, queues, traitement asynchrone, optimisation SQL, monitoring.

---

## 73. VARIABLES D'ENVIRONNEMENT

```
APP_URL=
APP_KEY=
DB_CONNECTION=pgsql
DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=

REDIS_HOST=
REDIS_PASSWORD=
REDIS_PORT=

QUEUE_CONNECTION=redis
HORIZON_PREFIX=

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=
REVERB_PORT=

SANCTUM_STATEFUL_DOMAINS=

AIRTEL_CLIENT_ID=
AIRTEL_CLIENT_SECRET=
AIRTEL_BASE_URL=

MOOV_CLIENT_ID=
MOOV_CLIENT_SECRET=
MOOV_BASE_URL=

TICKET_TOKEN_SIGNING_KEY=

EMAIL_API_KEY=
SMS_API_KEY=
```

Créer `.env.example`. Ne jamais committer les secrets.

---

