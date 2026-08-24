# CLAUDE.md

# Pass241 — Plateforme SaaS de billetterie, contrôle d'accès et collecte de fonds

**Nom du projet : Pass241**

---

## APERÇU RAPIDE

*Ce résumé n'ajoute aucune règle nouvelle — il oriente une première lecture. Les sections détaillées ci-dessous font seules foi.*

- **Produit** : SaaS multi-tenant (billetterie + collecte de fonds + contrôle d'accès) pour le marché gabonais. Paiement : Airtel Money / Moov Money.
- **Qui est le client ?** L'organisateur (compte SaaS). Le participant/donateur est l'utilisateur final — jamais l'inverse (section 2).
- **Pas de marketplace** : chaque événement/campagne a un lien public unique, partagé par l'organisateur lui-même (sections 4-6).
- **Stack** : Laravel 13 / PHP 8.4 / PostgreSQL / Redis / Horizon / Sanctum / Reverb (backend + dashboard) · Livewire 4 (Alpine embarqué) + Tailwind (web) · Filament (Super Admin uniquement) · React Native + Expo + SQLite (app agents, offline-first) — détail section 60, écarts justifiés dans [docs/00-decisions.md](docs/00-decisions.md).
- **Argent** : la plateforme encaisse, prélève sa commission (5 % billetterie, 2,5 % collectes) et reverse le net — elle n'est **jamais dépositaire des fonds**, et un reversement est bloqué tant que l'organisateur n'a pas complété son KYC (section 10.1, 21.2).
- **Ce qui ne bouge jamais** : le frontend ne calcule ni ne modifie aucune donnée financière ; l'isolation multi-tenant est vérifiée côté backend, jamais déduite d'un `organizationId` envoyé par le client (sections 9, 50, 80).
- **Hors périmètre actuel** : cashless, marketplace publique, réseau social, messages sur les dons, crypto (sections 5, 33, 79).
- **Séquencement** : le module Collectes (Phase 7) ne démarre qu'une fois la Billetterie (Phases 1-5) terminée et validée (section 78).
- **Avant de coder** : relire la section 80 (règles absolues), la section 81 (règles de travail pour Claude Code) et la section 82 (definition of done).
- **Identité visuelle** : bleu azurin + blanc cassé, liquid glass avec modération — jamais sur les tableaux denses ni sur le QR Code (section 70, détail [docs/10-design-system.md](docs/10-design-system.md)).

---

## INDEX DES MODULES

Le détail de chaque domaine vit dans `docs/`. La numérotation des sections (1 à 85) est conservée telle quelle à l'intérieur des fichiers découpés — un renvoi « section 9 » lu n'importe où dans ce projet reste valable, où que la section ait physiquement été déplacée.

| Fichier | Sections | Contenu |
|---|---|---|
| [docs/00-decisions.md](docs/00-decisions.md) | — | Journal des décisions techniques : arbitrages hors spec, avec date et raison |
| [docs/01-vision-modele-roles.md](docs/01-vision-modele-roles.md) | 1–10 | Vision, modèle produit, qui peut créer un compte, distribution, mini-site, multi-tenant, rôles, KYC |
| [docs/02-billetterie-evenements.md](docs/02-billetterie-evenements.md) | 11–20 | Événements, parcours d'achat, génération du billet, types de billets, QR Code, statuts, commandes |
| [docs/03-billetterie-paiements.md](docs/03-billetterie-paiements.md) | 21–26 | Paiements Airtel/Moov, webhooks, idempotence, remboursements, invitations, codes promo |
| [docs/04-controle-acces.md](docs/04-controle-acces.md) | 27–33 | Application mobile agents, scan QR, points d'entrée, mode hors connexion, badges |
| [docs/05-collecte-fonds.md](docs/05-collecte-fonds.md) | 34–55 | Campagnes, dons, transparence financière, anonymat, remboursements, statistiques |
| [docs/06-dashboards-exports.md](docs/06-dashboards-exports.md) | 56–59 | Dashboard organisateur, dashboard Super Admin, exports |
| [docs/07-architecture-technique.md](docs/07-architecture-technique.md) | 60–65 | Stack technique, structure backend, entités, modèle de données Donation |
| [docs/08-securite-notifications-design.md](docs/08-securite-notifications-design.md) | 66–73 | Sécurité, autorisations financières, audit logs, notifications, UI/UX, performance, variables d'env |
| [docs/09-tests-git-documentation.md](docs/09-tests-git-documentation.md) | 74–77 | Tests, tests anti-fraude des collectes, Git, documentation |
| [docs/10-design-system.md](docs/10-design-system.md) | 70 (détail) | Palette bleu azurin/blanc cassé, typographie, principes du liquid glass — démo : [docs/10-design-system-preview.html](docs/10-design-system-preview.html) |
| [docs/11-votes-futur.md](docs/11-votes-futur.md) | 86–87 | Module Votes — fonctionnalité future (Phase 9), extension du dashboard organisateur |

**Comment lire ce projet :** ce fichier `CLAUDE.md` porte la vision d'ensemble, la roadmap et les règles non négociables — c'est ce qui doit toujours être en tête. Pour travailler sur un module précis, ouvrir le fichier `docs/` correspondant ; il rappelle en en-tête que les règles absolues de ce fichier priment en cas de doute.

---

## 78. ROADMAP

**Suivi détaillé avec cases à cocher : [ROADMAP.md](ROADMAP.md).**

**Note de priorisation :** les deux modules (billetterie et collectes) sont prévus pour un lancement sans deadline fixe imposée, mais la **construction reste strictement séquentielle** : la billetterie (Phases 1 à 5) doit être terminée, testée et validée avant de démarrer le module Collectes (Phase 7). Ce choix n'est pas dû à une contrainte de délai, mais au fait que les deux modules partagent des briques critiques (paiement, commission, KYC/reversement) — les valider une seule fois sur la billetterie avant de les réutiliser sur les collectes limite la propagation de bugs entre les deux.

**Ne pas commencer le développement du module Collectes (Phase 7) tant que la billetterie n'est pas validée.**

**PHASE 1 — FONDATIONS** : architecture, PostgreSQL, migrations Laravel, authentification (Sanctum), organisations, utilisateurs, rôles (Spatie Permission), permissions, multi-tenancy, statut KYC sur `Organization`.

**PHASE 2 — ÉVÉNEMENTS** : création, modification, publication, page publique, slug, SEO, partage.

**PHASE 3 — BILLETTERIE** : types de billets, stock, holds, commandes, participants, QR Codes signés, billets électroniques.

**PHASE 4 — PAIEMENTS** : Airtel Money, Moov Money, webhooks, confirmation, reçus, remboursements, commission.

**PHASE 5 — CONTRÔLE D'ACCÈS** : application mobile React Native/Expo (périmètre V1 — section 27.17), comptes agents, points d'entrée, scan QR, validation locale, historique, tableau de bord agent, recherche manuelle, mode offline avec base SQLite, synchronisation, résolution de conflit.

**PHASE 6 — INVITATIONS ET PROMOTIONS** : invitations, codes promo, statistiques.

**PHASE 7 — COLLECTES** : campagnes, pages publiques, dons (public/anonyme), historique, transparence, paiements, reçus, remboursement partiel, statistiques, audit, commission.

**PHASE 8 — OPTIMISATION** : exports, analytics, notifications, monitoring, sécurité, tests, performance.

**PHASE 9 — VOTES** *(fonctionnalité future, hors priorité actuelle)* : module de vote (candidats, ouverture/fermeture des votes, résultats, votes gratuits ou payants). Développée après les phases 1 à 8 — détail section 86, [docs/11-votes-futur.md](docs/11-votes-futur.md).

---

## 79. FONCTIONNALITÉS HORS PÉRIMÈTRE

Ne pas ajouter spontanément : cashless, portefeuille électronique, paiement RFID/NFC/bracelet/badge, marketplace globale, réseau social, messagerie, commentaires, témoignages, messages de dons, likes, réactions, crypto, NFT, paris, fonctionnalités bancaires non prévues.

**Le module Votes n'entre pas dans cette liste** : ce n'est pas hors périmètre, c'est une fonctionnalité planifiée en Phase 9, développée après les autres (section 78, section 86).

Toute nouvelle fonctionnalité importante doit être validée avant implémentation.

---

## 80. RÈGLES ABSOLUES — RÉCAPITULATIF (NON NÉGOCIABLES)

> Ce récapitulatif condense les règles déjà détaillées plus haut (sections 9, 15, 21.2, 32-33, 37-51, 70). En cas de doute ou de contradiction apparente, la section détaillée fait foi ; ce récapitulatif sert de check-list rapide avant toute implémentation touchant à ces domaines.

### 80.1 Collectes (détail sections 37-51)

1. Montant collecté et pourcentage calculés automatiquement, jamais saisis manuellement.
2. Seuls les dons confirmés sont comptabilisés.
3. Montant collecté, pourcentage et nombre de dons sont visibles publiquement.
4. Le donateur choisit l'anonymat (identité seulement — le montant reste toujours public).
5. Aucun message de don n'est autorisé (aucun champ prévu en base).
6. L'organisateur ne peut jamais : supprimer/masquer un don confirmé, supprimer un donateur de l'historique, modifier un montant, falsifier une statistique.
7. Les remboursements (totaux ou partiels) sont des opérations traçables, liées au don initial — jamais un `UPDATE` direct.
8. Le frontend n'est jamais la source de vérité financière ; toutes les opérations sensibles sont auditées.
9. La commission (2,5 %) est calculée automatiquement, jamais saisie manuellement.

### 80.2 Billetterie (détail sections 15, 21.2)

**Parcours organisateur** : crée son compte → crée son organisation → crée son événement → configure ses billets → publie → reçoit un lien → partage le lien.

**Parcours participant** : reçoit le lien → ouvre la page → choisit son billet → renseigne ses informations → paie → reçoit son billet.

La commission (5 %) est calculée automatiquement, jamais saisie manuellement.

### 80.3 Cashless (détail sections 32-33)

Hors périmètre. Les badges et bracelets servent uniquement à l'identification et au contrôle d'accès — jamais au paiement.

### 80.4 Multi-tenant (détail section 9)

Une organisation ne peut jamais voir les événements, participants, commandes, transactions, dons, campagnes ou agents d'une autre organisation. Toutes les requêtes backend vérifient le contexte de l'organisation (`EnsureOrganizationContext` middleware) — jamais confiance dans un `organizationId` envoyé par le frontend.

### 80.5 Principe commun à tout le périmètre financier

**La plateforme n'est jamais dépositaire définitive des fonds** (section 21.2) : elle encaisse, retient sa commission, et reverse le net à l'organisateur — sous réserve de la vérification KYC (section 10.1).

### 80.6 Interface (détail section 70.6)

**Aucun emoji nulle part dans le logiciel** — interface, notifications, emails, exports, messages d'erreur inclus. Uniquement des icônes de bibliothèque (Lucide), traits fins, cohérentes avec la palette.

---

## 81. RÈGLES CLAUDE CODE

Avant toute modification importante : analyser le projet, lire le code existant, comprendre l'architecture, identifier les modules concernés, vérifier les conventions, vérifier les dépendances, planifier, implémenter, tester, corriger, vérifier les régressions, documenter si nécessaire.

Ne jamais réécrire inutilement du code fonctionnel. Ne jamais supprimer une fonctionnalité sans justification. Ne jamais ajouter une fonctionnalité majeure non demandée.

---

## 82. DEFINITION OF DONE

Une fonctionnalité est terminée lorsque : frontend terminé, backend terminé, logique métier terminée, permissions terminées, validation serveur terminée, erreurs gérées, loading states terminés, responsive vérifié, tests ajoutés, sécurité vérifiée, isolation multi-tenant vérifiée, aucune régression évidente, documentation mise à jour si nécessaire.

---

## 83. PRINCIPE D'ARCHITECTURE FINAL

```
ORGANISATEUR
   │
   ↓
ORGANISATION
   │
   ┌─────────────┴─────────────┐
   ↓                            ↓
ÉVÉNEMENTS                  COLLECTES
   │                            │
   ↓                            ↓
BILLETTERIE                  DONS
   │                            │
   ↓                            ↓
PARTICIPANTS               DONATEURS
   │                            │
   ↓                            ↓
CONTRÔLE D'ACCÈS           TRANSPARENCE
```

Le produit doit être : sécurisé + multi-tenant + scalable + mobile-first + Mobile Money + transparent + auditable + non dépositaire des fonds.

---

## 84. PHILOSOPHIE DU PRODUIT

**SIMPLICITÉ** — L'organisateur doit pouvoir créer et publier un événement rapidement. Le participant doit pouvoir acheter un billet sans friction. Le donateur doit pouvoir contribuer rapidement.

**TRANSPARENCE** — Une collecte de fonds doit refléter les transactions réelles. Le créateur ne doit pas pouvoir maquiller les résultats. Le montant collecté et la progression doivent être vérifiables.

**CONFIANCE** — Les transactions financières importantes doivent être traçables, auditées, protégées, idempotentes, non modifiables arbitrairement.

---

## 85. RÈGLE FINALE POUR CLAUDE

Toujours garder à l'esprit :

- L'organisateur est le client SaaS. Le participant est l'utilisateur final de la billetterie. Le donateur est l'utilisateur final d'une collecte.
- L'organisateur apporte son audience et partage le lien de son événement ou de sa campagne. La plateforme fournit l'infrastructure technique.
- L'organisateur peut déléguer la gestion opérationnelle à une seule personne de confiance (CO_ORGANIZER), sans lui donner accès aux finances par défaut.
- Le cashless est hors périmètre. Les badges et bracelets servent uniquement à l'identification et au contrôle d'accès.
- Les dons peuvent être anonymes ou nominatifs — seule l'identité est concernée, le montant du don reste toujours public.
- Aucun message n'est associé à un don.
- Le montant collecté et le pourcentage sont publics, calculés automatiquement côté serveur.
- Le créateur d'une collecte ne peut jamais supprimer, modifier ou masquer arbitrairement un don confirmé, ni falsifier les statistiques.
- Les billets sont représentés par un QR Code encodant un token signé, jamais un identifiant brut.
- La plateforme prélève une commission (5 % billetterie, 2,5 % collectes) calculée automatiquement, mais **n'est jamais dépositaire des fonds**.
- Les données financières confirmées constituent une source de vérité immuable et auditable.
- Toute fonctionnalité susceptible de compromettre ces principes doit être refusée ou soumise à validation explicite avant implémentation.

FIN DU CLAUDE.md
