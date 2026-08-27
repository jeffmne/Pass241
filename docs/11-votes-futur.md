# Votes — fonctionnalité future

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Phase 9 de la roadmap (section 78) : développée **après** les phases 1 à 8, une fois la billetterie et les collectes validées. Ce n'est pas une fonctionnalité hors périmètre (section 79) — c'est une extension planifiée, pas encore prioritaire.

---

## 86. MODULE VOTES

À terme, la plateforme pourra également permettre d'organiser des votes.

Exemples d'usages visés :

- concours ;
- Miss et Mister ;
- artiste de l'année ;
- meilleur entrepreneur ;
- compétitions scolaires ou universitaires ;
- prix du public ;
- compétitions sportives.

### 86.1 Fonctionnement

Les organisateurs pourront :

- créer leurs candidats ;
- ouvrir les votes ;
- suivre les résultats.

### 86.2 Modèle économique du vote

Les votes pourront être **gratuits ou payants**, selon le type de compétition — le choix revient à l'organisateur au moment de la création du vote.

### 86.3 Règle importante — vote multiple

**C'est l'organisateur qui définit si une même personne peut voter à plusieurs reprises ou non.** Ce comportement doit être un paramètre configurable par campagne de vote, pas une règle fixée globalement dans le code — un concours « prix du public » peut vouloir un vote unique par personne, tandis qu'un vote payant type SMS/mobile money peut au contraire vouloir autoriser les votes multiples (chaque vote payant compte).

---

## 86.4 Périmètre fonctionnel détaillé — demandé le 2026-08-27

Précisions apportées par Jeff, à considérer comme la définition du module.

**Structure libre.** Aucune catégorie prédéfinie : l'organisateur crée son vote, puis autant de catégories qu'il veut, chacune avec ses candidats. Les catégories sont **indépendantes les unes des autres** — fenêtre de vote, gratuité, prix, plafond et visibilité des résultats se règlent catégorie par catégorie.

```
Election Miss 2026
├── Miss Libreville      → Candidate A, B, C
├── Meilleur Artiste     → Artiste A, B, C
└── Prix du Public       → Candidat X, Y
```

**Le vote** porte : nom, description, image/affiche, début, fin, statut (brouillon, programmé, actif, terminé), et le choix « résultats publics » ou « masqués jusqu'à la clôture ».

**Une catégorie** porte : nom, description, image, début, fin, vote gratuit ou payant, prix du vote, nombre maximum de votes par personne, visibilité des résultats, candidats.

**Un candidat** porte : nom, photo, description/biographie, numéro ou identifiant, catégorie, actif/inactif. L'organisateur peut ajouter, modifier ou désactiver un candidat — **jamais modifier son nombre de votes**.

**Page publique** partageable sur `pass241.com/vote/{slug}` : consulter le vote, ses catégories, ses candidats, en choisir un, voter. Mobile d'abord.

**Vote payant** : candidat choisi → nombre de votes → montant total → paiement → confirmation PSP → vote enregistré. **Le vote n'est VALIDÉ qu'après confirmation réelle du paiement par le PSP.**

**Vote gratuit** : autorisé par catégorie, avec les mécanismes de limitation d'abus définis par l'organisateur.

**Fin du vote** : à la date de fin, les nouveaux votes sont refusés, les catégories concernées closes, les résultats figés, l'historique conservé, et l'organisateur consulte les résultats définitifs. Un rapport final est prévu — total des votes, classement par catégorie, gagnants, revenus, période.

**Intégrité** : une fois un vote validé, l'organisateur ne peut ni le supprimer, ni le modifier, ni changer le nombre de votes, ni changer rétroactivement le candidat choisi. Toute opération administrative sensible est auditée.

---

## 86.5 Analyse d'architecture — faite le 2026-08-27, avant toute écriture

*Analyse menée sur le code réel à la demande de Jeff, pour ne pas la refaire au moment d'implémenter. Rien n'a été codé. Les décisions porteuses sont consignées dans la décision D-041.*

### Ce qui se réutilise tel quel

Comptes organisateurs, organisations, rôles Spatie, isolation multi-tenant (`BelongsToOrganization`, `EnsureOrganizationContext`), `AuditLogger` et sa table en ajout seul, `PspFeeCalculator` (déjà générique : `feeFor(int $subtotal)`), `QrRenderer`, notifications et jobs, le patron des statistiques (agrégation SQL + cache court, décision D-031), le design system, et le `FakeProvider` de paiement pour développer sans Airtel ni Moov.

### Le mur : la couche paiement est typée sur `Order`

