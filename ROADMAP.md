# ROADMAP — Pass241

> Suivi opérationnel des phases décrites en section 78 de [CLAUDE.md](CLAUDE.md).
>
> **Règle de séquencement non négociable** : le module Collectes (Phase 7) ne démarre pas tant que la billetterie (Phases 1 à 5) n'est pas terminée, testée et validée. Les deux modules partagent le paiement, la commission et le KYC/reversement — les valider une seule fois sur la billetterie limite la propagation de bugs.

**Méthode de travail** : on avance par jalons livrables et testables de bout en bout, pas par couches techniques. Chaque jalon se termine par une vérification concrète que Jeff peut faire lui-même.

---

## JALON 0 — Socle technique

- [x] Environnement Docker (PHP 8.4, PostgreSQL 16, Redis 7, Mailpit)
- [x] Laravel 13.26 installé dans `/backend`
- [x] PostgreSQL configuré (SQLite retiré du projet)
- [x] Redis configuré pour cache, sessions et queues
- [x] Livewire, Tailwind 4, Vite
- [x] Spatie Laravel Permission
- [x] Laravel Sanctum (scaffolding API pour l'app agents)
- [x] Laravel Horizon (queues)
- [x] Laravel Reverb (temps réel)
- [x] Pest, avec suite de tests exécutée sur PostgreSQL et non sur SQLite
- [x] `.env.example` conforme à la section 73
- [x] Dépôt Git initialisé, `.gitignore`
- [x] `ROADMAP.md` et `docs/00-decisions.md`

*Vérification : `docker compose up -d`, http://localhost:8000 répond, `pest` est vert.*

---

## JALON 1 — Fondations multi-tenant (extrait Phase 1)

- [ ] Migrations `users`, `organizations`, `organization_members`
- [ ] Rôles et permissions Spatie en mode `teams` (scope organisation)
- [ ] `kycStatus` sur `Organization` (`PENDING`, `SUBMITTED`, `VERIFIED`, `REJECTED`)
- [ ] Inscription et connexion organisateur
- [ ] Middleware `EnsureOrganizationContext`
- [ ] Trait de scoping automatique par organisation
- [ ] `AuditLog` et les premiers événements journalisés (section 68)
- [ ] Tests d'isolation organisation A / organisation B (section 74)

*Vérification : deux organisations créées, aucune fuite de données entre elles, prouvé par test.*

---

## JALON 2 — Événement et page publique (Phase 2)

- [ ] `Event`, `EventLocation`, les sept statuts (section 12)
- [ ] Slug stable, lisible, SEO-friendly (section 13)
- [ ] Création, modification, publication depuis le dashboard
- [ ] Page publique `/e/{slug}` (section 7)
- [ ] SEO, OpenGraph, aperçu de partage (section 14)
- [ ] Design system : palette bleu azurin, liquid glass avec modération (section 70)

*Vérification : un événement publié, son lien ouvert sur téléphone, l'aperçu correct sur WhatsApp.*

---

## JALON 3 — Billets, stock et commande (Phase 3)

- [ ] `TicketType`, `Ticket`, `Order`, `OrderItem`
- [ ] Gestion du stock et `StockHoldService` (Redis, TTL 5 minutes)
- [ ] Formulaire d'achat (section 15) — email facultatif
- [ ] Validation serveur du préfixe opérateur / numéro (section 16.1)
- [ ] Jobs `ExpireOrderIfUnpaid` et `ReleaseStockHold`

*Vérification : commande créée, stock réservé, expiration automatique prouvée par test.*

---

## JALON 4 — Paiement de bout en bout (Phase 4)

- [ ] `PaymentProviderInterface`
- [ ] `FakeProvider` de développement (valider / refuser / timeout)
- [ ] Webhook sécurisé et idempotent (sections 22, 23)
- [ ] `ProcessPaymentWebhook`, polling de secours `checkPaymentStatus()`
- [ ] `CommissionCalculationService` avec snapshot du taux (5 %)
- [ ] Entité `Commission`
- [ ] Tests : succès, échec, timeout, double webhook

*Vérification : un paiement simulé confirme la commande et enregistre la commission.*

---

## JALON 5 — Le billet en main (Phase 3, fin)

- [ ] `TicketTokenService` — payload signé, jamais d'identifiant brut (section 18)
- [ ] Billet affiché immédiatement après confirmation (section 15.1)
- [ ] QR Code, téléchargement PDF
- [ ] Espace « Mes billets » par lien signé et session longue durée
- [ ] Email de copie si une adresse a été renseignée (section 69)

*Vérification : la tranche verticale complète, de la création de l'événement au QR sur téléphone.*

---

## SUITE — après validation de la tranche verticale

### Phase 4 (complément)
- [ ] Airtel Money réel, Moov Money réel (dès obtention des credentials)
- [ ] Reçus
- [ ] Remboursements, modèle hybride demande/décision (section 24.1)
- [ ] `CommissionSettlementStrategy` et reversement (section 21.2)

### Phase 5 — Contrôle d'accès
- [ ] Application React Native / Expo (périmètre V1, section 27.17)
- [ ] Comptes agents, points d'entrée
- [ ] Scan QR, validation locale
- [ ] Mode hors connexion, SQLite, synchronisation
- [ ] `ScanConflictResolver` (double scan)

### Phase 6 — Invitations et promotions
- [ ] Invitations (section 25)
- [ ] Codes promo (section 26)
- [ ] Statistiques

### Phase 7 — Collectes *(bloquée tant que la billetterie n'est pas validée)*
- [ ] Campagnes, pages publiques
- [ ] Dons publics et anonymes
- [ ] Transparence financière, calcul automatique
- [ ] Remboursement partiel
- [ ] Tests anti-fraude obligatoires (section 75)
- [ ] Commission 2,5 %

### Phase 8 — Optimisation
- [ ] Exports, analytics
- [ ] Notifications
- [ ] Monitoring, sécurité, performance

### Phase 9 — Votes *(fonctionnalité future)*
- [ ] Module de vote (section 86)
