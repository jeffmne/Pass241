# Contrôle d'accès — Application mobile agents

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 27. MODULE CONTRÔLE D'ACCÈS — APPLICATION MOBILE AGENTS

### 27.0 Objectif

La plateforme dispose d'une application mobile dédiée aux agents chargés de contrôler les billets à l'entrée des événements.

Le fonctionnement suit le principe de **WeezAccess (Weezevent)** : le smartphone de l'agent télécharge localement les données nécessaires au contrôle afin de continuer à fonctionner même lorsque la connexion Internet est mauvaise ou inexistante.

**L'application est volontairement simple.** Elle ne sert **pas** à vendre des billets, gérer les événements, gérer les dons ou administrer la plateforme. Elle est exclusivement destinée au contrôle des accès. Toute fonctionnalité hors de ce périmètre reste sur la plateforme web (dashboard organisateur).

### 27.1 Stack mobile

- React Native
- Expo
- TypeScript
- Expo Camera / Barcode Scanner (scan QR)
- SQLite (base de données locale)
- Expo SecureStore (informations d'authentification sensibles)
- NetInfo (détection de l'état de la connexion)
- API REST Laravel (Sanctum) — synchronisation bidirectionnelle

Ce choix tranche la question laissée ouverte en section 60.4 : l'app agent est en **React Native + Expo**, découplée du moteur Livewire, client API pur.

### 27.2 Comptes agents

Les agents disposent de comptes opérateurs distincts des comptes organisateurs, gérés uniquement depuis la plateforme web (jamais depuis l'app mobile).

L'organisateur (ou un CO_ORGANIZER autorisé) peut : créer un agent, modifier un agent, désactiver/supprimer un agent, associer un agent à un ou plusieurs événements, définir les événements/points d'entrée que l'agent peut contrôler, consulter l'activité de l'agent.

Un agent ne doit jamais avoir accès aux fonctionnalités d'administration. Il n'accède qu'aux événements et données qui lui sont attribués.

### 27.3 Authentification

- Connexion sécurisée par email/identifiant + mot de passe
- Authentification via Laravel Sanctum
- Conservation sécurisée de la session (SecureStore)
- Déconnexion, expiration de session
- L'administrateur peut révoquer une session/appareil à distance

Après la première connexion, l'application récupère les événements et données de contrôle auxquels l'agent est autorisé.

### 27.4 Fonctionnement général

```
Connexion agent
      ↓
Récupération des événements autorisés
      ↓
Téléchargement des données nécessaires
      ↓
Stockage local sécurisé (SQLite)
      ↓
Sélection de l'événement
      ↓
Scanner QR
      ↓
Vérification locale
      ↓
Résultat immédiat
      ↓
Enregistrement local du contrôle
      ↓
Synchronisation avec Laravel
```

### 27.5 Synchronisation initiale (téléchargement des données de contrôle)

Lorsqu'un agent sélectionne un événement, l'application télécharge les données nécessaires au contrôle : identifiant du billet, identifiant de commande, identifiant de l'événement, type de billet, statut du billet, informations minimales d'identification et de validation, état d'utilisation.

**Ne jamais télécharger inutilement des données personnelles sensibles** (pas d'email, pas de numéro de téléphone de paiement sur le device agent). L'application ne stocke localement que le strict nécessaire au contrôle.

### 27.6 Scanner QR — écran principal

L'écran principal de l'application est le scanner. Parcours : sélectionner un événement → ouvrir le scanner → présenter le QR code → résultat instantané.

Après chaque scan, l'application revient automatiquement au mode scanner. Le scanner doit être optimisé pour un grand nombre de contrôles rapides et successifs :

```
Présenter le billet → SCAN → Résultat → Laisser entrer / Refuser → SCAN SUIVANT
```

Le nombre de manipulations doit être réduit au minimum.

### 27.7 Validation locale

La validation s'effectue localement pour ne pas dépendre d'une connexion permanente. Le QR code contient le token signé décrit en section 18. L'application vérifie : authenticité (signature), appartenance à l'événement sélectionné, statut du billet, paiement validé, annulation éventuelle, expiration éventuelle, utilisation précédente.

### 27.8 Résultats de contrôle (affichage)

**🟢 Billet valide**
```
BILLET VALIDE — Entrée autorisée
Nom : Jean Dupont
Type : VIP
Événement : Concert Example
Contrôlé à : 19:42
```

**🟠 Billet déjà utilisé**
```
BILLET DÉJÀ UTILISÉ — Entrée refusée
Ce billet a déjà été contrôlé.
Premier contrôle : 19:18
```
Lorsque l'information est disponible, afficher l'agent et/ou l'appareil ayant effectué le premier contrôle.

**🔴 Billet invalide**
```
BILLET INVALIDE — Entrée refusée
Le billet est inconnu, annulé, non payé
ou incompatible avec cet événement.
```

L'interface doit être extrêmement visuelle : l'agent doit comprendre le résultat en moins d'une seconde (code couleur systématique).

### 27.9 Mode hors ligne

Fonctionnalité fondamentale. L'application continue de fonctionner sans Internet (lent, temporairement indisponible, réseau mobile absent, Wi-Fi indisponible). L'agent continue de scanner les billets présents dans sa base locale.

Afficher clairement l'état de connexion : 🟢 En ligne / 🟠 Synchronisation / 🔴 Hors ligne. Le changement d'état réseau ne doit jamais interrompre le contrôle en cours.

### 27.10 Base locale (SQLite)

Stocke : événements téléchargés, billets autorisés, état local des billets, contrôles effectués, opérations en attente de synchronisation, informations de synchronisation. Ces données doivent être protégées autant que possible contre l'accès non autorisé.

### 27.11 Synchronisation

Toutes les validations locales sont enregistrées dans une file de synchronisation :

```
SCAN → Validation locale → Enregistrement SQLite
→ File de synchronisation → Connexion disponible
→ Envoi à Laravel → Confirmation serveur → Marqué comme synchronisé
```

Synchronisation automatique + synchronisation manuelle possible. Afficher : dernière synchronisation, nombre d'opérations en attente, état, erreurs éventuelles.

### 27.12 Gestion des conflits

Chaque contrôle possède un identifiant unique / idempotency key. **Le serveur Laravel est toujours l'autorité finale.** Si un billet est déjà marqué `USED` côté serveur, toute nouvelle tentative est refusée à la synchronisation. Voir aussi la règle de résolution par timestamp en section 31.1 pour le cas spécifique du double scan simultané offline.

### 27.13 Plusieurs agents simultanés

Un même événement peut être contrôlé par plusieurs agents en parallèle, chacun affecté à un point d'entrée (section 29) :

```
Concert Example
Agent 01 → Entrée principale
Agent 02 → Entrée VIP
Agent 03 → Entrée secondaire
Agent 04 → Entrée invités
```

Chaque contrôle enregistre : billet, événement, agent, appareil, date, heure, statut, identifiant de synchronisation.

### 27.14 Tableau de bord agent

Simple, affiche pour l'événement sélectionné : nombre de billets contrôlés, valides, invalides, déjà utilisés, opérations en attente de synchronisation, état de connexion, dernière synchronisation.

```
CONCERT EXAMPLE
1 248 contrôlés
🟢 1 210 valides   🔴 21 invalides   🟠 17 déjà utilisés
📶 En ligne   🔄 Synchronisé il y a 12 secondes
```

### 27.15 Historique des contrôles et recherche manuelle

L'agent consulte les derniers contrôles (heure, billet, type, résultat) :

```
19:42  Jean Dupont      VIP       🟢
19:41  Marie N.         Standard  🟢
19:40  Ticket #4582     Standard  🔴
19:39  Paul M.          VIP       🟠
```

Si le QR code est illisible, recherche manuelle par numéro de billet, code du billet, ou éventuellement nom du participant — fonctionne aussi hors ligne sur les données locales.

### 27.16 Sécurité de l'application agent

Aucun accès aux fonctionnalités d'administration, permissions limitées aux événements assignés, tokens sécurisés, données locales minimisées, communication HTTPS uniquement, validation côté serveur systématique, logs d'activité, révocation des appareils/sessions, protection contre la réutilisation d'un billet, idempotency keys pour les validations. **Le serveur Laravel reste toujours l'autorité finale — aucune confiance accordée uniquement aux données envoyées par le mobile.**

### 27.17 Périmètre V1 — obligatoire

Connexion agent, liste des événements autorisés, sélection d'un événement, téléchargement des données de contrôle, scanner QR, validation locale, résultat valide/invalide/déjà utilisé, enregistrement des scans, synchronisation avec Laravel, indicateur en ligne/hors ligne, historique des contrôles, recherche manuelle par numéro, compteurs de contrôle, gestion de plusieurs agents, sécurité des sessions.

### 27.18 Hors périmètre V1

Ne pas ajouter dans l'app mobile : vente de billets, gestion des événements, gestion des dons, gestion financière, création d'agents depuis l'application, statistiques avancées, notifications complexes, fonctionnalités sociales, ou toute fonctionnalité inutile au contrôle des entrées. Ces fonctionnalités restent dans la plateforme web principale.

### 27.19 Principe UX

Contexte réel : l'agent doit contrôler des centaines ou milliers de personnes rapidement. Priorités, dans l'ordre : rapidité du scan, lisibilité du résultat, fonctionnement hors ligne, fiabilité, synchronisation automatique, simplicité de l'interface, sécurité.

---

## 28. VALIDATION D'UN BILLET

**Billet valide** → "BILLET VALIDE — Jean Dupont — VIP — ENTRÉE AUTORISÉE"
**Billet invalide** → "BILLET INVALIDE — QR Code non reconnu — ENTRÉE REFUSÉE"
**Déjà utilisé** → "BILLET DÉJÀ UTILISÉ — Jean Dupont — VIP — ENTRÉE REFUSÉE"
**Remboursé** → "BILLET ANNULÉ — ENTRÉE REFUSÉE"

---

## 29. POINTS D'ENTRÉE

Un événement peut avoir : entrée principale, entrée VIP, entrée invités, entrée secondaire, autres entrées.

Chaque point possède : nom, description, statut, agents affectés.

---

## 30. AGENTS DE CONTRÔLE

L'organisateur peut : inviter un agent, activer/désactiver, affecter à un événement, affecter à un point d'entrée, consulter l'activité.

Un agent ne voit que les événements auxquels il est affecté. Un agent est affecté à un point d'entrée par session de scan, mais peut être réaffecté par l'organisateur à un autre point ou événement sans recréer son compte.

---

## 31. MODE HORS CONNEXION

Le scanner doit fonctionner avec une connexion faible ou absente.

```
Application mobile → Stockage local sécurisé → Queue locale
→ Synchronisation → API → Base centrale
```

Prévoir : synchronisation automatique, gestion des doublons, gestion des conflits, protection contre les validations concurrentes, historique local.

### 31.1 Résolution de conflit — double scan offline

Règle : **le scan avec le timestamp local le plus ancien l'emporte.**

```
Device A scanne à 20:03:12 (offline)
Device B scanne à 20:03:45 (offline)
→ Synchronisation (ordre d'arrivée réseau indifférent)
→ Comparaison des timestamps locaux
→ 20:03:12 → ticket marqué USED
→ 20:03:45 → rejeté, conflit journalisé, agent B voit "BILLET DÉJÀ UTILISÉ"
```

Limite connue à documenter : cette règle suppose une horloge device fiable — prévoir une synchronisation NTP au démarrage de l'app agent, et un warning si l'horloge est anormalement désynchronisée par rapport au serveur.

Chaque scan (accepté ou rejeté) est journalisé dans `AccessScan` avec : `deviceId, agentId, scannedAt (local), syncedAt (serveur), result, conflictWith (nullable)`.

La stratégie offline doit être définie précisément avant la production.

---

## 32. BADGES ET BRACELETS

Servent uniquement à : identification, catégorisation, contrôle d'accès. Jamais au paiement.

---

## 33. CASHLESS HORS PÉRIMÈTRE

Le cashless est explicitement exclu du projet actuel. NE PAS développer : portefeuille, solde, recharge, paiement RFID, paiement NFC, paiement bracelet, paiement badge, portefeuille événementiel.

---

