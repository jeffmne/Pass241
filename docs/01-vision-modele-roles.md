# Vision, modèle & rôles

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 1. VISION DU PROJET

Le projet consiste à développer une plateforme SaaS moderne permettant aux organisateurs de gérer :

- leurs événements ;
- leur billetterie ;
- leurs participants ;
- leurs invitations ;
- leurs promotions ;
- leur contrôle d'accès ;
- leurs agents de contrôle ;
- leurs campagnes de collecte de fonds ;
- leurs paiements ;
- leurs statistiques ;
- leurs exports.

La plateforme est principalement destinée au marché gabonais dans sa première version, avec une architecture permettant une expansion future vers d'autres pays.

Les moyens de paiement initiaux sont :

- Airtel Money ;
- Moov Money.

La plateforme doit être conçue comme un véritable SaaS multi-tenant, sécurisé, scalable et mobile-first.

---

## 2. MODÈLE FONDAMENTAL DU PRODUIT

### 2.1 L'organisateur est le client SaaS

Principe absolument fondamental :

> L'organisateur est le client de la plateforme. Le participant, l'acheteur ou le donateur est l'utilisateur final.

Les organisateurs créent un compte afin d'utiliser les services de la plateforme. Ils peuvent ensuite gérer leurs propres événements et campagnes.

```
PLATEFORME SaaS
   │
   ↓
ORGANISATEUR
   │
   ┌────────────────┼────────────────┐
   ↓                 ↓                ↓
ÉVÉNEMENTS       BILLETTERIE      COLLECTES
   │                 │                │
   ↓                 ↓                ↓
Participants     Acheteurs        Donateurs
   │
   ↓
CONTRÔLE D'ACCÈS
```

---

## 3. QUI PEUT CRÉER UN COMPTE ?

**Associations et BDE** — soirées, galas, tournois, événements étudiants, activités associatives, adhésions, collectes de fonds.

**Organisateurs de festivals et concerts** — vente de billets, billets VIP/Premium/Standard/Early Bird, invitations, contrôle des entrées, scan QR Code, gestion des agents, statistiques.

**Entreprises et agences événementielles** — conférences, séminaires, salons, formations, événements professionnels, inscriptions, invitations, gestion des participants.

**Particuliers** — anniversaires, fêtes, événements privés, réservations, participation financière, événements avec capacité limitée.

**Associations / ONG / particuliers** — campagnes de collecte, financement de projets, aides communautaires, projets caritatifs, événements solidaires.

---

## 4. MODÈLE DE DISTRIBUTION

La plateforme n'est **pas** une marketplace publique d'événements.

Principe : l'organisateur crée son événement et partage lui-même le lien. La plateforme fournit la technologie ; l'organisateur apporte son audience.

```
Organisateur → Création événement → Publication → Lien public
→ WhatsApp / Instagram / Facebook / TikTok / Site / Affiche
→ Participant → Page événement → Billetterie
```

---

## 5. PAS DE MARKETPLACE PUBLIQUE PAR DÉFAUT

Ne pas créer par défaut : catalogue global d'événements, marketplace publique, fil d'actualité, réseau social, système de recommandation, moteur de découverte obligatoire, abonnement aux organisateurs.

L'utilisateur n'a pas besoin de rechercher l'événement sur la plateforme ; il reçoit le lien directement de l'organisateur.

---

## 6. ACCÈS À UN ÉVÉNEMENT

Un événement possède une URL publique unique. Exemple : `https://Pass241.app/e/concert-afro-2026`

Le lien peut être partagé via WhatsApp, Instagram, Facebook, TikTok, email, SMS, site web, QR Code, affiches, flyers.

Parcours : Lien partagé → Page publique événement → Choix billet → Formulaire → Paiement → Billet QR Code.

---

## 7. MINI-SITE ÉVÉNEMENTIEL

Chaque événement publié possède une page publique dédiée fonctionnant comme un mini-site, affichant : affiche, titre, description, date, heure, lieu, adresse, programme, informations pratiques, types de billets, prix, disponibilité, formulaire, paiement, confirmation.

L'organisateur peut donc vendre ses billets sans développer son propre système de billetterie.

---

## 8. INTÉGRATION AU SITE DE L'ORGANISATEUR

À terme, permettre : bouton d'achat, widget, iframe si pertinent, intégration API, checkout intégré.

**Mode A — Mini-site** : Lien organisateur → Page plateforme → Billetterie → Paiement (prioritaire pour le MVP)

**Mode B — Site de l'organisateur** : Site organisateur → Widget billetterie → Checkout → Paiement → Billet

---

## 9. ARCHITECTURE MULTI-TENANT