```
CollectionProvider::initializePayment(Order)
CollectionProvider::checkPaymentStatus(Order)
CollectionProvider::refund(Order, int, string)
payment_webhooks.order_id            → clé étrangère vers orders
ProcessPaymentWebhook                → résout payload['order_reference']
RefundRequest                        → une demande par commande
Settlement                           → event_id unique, un virement par événement
```

Seule la commission est déjà polymorphe (`commissionable_type/id`), et sa contrainte en base n'accepte que `TICKETING` et `DONATION`.

**Faire passer les votes par `orders` est exclu** : `orders.event_id` est `NOT NULL` avec cascade sur `events`. Le rendre nullable toucherait la table la plus sensible du produit — celle que lisent les statistiques, les reversements, les remboursements et les reçus. C'est le même raisonnement de rayon d'explosion qui avait fait choisir l'inverse pour les invitations (décision D-039), et il conclut ici dans l'autre sens parce qu'une invitation a un événement, pas un vote.

**Dupliquer le chemin de l'argent est exclu aussi** : deux implémentations de l'idempotence, de la vérification de montant et de la commission divergeront, du côté le moins parcouru.

**La sortie est l'extraction d'un contrat `Payable`** — et elle appartient à la phase 7, pas à la phase 9 : les collectes butent sur le même mur en premier. Voir la décision D-041.

### Nommage retenu

`Vote` désignerait à la fois le scrutin et le bulletin. En code : **`Poll`** (le scrutin), **`PollCategory`**, **`Candidate`**, **`Ballot`** (le bulletin). En interface, tout reste « Vote », « Catégorie », « Candidat » — le vocabulaire de l'organisateur ne change pas.

### Modèle de données proposé

```
Organization
   └── Poll                    (le vote)
         ├── PollCategory      (indépendantes entre elles)
         │     └── Candidate
         └── Ballot            (un bulletin, lié à un Payment si payant)
               └── VoterQuota  (compteur par votant et par catégorie)
```

**`polls`** — `organization_id`, `name`, `slug` unique, `description`, `poster_path`, `banner_path`, `starts_at`, `ends_at`, `status` (`DRAFT`, `SCHEDULED`, `ACTIVE`, `CLOSED`, `CANCELLED`), `results_visibility` (`LIVE`, `AFTER_CLOSE`), `created_by`. `CHECK (ends_at > starts_at)`. Slug figé après publication, comme les événements.

**`poll_categories`** — `poll_id`, `name`, `description`, `image_path`, `starts_at` / `ends_at` nullables (héritent du scrutin), `is_paid`, `unit_price` (entier XAF), `max_votes_per_voter` nullable (= illimité), `results_visibility` nullable (= hérite), `position`, `is_active`. En base :

```sql
CHECK ((is_paid = false AND unit_price = 0) OR (is_paid = true AND unit_price > 0))
CHECK (max_votes_per_voter IS NULL OR max_votes_per_voter > 0)
```

**`candidates`** — `poll_category_id`, `name`, `number` (unique dans la catégorie), `photo_path`, `biography`, `is_active`, `position`. **Aucune colonne de compteur** : c'est la traduction en base de la règle « l'organisateur ne modifie jamais le nombre de votes ». On ne peut pas falsifier une colonne qui n'existe pas.

**`ballots`** — `organization_id`, `poll_id`, `poll_category_id`, `candidate_id`, `payment_id` nullable, `quantity` (>= 1), `unit_price`, `amount`, `status` (`PENDING`, `CONFIRMED`, `FAILED`, `REFUNDED`), `voter_hash`, `payer_phone` nullable, `ip_address`, `user_agent_hash`, `confirmed_at`.

Un **déclencheur PostgreSQL** interdit toute modification de `candidate_id`, `poll_category_id`, `quantity`, `unit_price` et `amount` ; seuls le statut et sa date peuvent bouger. C'est ce qui rend impossible — y compris depuis une commande artisan ou une correction manuelle — de changer rétroactivement le candidat choisi. L'ajout seul pur ne convient pas ici, contrairement aux commissions : un bulletin payant doit pouvoir passer de `PENDING` à `CONFIRMED`.

Un vote gratuit naît `CONFIRMED`. Un vote payant naît `PENDING` et n'est compté qu'à la confirmation du PSP.

**`voter_quotas`** — `poll_category_id`, `voter_hash`, `used`, unique sur le couple. Le plafond se vérifie par un `UPDATE … SET used = used + n WHERE used + n <= max` **atomique**, jamais par un `SELECT` suivi d'un `INSERT` : c'est le mécanisme de `StockLedger::commit`, et c'est ce qui empêche deux soumissions simultanées de passer toutes les deux.

**`poll_results`** — instantané figé à la clôture, par catégorie et candidat : `rank`, `votes`, `revenue`, `frozen_at`. En ajout seul. Sert le rapport final et garantit qu'un résultat publié ne bouge plus, même si un remboursement intervient après.

