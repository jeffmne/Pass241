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

- [x] Nouveau logo intégré le 2026-08-26 (décision D-036) : pictogramme seul en `currentColor`, cadrage relevé à l'encre, favicon SVG clair et sombre, billet de la page d'accueil ramené à une taille de vitrine

**Reste à faire sur ce chantier, non bloquant :**
- [ ] `apple-touch-icon.png` — iOS ne lit pas les favicons SVG, et le billet vit dans le navigateur du téléphone
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

- [x] `TicketTokenService` — jeton signé HMAC, clé dédiée, aucune donnée personnelle (section 18)
- [x] Billet affiché immédiatement après confirmation (section 15.1)
- [x] Billet en verre liquide conforme à la section 15.2, bannière de l'événement en surimpression
- [x] QR Code sur encart blanc opaque, jamais sur le verre (règle 70.4)
- [x] Téléchargement du billet en **image**, capturée sur le billet affiché (décision D-021)
- [x] PDF réservé à l'email, une pièce jointe par billet — route publique supprimée
- [x] PDF sur une seule page, centré, QR en PNG, affiche recadrée et voile gravé (décision D-022)
- [x] Espace « Mes billets » sans compte, par lien signé et cookie de longue durée
- [x] Recherche manuelle par référence, pour un acheteur revenu d'un autre appareil
- [x] Email de copie si une adresse a été renseignée (section 69), envoyé par un job
- [x] Commande `pass241:ticket-key` pour générer la clé de signature

*Vérification : la tranche verticale complète est faite — création de l'événement, publication, achat, paiement, billet avec QR sur téléphone.*

**Points ouverts :**
- [ ] **Signature asymétrique** pour la vérification hors connexion par l'agent, sans exposer le secret sur son terminal (section 18). Le préfixe de version du jeton permet de l'ajouter sans invalider les billets en circulation — à trancher au jalon du contrôle d'accès.
- [ ] Apple / Google Wallet (non prioritaire V1, section 15.1)
- [ ] Vérification du rendu du PDF à l'impression réelle
- [ ] Vérification du téléchargement en image sur Safari iOS — la capture repose sur `foreignObject`, historiquement capricieux sur ce moteur. Un échec est déjà rattrapé à l'écran, mais reste à mesurer sur un vrai appareil.

---

## ACCENTUATION DE L'INTERFACE — analyse faite, application à venir

Décidé le 2026-08-25 : le texte visible doit être accentué. Aucune vue n'a encore été modifiée. L'analyse est faite, la reprise est mécanique.

**Périmètre** : nœuds de texte des vues Blade, attributs `aria-label` / `placeholder` / `alt` / `title`, libellés d'enums, messages de validation et d'erreur. Pas les commentaires (convention interne inchangée). Volume mesuré : **213 corrections automatiques dans 21 fichiers**, plus les cas ci-dessous.

**Quatre pièges de masquage, vérifiés — à ne pas réintroduire :**

1. Le `>` de `$attributes->merge` ferme la balise trop tôt : ses attributs passent pour du texte et `role="img"` devient `rôle="img"`. Il faut neutraliser les expressions `{{ }}` **avant** de découper les balises.
2. Une fonction fléchée JavaScript dans un `@click` contient `=>` : même effet. Neutraliser aussi les valeurs d'attributs (`=\s*"..."`).
3. Le corps d'une balise peut contenir du masque — ne pas l'exclure du motif de balise, sinon la balise n'est plus trouvée du tout.
4. L'attribut `content` d'une `<meta>` porte aussi bien une description lisible que `width=device-width` ou `summary_large_image` : le laisser hors de la liste blanche, traiter les rares descriptions à la main.

**Cas grammaticaux, tous relevés :**

- `a` → `à` partout, **sauf** quand il précède `été`, `pu`, `déjà` ou `pas` (auxiliaire avoir). Règle vérifiée sur les 41 occurrences.
- `des` → `dès` : trois occurrences seulement (`kyc-banner:34`, `organizations/settings:86`, `welcome:166`).
- `ou` → `où` : deux occurrences (`events/form:161` « Quand et où », `mail/tickets-issued:18` « Où : »).
- `la`, `du`, `sur` : aucune occurrence à accentuer.

