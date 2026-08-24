# Journal des décisions techniques

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues et le modèle financier priment toujours sur ce fichier en cas de doute.

Ce fichier enregistre les arbitrages qui ne figurent pas dans la spécification fonctionnelle, avec leur date et leur raison. Une décision inscrite ici n'a pas à être rediscutée à chaque module ; si elle doit changer, on l'amende explicitement plutôt que de la contourner en silence.

---

## D-001 — Environnement de développement sous Docker
**2026-08-24**

PHP 8.4, PostgreSQL 16, Redis 7, Mailpit, orchestrés par `docker-compose.yml`.

**Raison** : la machine de développement dispose de PHP 8.2 via XAMPP et d'aucun PostgreSQL, alors que la section 60 impose PHP 8.3+ et PostgreSQL. Docker reproduit fidèlement la production sans toucher à l'installation XAMPP existante.

**Ports hôte** décalés pour éviter les conflits avec des services déjà en écoute sur la machine : PostgreSQL sur `5434` (5432 et 5433 occupés), Redis sur `6380` (6379 occupé). À l'intérieur du réseau Docker, les ports restent standards.

---

## D-002 — Provider de paiement factice pour le développement
**2026-08-24**

Aucun accès Airtel Money ni Moov Money n'est disponible. `PaymentProviderInterface` est développée avec un `FakeProvider` simulant le flux complet de la section 21.1 : push USSD, validation, refus, absence de callback jusqu'au timeout de 5 minutes.

**Raison** : le flux de paiement, l'idempotence des webhooks et le calcul de commission sont la partie la plus risquée du produit et sont partagés entre billetterie et collectes. Les construire et les tester tôt, sans attendre les opérateurs, évite de découvrir les erreurs d'architecture au moment de l'intégration réelle.

**Conséquence** : `PAYMENT_DEFAULT_DRIVER=fake` en développement et en test. Les vrais providers se branchent derrière la même interface, sans réécriture.

---

## D-003 — Montants stockés en entiers XAF
**2026-08-24**

Les montants sont des entiers (`bigint`) exprimés en francs CFA, sans subdivision : 50 000 FCFA est stocké `50000`. Une colonne `currency` est présente dès le départ, figée à `XAF`.

**Raison** : le franc CFA n'a pas de subdivision utilisée en pratique. L'entier supprime toute erreur d'arrondi flottant sur le calcul de commission (sections 21.2, 80.1). La colonne `currency` prépare l'expansion vers d'autres pays annoncée en section 1 sans migration de schéma.

**Interdit** : `float`, `double`, et tout calcul monétaire côté frontend (section 80.1, point 8).

---

## D-004 — Accès à « Mes billets » par lien signé et session longue durée
**2026-08-24**

À la confirmation du paiement, l'acheteur reçoit une URL signée vers son billet et un cookie de session longue durée. Pas d'OTP en V1.

**Raison** : la section 15.1 fait du numéro de téléphone l'identifiant de récupération du billet, mais la section 69 précise que le canal SMS n'est pas activé au lancement et que le fournisseur reste à définir. Sans SMS et sans email obligatoire, rien ne permettait de vérifier que le numéro saisi appartient bien à l'acheteur. Le lien signé lève la contradiction sans dépendance externe et sans imposer l'email, ce qui préserve l'adaptation au marché gabonais voulue en section 15.1.

**Limite assumée** : la perte du terminal sans email renseigné fait perdre l'accès à l'espace « Mes billets ». À reconsidérer le jour où un fournisseur SMS ou WhatsApp Business sera retenu — le passage à l'OTP se fera alors sans remettre en cause le modèle de données.

---

## D-005 — Livewire 4 au lieu de Livewire 3
**2026-08-24**

La section 60 mentionne Livewire 3. Livewire 3 déclare `illuminate/* ^10|^11|^12` et **ne supporte pas Laravel 13**. Version installée : Livewire 4.4.1.

**Raison** : incompatibilité technique, pas un choix. Conserver Livewire 3 imposerait de rétrograder en Laravel 12 et de contredire la section 60 sur un point plus structurant.