### Routes

Public, en Livewire — pas d'API REST : le votant n'a pas de compte, comme l'acheteur (section 15).

```
GET  /vote/{slug}                page publique du scrutin
GET  /vote/{slug}/{category}     une catégorie et ses candidats
GET  /vote/{slug}/resultats      selon results_visibility
     soumission par actions Livewire, sous limitation de débit
POST /webhooks/paiement/{provider}   route existante, inchangée
```

Dashboard : `/votes`, `/votes/creer`, `/votes/{poll}/modifier`, `/votes/{poll}/categories` (catégories et candidats sur un seul écran), `/votes/{poll}/resultats`.

Aucune route API mobile : l'application agent ne touche pas aux votes.

Permissions à ajouter : `votes.view`, `votes.manage`. Les revenus restent conditionnés par `finances.view`.

### Organisation du code

```
app/Models/                Poll, PollCategory, Candidate, Ballot, VoterQuota
app/Enums/                 PollStatus, ResultsVisibility, BallotStatus
app/Actions/Polls/         SavePoll, PublishPoll, ClosePoll
app/Actions/Voting/        CastFreeBallot, CreatePaidBallot, ConfirmBallot
app/Services/Voting/       VoterIdentity, VoteQuotaLedger, VotingStatistics, PollResultsFreezer
app/Livewire/Dashboard/Polls/    Index, Form, Categories, Results
app/Livewire/Public/       PollPage, PollCategoryPage, PollResults
app/Console/Commands/      ClosePolls (tâche planifiée)
```

Résultats en direct par Reverb **uniquement en retransmission d'une valeur déjà calculée côté serveur** (section 49) — jamais un compteur incrémenté dans le navigateur.

### Stratégie anti-fraude

Ce qui tient, parce que la base le garantit :

1. Aucun compteur de votes en colonne — tout est agrégé en SQL, avec un cache court. Il n'y a rien à falsifier.
2. Le déclencheur sur `ballots` rend candidat, quantité et montant immuables après création.
3. Le plafond par votant est vérifié par un `UPDATE` conditionnel atomique.
4. Prix et montant **recalculés serveur** depuis la catégorie : une quantité ou un prix venus du navigateur sont ignorés.
5. Idempotence déjà acquise par la couche paiement : unicité `(provider, event_id)` sur `payment_webhooks`, une seule commission par transaction, montant divergent refusé.
6. Limitation de débit sur les routes publiques de vote.
7. Audit de toute opération sensible : création, publication, clôture, ajout et désactivation d'un candidat, remboursement d'un bulletin.
8. Fenêtre de vote vérifiée **à chaque bulletin**, jamais seulement par la tâche planifiée — le scrutin refuse un vote hors délai même si le cron n'a pas tourné. Mécanisme déjà employé pour l'expiration des invitations.

**Ce qui reste fragile, et doit être dit :** le vote gratuit. Sans compte utilisateur et sans OTP SMS (décision D-004 : pas d'OTP en V1), il n'existe aucun moyen honnête de garantir « une personne, un vote ». On peut exiger un numéro, en dériver un `voter_hash`, y ajouter IP et empreinte d'appareil, limiter le débit — on ne peut pas empêcher quelqu'un de saisir cinq numéros.

Trois issues, **à trancher au moment d'implémenter** : assumer le caractère indicatif du vote gratuit et le dire dans l'interface ; activer l'OTP SMS pour les catégories gratuites, ce qui suppose un budget SMS ; ou ne proposer que le vote payant, où le paiement est lui-même la preuve d'unicité — c'est d'ailleurs le modèle économique réel de ce type de concours ici.

### Questions ouvertes, à trancher avant d'implémenter

1. **Taux de commission des votes.** `CLAUDE.md` fixe 5 % billetterie et 2,5 % collectes ; rien pour les votes. Recommandation : **5 %**, comme la billetterie — un vote payant est une vente de service à un public, pas un don à une cause. Assiette : le sous-total des bulletins, jamais le total débité frais PSP compris (décision D-023).
2. **Vote gratuit** : laquelle des trois issues ci-dessus.
3. **Rapport final** : PDF joint par email, ou écran exportable ? Le module de reçus (décision D-029) donne déjà le patron.

---

## 87. DASHBOARD ORGANISATEUR — VOTES

Extension future du dashboard organisateur (section 56). Doit afficher, par campagne de vote :

- candidats ;
- nombre de votes ;
- classement ;
- statistiques.

**Précisé le 2026-08-27** : votes par candidat, classement, votes par catégorie, total des votes, évolution des votes, revenus générés par les votes payants, statistiques par période. Les résultats publics respectent le choix de l'organisateur — temps réel, ou masqués jusqu'à la fin du vote.

---
