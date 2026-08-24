# Architecture technique

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 60. ARCHITECTURE TECHNIQUE

> Écarts entre cette section et l'installation réelle (versions imposées par des incompatibilités de dépendances) : voir [00-decisions.md](00-decisions.md), décisions D-005 et D-006.

### Backend
- Laravel 13
- PHP 8.4
- PostgreSQL
- Redis (dev/local) / Upstash Redis (production serverless-friendly)
- Laravel Horizon (jobs, queues)
- Laravel Sanctum (auth API — dashboard web + app mobile agents)
- Laravel Reverb (temps réel — WebSockets)
- Spatie Laravel Permission (rôles, permissions, scoping multi-tenant)

### Frontend web (pages publiques + dashboard organisateur)
- Blade
- Livewire 4 — Livewire 3 ne supporte pas Laravel 13 (décision D-005)
- Alpine.js — embarqué par Livewire 4, pas installé séparément
- Tailwind CSS 4

### Admin
- Filament — réservé au dashboard Super Admin (voir 60.3)

### Application de contrôle d'accès
- Découplée du moteur Livewire (voir 60.4), consomme l'API Sanctum

### Base de données
- PostgreSQL + Eloquent ORM

### 60.1 Pages publiques

Les pages publiques (événement, collecte) doivent être SEO-friendly (section 14). Le rendu serveur Blade/Livewire évite les problèmes d'hydration et d'indexation propres à un frontend découplé en SPA.

### 60.2 Temps réel avec Reverb

Cas d'usage prioritaires :
- Progression de collecte en direct sur la page publique (section 37)
- Derniers dons affichés en direct
- Statistiques de scan en direct côté dashboard organisateur pendant un événement

Chaque mise à jour poussée via Reverb reste une simple retransmission d'une valeur déjà calculée et validée côté serveur (cf. section 49).

### 60.3 Filament — périmètre strict

Filament est réservé au Super Admin (section 58) : gestion des organisations, utilisateurs, supervision globale, logs, statistiques plateforme.

Le dashboard organisateur (section 56) est en Livewire + Tailwind sur-mesure, car la section 70 impose une UI premium (glassmorphism, gradients, micro-interactions) que Filament, orienté admin utilitaire, n'est pas conçu pour produire nativement.

### 60.4 Application de contrôle d'accès — hors Livewire

Le mode hors connexion (section 31, détaillé en section 27) est un contrat non négociable : scan, queue locale, synchronisation différée. Livewire fonctionne par aller-retours serveur à chaque interaction et ne peut pas fonctionner offline.

**Choix retenu : React Native + Expo** (cf. section 27.1 pour le détail complet de la stack mobile — SQLite, SecureStore, NetInfo, Expo Camera).

L'app agent est un **client API pur** : elle ne partage aucun moteur de rendu avec Blade/Livewire. Le contrat d'API (endpoints scan, sync, auth) doit être stable et versionné indépendamment du reste du backend, exposé via `Http/Controllers/Api/` (section 61).

---

## 61. STRUCTURE BACKEND

```
app/
├── Models/
│   ├── User.php
│   ├── Organization.php
│   ├── OrganizationMember.php
│   ├── EventDelegate.php
│   ├── Event.php
│   ├── EventLocation.php
│   ├── TicketType.php
│   ├── Ticket.php
│   ├── Order.php
│   ├── OrderItem.php
│   ├── PaymentRefund.php
│   ├── Invitation.php
│   ├── PromoCode.php
│   ├── PromoCodeUsage.php
│   ├── AccessPoint.php
│   ├── AccessAgent.php
│   ├── AccessScan.php
│   ├── Campaign.php
│   ├── Donation.php
│   ├── DonationRefund.php
│   ├── Commission.php
│   ├── Notification.php
│   └── AuditLog.php
│
├── Http/
│   ├── Controllers/
│   │   ├── Api/            (endpoints consommés par l'app agent — Sanctum)
│   │   └── Web/            (contrôleurs classiques si besoin hors Livewire)
│   ├── Livewire/
│   │   ├── Public/         (pages événement, collecte — SEO)
│   │   ├── Dashboard/      (organisateur — Événements, Billetterie, Collectes, Finances...)
│   │   └── Components/     (composants réutilisables : QR display, progress bar, etc.)
│   ├── Middleware/
│   │   ├── EnsureOrganizationContext.php   (isolation multi-tenant)
│   │   └── ...
│   └── Resources/          (transformation API pour l'app agent)
│
├── Services/
│   ├── Payments/
│   │   ├── PaymentProviderInterface.php
│   │   ├── AirtelMoneyProvider.php
│   │   └── MoovMoneyProvider.php
│   ├── Ticketing/
│   │   ├── TicketTokenService.php   (génération + vérification signature QR)
│   │   └── StockHoldService.php     (holds Redis, TTL)
│   ├── Donations/
│   │   ├── DonationCalculationService.php  (collectedAmount, percentage)
│   │   └── DonationRefundService.php
│   ├── Commission/
│   │   └── CommissionCalculationService.php
│   └── AccessControl/
│       └── ScanConflictResolver.php  (résolution double-scan offline)
│
├── Jobs/
│   ├── ExpireOrderIfUnpaid.php       (timeout 5 min)
│   ├── ReleaseStockHold.php
│   ├── ProcessPaymentWebhook.php     (idempotent)
│   └── SyncOfflineScans.php
│
├── Events/ & Listeners/              (AuditLog, Notifications, Reverb broadcasts)
│
├── Policies/                         (autorisations Eloquent — RBAC + Spatie Permission)
│
└── Filament/
    └── Resources/                    (Super Admin uniquement)
```

### Mobile (app agents)
- React Native + Expo, TypeScript
- SQLite (données locales), Expo SecureStore (auth), NetInfo (connexion), Expo Camera (scan)
- Consomme l'API Laravel (Sanctum) — voir section 27 pour le détail fonctionnel complet

---

## 62. ENTITÉS PRINCIPALES

Prévoir au minimum : `User`, `Organization`, `OrganizationMember`, `EventDelegate`, `Role`, `Permission`, `Event`, `EventLocation`, `TicketType`, `Ticket`, `Order`, `OrderItem`, `PaymentRefund`, `Invitation`, `PromoCode`, `PromoCodeUsage`, `AccessPoint`, `AccessAgent`, `AccessScan`, `Campaign`, `Donation`, `DonationRefund`, `Commission`, `Notification`, `AuditLog`.

---

## 63. MODÈLE DE DONNÉES — DONATION

Une donation doit notamment contenir : `id`, `campaignId`, `amount`, `currency`, `donorId` (nullable), `donorName` (nullable), `donorEmail` (nullable), `isAnonymous`, `paymentId`, `status`, `createdAt`, `confirmedAt`.

Ne PAS ajouter : `message`, `comment`, `note`, `testimonial`.

---

## 64. INTÉGRITÉ DU MODÈLE DONATION

Le montant d'un don confirmé ne doit pas être modifiable. Si une correction financière est nécessaire :

```
Donation → Refund / Adjustment
```

et non `UPDATE Donation.amount`. L'historique doit rester traçable.

---

## 65. CALCUL DES COLLECTES

```
confirmedDonations = DONATIONS WHERE status = CONFIRMED
collectedAmount = SUM(confirmedDonations.amount)
percentage = (collectedAmount / goalAmount) × 100
```

Limiter l'affichage à 100 % si nécessaire pour l'interface, tout en conservant la valeur réelle en backend.

---