La plateforme doit être multi-tenant dès le départ. Chaque organisation doit avoir une isolation logique stricte.

```
PLATEFORME
├── Organisation A (Users, Events, Tickets, Orders, Agents, Campaigns)
├── Organisation B (Users, Events, Tickets, Orders, Agents, Campaigns)
└── Organisation C (Users, Events, Tickets, Orders, Agents, Campaigns)
```

Une organisation ne doit jamais accéder aux données d'une autre. **Ne jamais faire confiance à un `organizationId` envoyé par le frontend.** Le backend doit déterminer l'organisation depuis l'utilisateur authentifié.

---

## 10. RÔLES

### SUPER_ADMIN
Administrateur global de la plateforme. Peut : gérer les organisations, gérer les utilisateurs, consulter les événements/transactions/collectes, gérer les paramètres globaux, consulter les logs, suspendre une organisation, consulter les statistiques globales.

Le Super Admin ne doit pas pouvoir modifier arbitrairement les transactions financières historiques.

### ORGANIZER
Client SaaS. Peut : gérer son organisation, créer/modifier/publier des événements, créer des billets, gérer commandes/participants/invitations/codes promo/agents/points d'entrée, consulter les revenus, gérer les remboursements selon les règles, créer des campagnes, consulter les dons, exporter les données.

#### 10.1 Vérification (KYC) avant reversement

L'inscription et la création d'événements/campagnes restent **libres** — aucune vérification requise pour publier et commencer à vendre des billets ou collecter des dons.

En revanche, **aucun reversement (section 21.2) ne peut être déclenché tant que l'organisateur n'a pas complété sa vérification d'identité.**

```
Inscription libre → Création événement/campagne → Ventes/dons possibles
                                                          │
                                                          ▼
                                          Reversement bloqué tant que KYC incomplet
                                                          │
                                                          ▼
                                    KYC complété (pièce d'identité + RCCM si entreprise/asso)
                                                          │
                                                          ▼
                                          Reversements débloqués (rétroactivement sur le solde en attente)
```

- Les fonds collectés avant KYC restent comptabilisés normalement (transparence des collectes non affectée, section 37) — seul le **reversement physique** vers l'organisateur est suspendu.
- Statut à prévoir sur `Organization` : `kycStatus` (`PENDING`, `SUBMITTED`, `VERIFIED`, `REJECTED`).
- Documents à prévoir : pièce d'identité pour un particulier, RCCM (ou équivalent) pour une entreprise/association.
- Le dashboard organisateur doit afficher clairement le solde en attente de reversement et le statut KYC, pour inciter à la vérification sans bloquer l'usage du produit.

### CO_ORGANIZER (délégation)
Rôle permettant à un organisateur indisponible (ex: en tournée pour un concert) de déléguer la gestion opérationnelle d'un événement — ou de toute l'organisation — à une seule personne de confiance.

```
CO_ORGANIZER
Peut (scope = un événement ou l'organisation entière, au choix de l'organisateur) :
├── gérer la billetterie (billets, commandes, participants)
├── gérer les invitations et codes promo
├── gérer les agents et points d'entrée
└── modifier l'événement

Ne peut pas (par défaut) :
├── consulter les revenus / finances de l'événement — sauf activation explicite
├── modifier les paramètres de facturation de l'organisation
├── gérer les rôles/accès d'autres utilisateurs de l'organisation
└── supprimer l'organisation ou l'événement
```

**Entité `EventDelegate`**

```
EventDelegate
├── id
├── organizationId
├── eventId (nullable → si null, délégation sur toute l'organisation)
├── userId
├── canViewFinances (boolean, false par défaut)
├── assignedBy
├── assignedAt
└── revokedAt (nullable)
```

Implémentation recommandée via Spatie Laravel Permission avec un scoping par équipe/organisation (`teams`), plutôt qu'un système de permissions granulaires custom.

### MANAGER
Utilisateur secondaire d'une organisation. Ses permissions sont définies par l'organisateur (rôle à permissions plus larges/administratives que CO_ORGANIZER).

### ACCESS_AGENT
Agent chargé du contrôle d'accès. Peut : se connecter à l'application mobile, sélectionner un événement autorisé, sélectionner un point d'entrée, scanner les billets, consulter le résultat, consulter son historique, travailler hors connexion.

Ne peut pas : modifier les prix, modifier les billets, effectuer des remboursements, consulter les revenus, modifier les événements, gérer les campagnes.

### CUSTOMER
Utilisateur final. Peut : acheter un billet, recevoir son billet, consulter/télécharger ses billets, consulter ses reçus, effectuer un don, consulter ses dons.

---

