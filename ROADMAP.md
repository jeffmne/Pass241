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

- [x] Migrations `users`, `organizations`, `organization_members`, `organization_documents`
- [x] Rôles et permissions Spatie en mode `teams` (scope organisation)
- [x] `kycStatus` sur `Organization` (`PENDING`, `SUBMITTED`, `VERIFIED`, `REJECTED`)
- [x] Dépôt des pièces justificatives sur disque privé, décision de vérification réservée au Super Admin
- [x] Inscription et connexion organisateur, limitation des tentatives
- [x] Middleware `EnsureOrganizationContext`
- [x] Trait de scoping automatique par organisation, fermé par défaut hors contexte
- [x] `AuditLog` en ajout seul, protégé par déclencheurs PostgreSQL (section 68)
- [x] Dashboard, changement d'organisation, écran de paramètres — mobile-first
- [x] Design system appliqué : palette azurine, Manrope et Inter, icônes Lucide
- [x] Tests d'isolation organisation A / organisation B (section 74)

*Vérification : deux organisations créées, aucune fuite de données entre elles, prouvé par 72 tests.*

**Reporté au jalon où l'entité concernée existera :**
- `EventDelegate` et le rôle CO_ORGANIZER limité à un événement (section 10) — le rôle et ses permissions existent, le rattachement à un événement précis attend le jalon 2.
- Écran de gestion des membres de l'organisation — la permission `members.manage` existe et est testée, l'interface attend d'avoir des membres à gérer.

---

## HORS JALON — Identité visuelle des surfaces publiques

*Travail demandé en cours de jalon 1, exécuté entre le jalon 1 et le jalon 2. Consigné ici pour que la feuille de route reflète ce qui a réellement été fait.*

- [x] Design system appliqué : nappe ambiante, verre liquide, boutons pilule en dégradé, Manrope et Inter, icônes Lucide
- [x] Page d'accueil refondue à partir d'une maquette de référence fournie
- [x] Connexion et inscription refondues, inscription adaptée aux grands écrans
- [x] Composant `x-glass-ticket` — élément signature de la section 70.5, réutilisable au jalon 5
- [x] `QrRenderer` (SVG, correction d'erreur haute) — réutilisable au jalon 5
- [x] Qualité adaptative selon les capacités de l'appareil (décision D-014)
- [x] Suppression des `wire:model.live` servant un simple état visuel (décision D-015)

**Reste à faire sur ce chantier, non bloquant :**
- [ ] Coordonnées réelles dans le pied de page — volontairement laissées vides plutôt qu'inventées
- [ ] Vérification du rendu sur un vrai téléphone d'entrée de gamme
- [ ] Dashboard organisateur : non retouché, la lisibilité y prime sur l'expression

---

## JALON 2 — Événement et page publique (Phase 2)

- [x] `Event`, `EventLocation`, les sept statuts et leurs transitions (section 12)
- [x] Slug stable, lisible, unique sur la plateforme, figé après publication (section 13)
- [x] Création, modification, publication, retour au brouillon, annulation motivée
- [x] Conditions de publication annoncées avant le clic, pas découvertes après
- [x] Affiche et bannière sur disque public, limitées à 4 Mo
- [x] Catégories, visibilité référençable ou privée, capacité maximale
- [x] Liste des événements : recherche, filtre par statut, état vide, lien public copiable
- [x] Page publique `/e/{slug}` avec héros pleine largeur (section 7)
- [x] SEO, OpenGraph, données structurées schema.org, canonique (section 14)
- [x] Design system appliqué : palette azurine, verre avec modération (section 70)

*Vérification : `/e/concert-afro-2026` et `/e/gala-des-entrepreneurs` sont créés par le seeder.*

**Reporté, faute d'entité concernée à ce stade :**
- [ ] Passage automatique en `ONGOING` puis `COMPLETED` — demande une tâche planifiée, à traiter avec les autres travaux périodiques
- [ ] Passage automatique en `SOLD_OUT` — dépend du stock des types de billets (jalon 3)
- [ ] `EventDelegate` : délégation limitée à un événement précis (section 10)

---

## JALON 3 — Billets, stock et commande (Phase 3)

- [x] `TicketType`, `Order`, `OrderItem`, `Ticket`
- [x] Gestion du stock et `StockHoldService` (Redis, TTL aligné sur le délai de paiement)
- [x] Écran de gestion des types de billets dans le dashboard
- [x] Parcours d'achat public (section 15) — sans compte, email facultatif
- [x] Validation serveur du numéro et de sa cohérence avec l'opérateur (section 16.1)
- [x] Jobs `ExpireOrderIfUnpaid` et `ReleaseStockHold`
- [x] Montants en entiers XAF, contraints en base, figés à l'achat

*Vérification : `/e/concert-afro-2026` — trois tarifs dont une dernière place, qui déclenche le hold.*

**Points ouverts, à trancher avant la mise en vente réelle :**
- [ ] **Préfixes opérateurs à confirmer** auprès d'Airtel Gabon et Moov Gabon (`config/payments.php`). Tant que les listes sont vides, le format du numéro est vérifié mais pas son appartenance à l'opérateur.
- [ ] **Risque de survente au-delà du dernier billet.** La section 17.1 limite le hold au dernier billet restant ; plusieurs commandes simultanées peuvent donc s'ouvrir sur le même stock abondant. La garantie de non-dépassement sera posée à la confirmation du paiement (jalon 4), par une vérification atomique avant émission des billets.
- [ ] Émission des billets et QR signés — au moment de la confirmation du paiement (jalon 4)
- [ ] Passage automatique en `SOLD_OUT` quand tous les types sont épuisés

---

## JALON 4 — Paiement de bout en bout (Phase 4)

- [x] `PaymentProviderInterface` et `PaymentManager`
- [x] `FakeProvider` de développement (valider / refuser / ne rien renvoyer)
- [x] Webhook signé et idempotent, idempotence portée par une contrainte de base (sections 22, 23)
- [x] `ProcessPaymentWebhook`, polling de secours pendant l'attente (section 21.1)
- [x] `ConfirmPayment` : vérification atomique du stock, émission des billets, tout ou rien
- [x] `CommissionCalculator` et entité `Commission`, en ajout seul, taux figé à la transaction
- [x] Écran de suivi de commande avec compte à rebours, sur lien signé
- [x] Vérification du montant : un callback au montant divergent ne confirme rien
- [x] Tests : succès, échec, montant divergent, double webhook, signature forgée, paiement tardif

*Vérification : `/e/concert-afro-2026` — l'écran de simulation reproduit les trois issues de la section 21.1.*

**Points ouverts :**
- [ ] **Préfixes opérateurs à confirmer** auprès d'Airtel Gabon et Moov Gabon (`config/payments.php`)
- [ ] **Remboursement automatique** quand un paiement tardif ne trouve plus de stock. Le cas est détecté, tracé et retrouvable (`payment_webhooks.outcome`), mais le remboursement reste manuel tant que le module de la section 24 n'existe pas.
- [ ] Reversement à l'organisateur (`CommissionSettlementStrategy`, section 21.2) — dépend des capacités réelles des opérateurs
- [ ] Airtel Money et Moov Money réels, dès obtention des accès

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