**Conséquence** : Alpine.js n'est pas installé séparément, Livewire 4 l'embarque. La syntaxe des composants suit Livewire 4.

---

## D-006 — Guzzle 7 au lieu de Guzzle 8
**2026-08-24**

Laravel 13 installe Guzzle 8 par défaut. Laravel Reverb 1.11 (dernière version) exige `guzzlehttp/psr7 ^2.6`, incompatible avec Guzzle 8. L'installation de Reverb a rétrogradé Guzzle en 7.15.5.

**Raison** : Reverb est requis par la section 60.2 (progression de collecte en direct, statistiques de scan en direct). Guzzle 7 reste pleinement supporté et suffit aux appels HTTP sortants vers Airtel et Moov.

**À surveiller** : remonter en Guzzle 8 quand Reverb publiera une version compatible.

---

## D-007 — Dates stockées en UTC, fuseau d'affichage séparé
**2026-08-24**

`config('app.timezone')` reste `UTC`. Un `config('app.display_timezone')` distinct vaut `Africa/Libreville` et ne sert qu'à la mise en forme destinée à l'utilisateur.

**Raison** : les horodatages financiers (confirmation de paiement, snapshot de commission, scan d'entrée) doivent être non ambigus et comparables. Un fuseau applicatif figé au Gabon deviendrait un piège le jour de l'expansion multi-pays prévue en section 1.

**Interdit** : stocker ou comparer des dates dans le fuseau d'affichage.

---

## D-008 — Tests exécutés sur PostgreSQL, jamais sur SQLite
**2026-08-24**

`phpunit.xml` pointe sur une base PostgreSQL `pass241_testing`, et non sur le SQLite en mémoire par défaut de Laravel.

**Raison** : l'intégrité financière du projet reposera sur des comportements propres à PostgreSQL — contraintes `CHECK`, index uniques partiels, verrous de ligne pour les holds de stock et l'idempotence des webhooks. Un test vert sur SQLite ne prouverait rien sur l'environnement réel. Le surcoût en durée est accepté.

---

## D-009 — Monorepo
**2026-08-24**

Un dépôt unique : `/backend` (Laravel), `/mobile` (React Native, à venir en Phase 5), `/docs` (spécification), `/docker`.

**Raison** : la spécification vit avec le code, et le contrat d'API entre le backend et l'application agent évolue dans le même historique — ce qui compte pour la section 60.4, où l'app agent est un client API pur dont le contrat doit rester stable.

---

## D-010 — Hébergement de production non tranché
**2026-08-24**

Aucune cible retenue. Le code reste agnostique : toute donnée d'environnement passe par des variables (section 73), aucun chemin ni service codé en dur.

**À trancher avant** : la mise en ligne. Le point de vigilance sera Reverb, qui exige un processus long et un WebSocket persistant — contrainte à vérifier avant de choisir une plateforme serverless.

---

## D-011 — Entrypoint Docker corrigeant les droits sur `storage`
**2026-08-24**

L'image PHP a un entrypoint qui applique `chmod -R a+rwX` sur `storage` et `bootstrap/cache` au démarrage du conteneur.

**Raison** : le code est monté depuis Windows, les fichiers appartiennent donc à `root`, alors que les workers PHP-FPM tournent en `www-data`. Sans cet ajustement, Blade ne peut pas écrire ses vues compilées et **toute page pas encore compilée renvoie une erreur 500** (`tempnam(): file created in the system's temporary directory`). Le symptôme est trompeur : les pages déjà compilées par une commande CLI continuent de fonctionner, ce qui laisse croire que l'environnement est sain.

**Portée** : développement local uniquement. L'image de production devra fixer proprement l'utilisateur propriétaire à la construction, sans `chmod` au démarrage.

---

## D-014 — Qualité visuelle adaptative
**2026-08-24**

Les effets coûteux — flou du verre liquide, reflet spéculaire, révélations au défilement — sont désactivés automatiquement sur les appareils modestes. `resources/js/app.js` pose `data-quality="reduced"` sur `<html>` avant le premier rendu, en se fiant à `hardwareConcurrency`, `deviceMemory` et `navigator.connection` (mode économie de données, réseau 2G/3G). Le verre garde alors sa teinte et sa bordure, mais perd son flou.

**Raison** : `backdrop-filter` est l'effet le plus coûteux du web, et la cible prioritaire est le téléphone Android d'entrée de gamme sur connexion mobile (sections 70.4, 72). Sans dégradation, le défilement devient saccadé exactement chez le public que le produit vise en premier. Le doute profite à la fluidité : un effet manquant ne se remarque pas, un défilement saccadé se remarque immédiatement.

**Axe distinct de `prefers-reduced-motion`**, qui reste respecté séparément : on y retire le déplacement spatial mais on garde l'opacité et les changements d'état, sinon l'interface paraît cassée.

---

## D-015 — Aucun `wire:model.live` pour un simple état visuel
**2026-08-24**

Les groupes de boutons radio (type d'organisation) utilisent Alpine en local pour la sélection visuelle, et un `wire:model` différé pour la valeur envoyée au serveur.

**Raison** : `wire:model.live` déclenche un aller-retour réseau à chaque clic, uniquement pour repeindre un bouton. Sur une connexion mobile gabonaise, cela se voit immédiatement sous forme de latence sur un geste qui devrait être instantané.

**Règle générale** : `wire:model.live` est réservé aux cas où le serveur a réellement quelque chose à dire (validation croisée, recalcul de prix, recherche). Jamais pour un état d'affichage.

---

## D-016 — Accordéons et menus en HTML natif
**2026-08-24**

Les blocs dépliables et le menu d'actions de la page publique sont des `<details>`/`<summary>`, pas des composants JavaScript.

**Raison** : ouverts au clavier, annoncés correctement par un lecteur d'écran, fonctionnels même si aucun script ne se charge, et sans un octet supplémentaire à télécharger. La maquette de référence utilisait des `div` avec un chevron décoratif qui ne s'ouvraient pas.

---

## D-012 — Composants Livewire classiques, sans emoji dans les noms de fichiers
**2026-08-24**

`config/livewire.php` : `make_command.type = 'class'` et `make_command.emoji = false`.

**Raison** : Livewire 4 génère par défaut des composants mono-fichier nommés `⚡nom.blade.php`. Deux problèmes. D'abord la règle 80.6 interdit tout emoji dans le logiciel, et un nom de fichier en fait partie. Ensuite la section 61 décrit une arborescence de classes (`Livewire/Public/`, `Livewire/Dashboard/`, `Livewire/Components/`) que le mode mono-fichier ne produit pas.

**Conséquence** : les composants vivent dans `app/Livewire/`, leurs vues dans `resources/views/livewire/`.

---

## D-013 — `vendor/` dans un volume Docker, hors du montage Windows
**2026-08-24**

Le dossier `vendor/` n'est plus monté depuis Windows : il vit dans un volume Docker nommé.

**Raison** : sur un montage de fichiers Windows vers un conteneur Linux, les itérateurs SPL de PHP tronquent silencieusement la lecture des grands dossiers. Mesuré sur le dossier d'icônes Lucide, 2025 fichiers réels :

| API PHP | Fichiers vus |
|---|---|
| `scandir`, `opendir`/`readdir` | 2025 — correct |
| `DirectoryIterator` | 1201 |
| `FilesystemIterator` | 1201 |
| `RecursiveDirectoryIterator` | 1201 |

Symfony Finder, `File::allFiles()`, la génération du classmap par Composer et la découverte de fichiers par de nombreux paquets reposent sur ces itérateurs. Le symptôme observé était une icône déclarée introuvable alors que son fichier existait ; la cause aurait pu produire bien pire, par exemple un classmap incomplet.

**Conséquence** : `composer install` s'exécute dans le conteneur et remplit le volume. Le dossier `vendor/` n'apparaît plus côté Windows, donc l'éditeur perd l'autocomplétion sur les classes du framework. À reconsidérer si cette gêne devient réelle — l'alternative propre serait de déplacer tout le projet dans le système de fichiers WSL2.