**Mots ambigus, tranchés :** accentués — `annule`, `confirme`, `emis`, `imprime`, `publie`, `utilise`, `utilisee`, `achete` ; non accentués — `affiche`/`affiches` (le nom, l'affiche de l'événement), `limite` (le nom), `valide` (l'adjectif). `cree` devient `crée` et non `créé` dans « se crée en laissant le prix à zéro ».

**Reste à faire** : compléter le dictionnaire (`francais`, `parametres`, `exigee`, `ramene`, `zero`, `autorises`), appliquer, corriger les assertions de tests qui vérifient des chaînes exactes, et traiter les chaînes visibles vivant dans des attributs JavaScript (`x-text="busy ? 'Preparation' : 'Telecharger'"`).

**Données de démonstration** : le seeder contient aussi du texte non accentué (« Institut francais du Gabon »), à reprendre dans la même passe.

---

## DEMANDES EN ATTENTE — reçues le 2026-08-26

Six chantiers demandés, à traiter au fil du développement. **Chacun commence par une analyse de l'existant et un plan soumis à validation avant toute écriture de code.** Rien n'est à implémenter tant que ce plan n'a pas été validé.

### A. Période de vente par type de billet — DEJA EN PLACE, verrouillée le 2026-08-26

**La fonctionnalité existait déjà**, de bout en bout : colonnes `sales_start_at` / `sales_end_at` avec contrainte `CHECK` en base, `salesHaveStarted()` / `salesHaveEnded()` / `isPurchasable()` / `unavailabilityReason()` sur `TicketType`, champs `datetime-local` et validation `after_or_equal` dans l'écran des types de billets, refus côté serveur dans `CreateOrder`, et affichage du motif à la place des boutons de quantité au checkout.

Les quatre états demandés sont distingués, y compris la nuance qui compte : **« La vente est terminée » et « Complet » ne disent pas la même chose** à l'acheteur — le premier lui dit de renoncer, le second de revenir voir si une place se libère.

**Ce qui manquait, et qui a été ajouté** : la couverture de tests (`SalesWindowTest`, 11 cas), dont le refus d'une commande dont la période se ferme entre l'affichage et la validation.

**Un bug réel corrigé au passage** : `unavailabilityReason()` lisait `$this->event` sans lever la portée d'organisation. Sur un chemin public — où l'acheteur n'appartient à aucune organisation — la relation revenait `null` et la méthode **plantait**. `TicketType::resolvedEvent()` relit désormais l'événement hors portée et échoue en fermé. C'est la cinquième fois que cette portée piège un chemin public.

### B. Statistiques du dashboard organisateur — FAIT le 2026-08-26 (décision D-031)

Billets vendus, recettes, net après commission, billets restants, et répartition par catégorie avec barres CSS. Tout agrégé en SQL, cache d'une minute, montants conditionnés par `finances.view`, isolation multi-tenant vérifiée par test.

**Reste à faire** : entrées et taux de présence sont déjà calculés et testés, mais masqués tant qu'aucun billet n'a été scanné — ils s'afficheront d'eux-mêmes dès que le contrôle d'accès (phase 5) produira des scans. Rien à écrire de plus.

**Non fait, à évaluer plus tard** : une vue par événement plutôt que consolidée sur l'organisation. Utile dès qu'un organisateur aura plusieurs événements simultanés ; inutile aujourd'hui.

### C, D, E. Conservation des fichiers de billets — ABANDONNE le 2026-08-26

**Sans objet, et c'est voulu.** Aucun fichier de billet n'est stocke : le PDF est rendu a la volee au moment d'envoyer l'email (`TicketPdf`), et le PNG est produit dans le navigateur de l'acheteur (decision D-021). Rien ne touche jamais le disque ni S3.

Il n'y a donc **aucun fichier a conserver, aucun a supprimer, aucune regle Lifecycle a ecrire, et aucune duree de conservation a configurer.** Le rendu a la volee n'a ni cout de stockage, ni purge a organiser, ni traçabilite de suppression a tenir — il rend le probleme inexistant plutot que de le resoudre.

Les garanties d'integrite demandees sont par ailleurs deja acquises : billet, evenement, participant, type, prix, statut, QR/jeton, transaction, remboursements et historique vivent en base, et rien de ce qui touche aux fichiers ne peut les atteindre.

**A rouvrir seulement si** un besoin reel de stockage apparait — par exemple si le rendu a la volee devient trop couteux sur un evenement de 100 000 billets, ou si un billet doit rester telechargeable a l'identique des annees plus tard. Le plan complet (S3 Lifecycle par prefixe date, marquage en base sans appel reseau, chunking) reste a ecrire a ce moment-la.

### F. Console d'administration — FAIT le 2026-08-26 (décision D-032)

Cinq écrans : vue générale, reversements, organisations, transactions, journal d'audit. Accès hors du système de rôles, vue transverse assumée, consultation seule sans aucune méthode de modification, verre liquide sur la colonne et les cartes, effets au défilement et au survol.

Le réglage de durée de conservation qui motivait cet écran est sans objet (voir C, D, E).

**Reste à faire, non bloquant** : suspension d'une organisation par un chemin dédié et audité (section 10) ; export du journal (section 59) ; alerte automatique sur un reversement resté en état inconnu plusieurs jours.

---

## SUITE — après validation de la tranche verticale

### Phase 4 (complément) — refonte du modèle de paiement, validée le 2026-08-25

Décisions prises : frais PSP à la charge de l'acheteur et affichés séparément ; reversement après l'événement ; aucun document administratif demandé à l'organisateur.

- [x] **Jalon A** — frais PSP payés par l'acheteur (décision D-023) : barème par PSP en configuration, `PspFeeCalculator` en arithmétique entière arrondie au supérieur, colonnes `subtotal` / `psp_fee_amount` / barème figé / `psp` sur `orders`, contraintes `CHECK` d'éclatement en base, ligne « Frais de paiement » au checkout et sur le suivi de commande. La commission Pass241 s'assied sur `subtotal`, jamais sur le total débité.
  - [ ] Barèmes réels à saisir dès signature avec PVit, PAYNALA ou Digitech Africa — les valeurs de `fake` sont fictives
- [x] **Jalon B** — abstraction PSP à deux faces (décision D-024) : `CollectionProvider` et `SettlementProvider`, capacités déclarées par le driver, `PayoutRequest` / `PayoutResult` / `PayoutState`, idempotence obligatoire sur le sortant, `holdsFunds()` comme critère de choix du prestataire
  - [ ] `orders.psp` est renseignée mais aucune ligne financière ne porte encore la référence de transaction du prestataire côté reversement — à faire avec la table `settlements` du jalon D
- [x] **Jalon C** — destination de reversement sur `Organization` (décision D-025) : numéro + opérateur, sans pièce justificative ni vérification par code, nom du titulaire affiché, changement journalisé à part et notifié par email, colonnes hors `$fillable` avec `SetPayoutDestination` comme seul chemin d'écriture. KYC documentaire éteint derrière `payments.payouts.require_kyc_documents`, non supprimé.
  - [ ] Écran des réglages non vérifié visuellement — couvert par les tests Livewire, mais la connexion demande une saisie de mot de passe
- [x] **Jalon D** — reversement après l'événement (décision D-026) : table `settlements` aux montants gelés par déclencheur, un virement par événement, `SettleEvent` avec quatre barrières contre le double paiement, commandes planifiées `pass241:settle-events` (horaire) et `pass241:reconcile-settlements` (deux fois par jour), liste en consultation seule au tableau de bord — aucun bouton « retirer »
  - [x] Notifier l'organisateur quand son reversement est parti (section 69) — envoyé dès l'acceptation de l'ordre, avec l'éclatement brut / commission / net
  - [x] Console Super Admin des reversements (décision D-030) : accès hors rôles via `EnsureSuperAdmin`, vue transverse assumée, consultation seule sans aucune méthode de modification, totaux agrégés en SQL
  - [ ] Alerte quand un reversement reste en état inconnu plusieurs jours — aujourd'hui visible seulement dans la sortie de la commande
- [x] **Jalon E** — remboursements (décision D-027) : `refund_requests`, règle des 48h vérifiée serveur et en échec fermé, décision motivée de l'organisateur, ajustement de commission par ligne nouvelle au taux figé, déduction automatique du reversement, `pass241:expire-refund-requests`, écran acheteur et écran organisateur
  - [x] Notifier le client de la décision par email (section 69), motif du refus compris
  - [x] Annulation d'événement par l'organisateur (décision D-028) : remboursement de tous les billets frais compris, demandes créées déjà approuvées, délai de 48h ignoré, commission rendue sur la part billets seulement
- [ ] Critère éliminatoire dans le choix du PSP : les fonds doivent rester dans les livres du PSP (sous-compte marchand + reversement programmé par API), Pass241 ne faisant que donner l'instruction
- [ ] Airtel Money réel, Moov Money réel (dès obtention des credentials)
- [x] Reçus (décision D-029) : PDF sur lien signé et joint à l'email, vendeur = organisateur, frais détaillés, commission absente, remboursements reflétés

### Phase 5 — Contrôle d'accès

**Socle serveur fait le 2026-08-26 (décision D-033)** : `entry_points`, `event_agents`, `access_scans` en ajout seul, `ValidateTicket` avec verrou et arbitrage du double scan, API agent complète (connexion Sanctum, événements, synchronisation incrémentale par curseur, remontée de lots de scans). 34 tests.

**Écrans web faits le 2026-08-26 (décision D-034)** : portes et agents sur un seul écran attaché à l'événement, point d'entrée facultatif, plusieurs agents possibles sur une même porte, mot de passe généré affiché une seule fois, fermeture plutôt que suppression. 13 tests.

**Tableau de bord, historique et entrée sans QR faits le 2026-08-26 (décision D-035)** : compteurs de l'entrée entière et de l'agent, historique corrigé par le serveur, recherche manuelle, et admission à la référence marquée comme telle. 18 tests.

**Écran des entrées fait le 2026-08-26 (décision D-037)** : le pendant organisateur — compteurs, journal filtrable, admission manuelle comptée à part, en lecture seule définitive. 14 tests.

- [x] Comptes agents, points d'entrée
- [x] Scan QR et validation serveur
- [x] Résolution du double scan hors connexion (section 31.1)
- [x] API agent : connexion, synchronisation, remontée des scans
- [x] Écrans web de gestion des agents et des points d'entrée
- [x] Tableau de bord agent et historique des contrôles (sections 27.14, 27.15) — côté serveur ; les écrans viennent avec l'application
- [x] Écran organisateur des entrées (décision D-037) : qui est passé, par quelle porte, ce qui a été refusé, et **combien d'entrées ont été accordées sans lecture du QR**. Lecture seule, vérifiée par un test qui refuse toute méthode d'écriture. 14 tests
- [x] Application React Native / Expo (périmètre V1, section 27.17) — décision D-038 : connexion, événements autorisés, scanner, validation locale, historique, recherche manuelle, compteurs. 31 tests sur les modules purs
- [x] Base locale SQLite et file de synchronisation côté application (décision D-038)
- [ ] **Premier lancement sur un vrai appareil.** Le contrat d'API est vérifié de bout en bout contre le serveur local, et `npm run typecheck` / `npm test` passent — mais caméra, SQLite, SecureStore et NetInfo n'existent que sur un téléphone. Rien de ce qui touche au matériel n'a encore tourné
- [ ] Révocation d'un appareil à distance (section 27.3) — le serveur sait révoquer une affectation, l'application ne l'apprend qu'à sa requête suivante
- [ ] Signature asymétrique du jeton, pour une vérification hors ligne sans empreintes (section 18, décision D-018)

### Phase 6 — Invitations *(promotions reportées)*

**Invitations faites le 2026-08-26 (décision D-039)** : une place mise de côté, qui devient un billet à l'acceptation. Elle emprunte le chemin des commandes — à zéro franc — pour hériter du stock, du QR signé, du PDF et du contrôle à l'entrée, sans jamais compter comme une vente. 31 tests.

**Envoi par email fait le 2026-08-27 (décision D-040)** : le lien part à la création pour les invités qui ont une adresse, avec une case décochable et un bouton de renvoi. Le statut « envoyée » n'est posé qu'après l'envoi réel, et le journal distingue les deux canaux. 9 tests.

- [x] Invitations (section 25) : création par liste collée, lien à transmettre, acceptation publique sans compte, annulation, expiration automatique, suivi
- [x] Envoi par email du lien d'invitation, réponse adressée à l'organisateur, renvoi limité à une fois par minute
- [x] Statistiques : couvertes par le tableau de bord organisateur (décision D-031), qui compte les invités à part des ventes et retranche les deux des places restantes

**Reporté, décidé le 2026-08-27 (décision D-040) :**
- [ ] **Codes promo (section 26).** Levier commercial, pas une brique de lancement : il suppose une audience installée et des partenaires à mesurer. Rien n'a été écrit, donc aucune dette — seulement une décision à reprendre. Quatre points déjà tranchés dans D-040 : commission sur le montant réellement payé, un seul code par commande, limite par personne portée par le numéro de téléphone, remise à 100 % refusée
- [ ] Statistiques par code promo — sans objet tant que les codes n'existent pas

### Phase 7 — Collectes

**Ordre des jalons arrêté le 2026-08-27.** L'analyse du module Votes (décision D-041) a montré que la couche paiement est typée sur `Order` de bout en bout : un don n'est pas une commande, et la généraliser une fois sert aussi les votes. Elle est donc faite ici, pas plus tard.

- [x] **Jalon 7.1 — Campagne et page publique, fait le 2026-08-27 (décision D-042)** : `Campaign` et ses six statuts, slug figé à la publication, écrans organisateur, page publique `/collecte/{slug}` avec progression et QR de partage, tâche `pass241:expire-campaigns`. **Aucune colonne de montant collecté** — tout est calculé par `CampaignProgress`. 30 tests
  - [ ] Bouton « Faire un don » rendu inerte tant que le jalon 7.2 n'a pas ouvert le paiement — visible plutôt que masqué, comme les entrées de menu non prêtes
- [x] **Jalon 7.2 — Couche paiement générique + premier don payé, fait le 2026-08-27 (décisions D-041, D-043)** : contrat `Payable`, `ConfirmPayment` déplacé et commun, `CollectionProvider` typé sur `Payable`, `payment_webhooks` polymorphe, `PayableResolver`. `Donation` avec anonymat, immuabilité garantie par déclencheur PostgreSQL, commission 2,5 %, formulaire public et page de suivi. 34 tests, dont les sept tests anti-fraude de la section 75. **La billetterie n'a pas changé de comportement : ses 547 tests sont passés inchangés.**
  - [ ] **La table `payments` n'a pas été créée** (décision D-043) : elle n'aurait rien rapporté tant que `orders` ne peut pas y migrer. Ce qui devait être partagé était la logique, pas le schéma
  - [ ] Reçu de don (section 53) — avec le module de reçus, jalon 7.5
- [x] **Jalon 7.3 — Transparence, fait le 2026-08-27 (décision D-044)** : historique public des dons avec « voir plus » et total toujours annoncé, anonymat appliqué en un seul point (`publicDonorName`) — **y compris pour l'organisateur** —, `CampaignStatistics` (collecté, donateurs, don moyen, plus gros don, net, évolution sur 30 jours), écran organisateur en lecture seule définitive. 11 tests
- [x] **Jalon 7.4 — Remboursement partiel et reversement, fait le 2026-08-27 (décision D-045)** : `donation_refunds` en opération liée — le don n'est jamais modifié —, ajustement de commission au taux figé, montant collecté et montant public du don recalculés seuls, remboursement depuis l'écran des dons sous `finances.refund`. `settlements` devient polymorphe (`settlable_type` / `settlable_id`), `SettleEvent` devient `SettleFunds` sur un contrat `Settlable`, et la commande planifiée balaie événements **et** collectes closes. 29 tests
  - [ ] **Une collecte longue ne touche rien avant sa clôture** (décision D-045) : contrainte assumée. La sortie, si l'usage l'impose, est le reversement périodique — plusieurs virements par collecte, donc unicité à lever et cumul à tenir
- [x] **Jalon 7.5 — Anti-fraude et reçus, fait le 2026-08-27 (décision D-046)** : les sept tests de la section 75 rassemblés et numérotés dans un seul fichier, audit vérifié (acteur, action, ressource, IP, metadata), reçu de don en PDF sur lien signé et joint à l'email de confirmation. 22 tests
- [x] Commission 2,5 %, calculée automatiquement, jamais saisie — assise sur le don et non sur le montant débité

**Phase 7 terminée le 2026-08-27. 643 tests, 1642 assertions.**

**Reste ouvert sur les collectes, non bloquant :**
- [ ] Une collecte longue ne touche rien avant sa clôture (décision D-045) — sortie possible : reversements périodiques
- [ ] Le donateur ne peut pas demander lui-même un remboursement : c'est l'organisateur qui décide (contrairement à la billetterie, section 24.1). À rouvrir si le besoin apparaît
- [ ] Vue consolidée des collectes au tableau de bord général (l'entrée de menu mène à la liste, pas à des chiffres agrégés)

### Phase 8 — Optimisation
- [ ] Exports, analytics
- [ ] Notifications
- [ ] Monitoring, sécurité, performance
- [x] **Déconnexion après inactivité, faite le 2026-08-28 (décision D-050)** — sortie de phase, demandée par Jeff : `ExpireInactiveSessions` sur le groupe `web`, `AUTH_IDLE_TIMEOUT` à 30 minutes, message nommant le délai, 419 pour Livewire. « Se souvenir de moi » passe à décoché par défaut — cochée, elle rendait l'expiration de session sans effet. 9 tests

### Phase 9 — Votes *(fonctionnalité future)*

**Analyse d'architecture faite le 2026-08-27, avant toute écriture** — périmètre détaillé, modèle de données, routes, organisation du code et stratégie anti-fraude en sections 86.4 et 86.5 de [docs/11-votes-futur.md](docs/11-votes-futur.md). Ce qui engage les autres modules est consigné dans la décision D-041, et traité en phase 7.

**Traitée avant la phase 8, à la demande de Jeff (2026-08-28).** L'ordre de la roadmap n'était contraignant que de 1 à 7, où les modules partagent le chemin de l'argent.

- [x] **Les trois questions ouvertes tranchées le 2026-08-28 (décision D-047)** : commission des votes à **5 %** comme la billetterie ; vote gratuit **assumé comme indicatif et annoncé dans l'interface** ; rapport final en **écran exportable**, PDF plus tard
- [x] **Jalon 9.1 — Fondations, fait le 2026-08-28 (décision D-048)** : `polls`, `poll_categories`, `candidates`, enums `PollStatus` / `ResultsVisibility`, permissions `votes.view` / `votes.manage`, commission `VOTING` ouverte en base. Aucune colonne de compteur nulle part ; règles tenues par des `CHECK` PostgreSQL ; fenêtre de catégorie héritée jamais recopiée, et jamais capable d'ouvrir ce que le scrutin a fermé. 39 tests
- [x] **Jalon 9.2 — Dashboard organisateur, fait le 2026-08-28 (décision D-049)** : `SavePoll`, `PublishPoll`, `SavePollCategory`, `SaveCandidate`, écrans liste / formulaire / catégories-et-candidats, entrée de menu, huit actions d'audit. Publier est un seul geste — la date d'ouverture décide entre actif et programmé ; les termes économiques se figent à l'ouverture ; catégories et candidats descendent du scrutin, donc toute recherche part de lui. 41 tests
- [ ] Jalon 9.3 — Page publique et vote gratuit : `Ballot`, `VoterQuota`, quota atomique, déclencheur d'immuabilité, mention du caractère indicatif
- [ ] Jalon 9.4 — Vote payant : `Ballot` comme `Payable`, `PayableResolver`, commission, webhooks
- [ ] Jalon 9.5 — Résultats, clôture, rapport : `VotingStatistics`, `poll_results` figés, tâche `ClosePolls`, écran exportable

**Reste ouvert, non bloquant :**
- [ ] `Poll::publicUrl()` référence la route `polls.public`, qui n'existe qu'au jalon 9.3 — le lien partageable n'apparaît donc pas encore sur les écrans organisateur
- [ ] Le gel des termes économiques se déclenche à l'ouverture du vote. Le jalon 9.3 pourra l'affiner en regardant s'il existe réellement un bulletin
