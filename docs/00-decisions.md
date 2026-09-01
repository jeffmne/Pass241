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

## D-018 — Jeton de billet signé par HMAC, clé dédiée
**2026-08-25**

Le QR Code encode `1.<identifiants>.<signature>` — quatre identifiants et une signature HMAC-SHA256 tronquée à 128 bits. Clé dédiée `TICKET_TOKEN_SIGNING_KEY`, jamais `APP_KEY`.

**Raison de la clé dédiée** : une rotation de la clé applicative — opération de routine — invaliderait sinon tous les billets déjà vendus et parfois imprimés.

**Raison de la troncature** : chaque caractère ajoute des modules au QR, donc de la difficulté à scanner sous un mauvais éclairage. 128 bits restent hors de portée d'une falsification.

**Ce que le jeton ne contient pas** : aucun nom, aucune adresse, aucun prix. Un QR Code se photographie, se partage et s'affiche sur un écran verrouillé.

**Ce que le jeton ne dit pas** : si le billet est encore valide. Le statut évolue après l'émission — remboursement, scan — et un jeton ne peut pas porter une information changeante. La vérification finale reste en base (section 18.1).

**Limite pour le module mobile** : la section 18 autorise HMAC « au minimum », mais évoque une signature asymétrique pour la vérification hors connexion sans exposer le secret sur le terminal de l'agent. Avec HMAC, un agent hors ligne devrait détenir la clé. Le préfixe de version permet d'ajouter une branche asymétrique sans invalider les billets en circulation — à trancher au jalon du contrôle d'accès.

---

## D-019 — Le PDF reprend le dessin du billet, sans le verre
**2026-08-25** — *complétée par les décisions D-021 et D-022 le même jour : le PDF n'est plus téléchargeable depuis la plateforme, et les contraintes du moteur de rendu y sont mesurées.*

Le PDF reprend la composition de l'écran « Mon billet » : bandeau de marque, bannière de l'événement avec le nom en surimpression, informations, ligne de déchirure à encoches, QR sur encart blanc.

**Ce qui change, et pourquoi.** Le moteur de rendu PDF ne connaît ni flexbox, ni `backdrop-filter`, ni les dégradés du verre liquide. La composition est refaite en positionnement absolu et en tableaux — seule technique donnant un résultat prévisible sur A4.

Le verre liquide, lui, n'a aucun équivalent imprimable : il repose sur ce qu'il y a **derrière** lui, et une feuille de papier n'a pas d'arrière-plan. La silhouette, les encoches et la palette sont conservées ; la matière cède la place au contraste.

La bannière est intégrée en `data:` URI plutôt que référencée par URL : le moteur devrait sinon la chercher par le réseau, ce qui suppose qu'il puisse joindre l'application depuis l'intérieur du conteneur — et ferait échouer le téléchargement en silence sinon.

---

## D-065 — `random_page_cost` à 1.1 : le réglage qui décide des plans
**2026-09-01**

PostgreSQL démarre avec `random_page_cost = 4.0`, une valeur calibrée pour des **disques à plateaux**, où une lecture aléatoire coûtait quatre fois une lecture séquentielle. Sur SSD l'écart est marginal, et 1.1 est la valeur retenue partout ailleurs. Elle est désormais posée sur le service `postgres` du `docker-compose.yml`.

**Ce n'est pas un réglage cosmétique : il décide des plans d'exécution.** La décision D-064 s'était arrêtée sur un mur — un index couvrant pour `StockLedger::sold()` gagnait un tiers du temps à 200 000 commandes, puis le planificateur l'abandonnait à 500 000 au profit d'une jointure par fusion parcourant l'index entier. J'avais conclu que l'index n'était pas le levier, sans identifier lequel l'était.

C'était celui-ci. Mesuré à 500 000 commandes, sans aucun index supplémentaire :

| Requête | `4.0` | `1.1` |
|---|---|---|
| `StockLedger::sold()` | 31,7 ms (jointure par fusion) | **13,7 ms** (boucle imbriquée) |
| `StockLedger::reserved()` | 0,122 ms | **0,045 ms** |
| Articles d'une commande | 0,048 ms | 0,050 ms |
| Comptage large d'une organisation | 21,7 ms | 21,2 ms |

**Le gain croît avec le volume**, et c'est ce qui compte plus que le facteur 2,3 : la boucle imbriquée suit les ventes du type de billet, là où la jointure par fusion suivait le nombre total de commandes de la plateforme. `sold()` s'exécute au rendu de **chaque page publique d'événement**, une fois par catégorie de billet.

Aucune régression sur les deux autres formes testées : un parcours large reste un parcours large, et une recherche déjà servie par un index ne change pas de plan.

**Tout déploiement doit porter le même réglage.** Un serveur géré ailleurs que par ce `docker-compose` — base managée, machine dédiée — revient à 4.0 par défaut et perd silencieusement ce gain. Rien ne casse, tout ralentit : c'est la forme de régression la plus difficile à remarquer.

**Pas de test.** Un réglage de serveur n'est pas un comportement de l'application ; un test qui lirait `SHOW random_page_cost` échouerait chez quiconque exécute la suite sur un PostgreSQL configuré autrement, sans qu'aucun code soit en cause.

---

## D-064 — Index des chemins chauds, et ce que la mesure a démenti
**2026-09-01**

**PostgreSQL n'indexe pas les colonnes de clé étrangère.** Il crée un index sur la colonne *référencée* — une clé primaire, déjà indexée — et rien du tout sur celle qui référence. Vingt-neuf colonnes du schéma étaient dans ce cas. La plupart sans conséquence ; quatre sur les requêtes les plus fréquentes du produit.

**Le défaut est invisible en développement**, et c'est ce qui le rend dangereux : sur cent lignes le planificateur préfère de toute façon un parcours séquentiel, et tous les tests passent. Il n'apparaît qu'en production, progressivement, sur les requêtes les plus fréquentes — au pire endroit et au pire moment.

**Mesuré sur 200 000 lignes synthétiques.** `SELECT * FROM order_items WHERE order_id = ?` faisait un **parcours séquentiel complet** : 10,7 ms, 199 999 lignes écartées par le filtre. Avec l'index : 2,5 ms, et surtout un coût qui cesse de croître avec le nombre total de commandes de la plateforme. Cette requête s'exécute à l'ouverture de chaque commande, à chaque confirmation de paiement (`guardPaymentConfirmation` parcourt les articles), à chaque émission de billets et sur l'export des commandes.

Trois autres index l'accompagnent : `tickets (organization_id, status)` — sans lui, le tableau de bord d'un organisateur parcourt les billets de *toute* la plateforme pour n'en garder que les siens ; `invitations (order_id)` ; et un index **partiel** sur `payment_webhooks … WHERE processed_at IS NULL`, dont la table est en ajout seul et ne cesse jamais de grandir — l'index, lui, reste minuscule à jamais.

**Ce que la mesure a démenti, et qui n'est donc pas livré.** `StockLedger::sold()` joint les articles aux commandes pour ne compter que celles qui sont payées. À 200 000 commandes, un index couvrant `orders (id) INCLUDE (status)` faisait basculer le plan en boucle imbriquée et gagnait un tiers du temps — 33 ms à 22 ms. **À 500 000 commandes, le planificateur l'abandonne** : il repasse à une jointure par fusion qui parcourt l'index en entier, et le gain se réduit à presque rien.

L'index n'est donc pas le levier ; le coût du parcours aléatoire dans le modèle de coûts de PostgreSQL l'est — `random_page_cost` vaut encore 4.0, une valeur calibrée pour des disques à plateaux, là où un SSD justifie 1.1. Livrer cet index aurait donné l'illusion d'avoir traité le sujet. Il reste consigné comme point ouvert plutôt qu'à moitié corrigé.

**Un test relit le schéma** et signale toute nouvelle clé étrangère ajoutée sans index sur une table chaude, avec une liste d'exceptions assumées et motivées. Sans lui, le même défaut se reproduirait à la prochaine migration, avec la même discrétion.

---

## D-063 — Deux surveillances distinctes : la sonde réveille, l'écran renseigne
**2026-09-01**

La supervision de la plateforme (section 72) se sépare en deux, et cette séparation décide de **qui est réveillé la nuit**.

`GET /sante` répond à « le service peut-il travailler ? ». Elle vérifie PostgreSQL, le cache et Redis par un aller-retour **réel** — lire une variable de configuration ne prouve rien, elle est renseignée que le service réponde ou non. Elle rend 200 ou **503**, jamais 500 : le code doit dire « indisponible, revenez » et non « cette page a un bug », les deux se traitant différemment en supervision comme derrière un répartiteur de charge.

Elle complète `/up` sans la remplacer. Celle de Laravel dit que le **processus** est vivant — c'est ce qu'un orchestrateur interroge pour redémarrer un conteneur, et elle doit rester légère. Une application qui démarre parfaitement mais ne joint plus PostgreSQL répond 200 sur la première et 503 sur la seconde.

L'écran **Supervision** de la console d'administration répond à « qu'est-ce qui est resté en chemin ? » : callbacks de paiement reçus et jamais traités, traitements en échec, reversements dans un état indécidable.

**Ce qui a tranché le découpage.** Ma première version comptait les jobs en échec dans la sonde de santé. Un email d'invitation qui ne part pas aurait alors fait sonner une alerte de panne — pour quelque chose qui n'empêche personne d'acheter un billet. Une alerte qui se déclenche pour rien finit par ne plus être lue, et c'est alors la vraie panne qu'on rate. Un test tient désormais cette frontière : un job en échec laisse `/sante` à 200 et apparaît sur l'écran.

**La panne la plus coûteuse est la première listée** : un callback reçu et jamais traité. L'argent a quitté le téléphone de l'acheteur, l'opérateur nous a prévenus, et la commande est restée en attente. Rien dans le produit ne le signalait — il fallait que l'acheteur écrive pour qu'on l'apprenne. Cinq minutes de grâce laissent à la file le temps de faire son travail avant de crier.

**Aucune action sur cet écran, volontairement.** Rejouer un callback risque d'encaisser deux fois ; marquer un incident réglé sans l'avoir réglé ne fait que le rendre invisible. Ces gestes se font à la main, en connaissance de cause.

**Aucun cache non plus** : la valeur du rapport tient à sa fraîcheur, et un compteur d'incidents en retard de cinq minutes est un compteur en qui on cesse de croire.

---

## D-062 — Les gardes d'écran s'exécutent à chaque requête, plus seulement au montage
**2026-09-01**

Toutes les autorisations d'écran passent des traits `RequiresPermission` (dashboard) et `SuperAdminOnly` (console d'administration), qui posent leur garde dans le hook Livewire `booted` au lieu de `mount`.

**Le défaut.** `mount` ne s'exécute qu'au premier chargement d'une page. Livewire ne le rappelle jamais ensuite : un clic poste sur `/livewire/update`, qui réhydrate le composant depuis son instantané et appelle directement la méthode demandée. Une garde posée dans `mount` ne protège donc que l'ouverture de l'écran, jamais sa durée de vie.

Tant qu'un écran ne fait que lire, la conséquence est mince. Mais ces écrans **écrivent**, et leurs méthodes d'écriture ne portaient pour la plupart aucune garde propre — elles s'appuyaient entièrement sur celle du montage. Deux tests l'ont établi avant toute correction : un membre dont l'organisateur venait de retirer les droits créait encore un **tarif de billet** et une **catégorie de concours**, tant que son onglet restait ouvert.

**Le même défaut, en pire, sur la console d'administration.** Elle est protégée par le middleware `EnsureSuperAdmin` sur ses routes — or Livewire ne rejoue sur `/livewire/update` qu'une liste blanche de middlewares, dont celui-ci ne fait pas partie. Et aucun des sept écrans ne se gardait lui-même. Quelqu'un dont le statut de Super Admin venait d'être retiré gardait donc la main sur toute la console, **y compris sur l'écran qui fixe les taux de commission de la plateforme** (section 58). Un test le reproduisait : rétrogradation après montage, puis `save()` sur le taux billetterie — accepté.

**Une règle d'autorisation qui dépend de la durée de vie d'un onglet n'en est pas une.** C'est ce qui a tranché, et non la probabilité du scénario.

**Deux protections qui se recouvrent, volontairement.** `EnsureSuperAdmin` rejoint la liste blanche persistante de Livewire — il couvre la requête ; le trait couvre le composant. Un futur écran d'administration ajouté hors du groupe de routes resterait couvert par le second.

**Ce qui n'a pas changé** : les gardes plus strictes propres à une opération restent à leur place. Consulter les remboursements demande `finances.view`, en trancher un demande en plus `finances.refund` (section 24.1). Les traits posent le plancher, ils ne remplacent pas ces vérifications.

---

## D-061 — Le grand livre des transactions, et la règle de signe des remboursements
**2026-09-01**

L'organisateur a désormais un écran **Finances → Transactions** qui réunit les trois modules — billetterie, collectes, votes — dans un seul journal, avec l'export correspondant (`TransactionsExport`). Aucune nouvelle table : la table `commissions` porte déjà les trois par une relation polymorphe (décision D-041), il n'y avait qu'à la lire dans l'ordre du temps.

**Le problème que ce travail a mis au jour.** `commissions` est en ajout seul (section 46) : un remboursement n'y corrige pas la ligne d'origine, il ajoute une ligne d'ajustement — et cette ligne porte un montant **positif**, parce qu'elle décrit un mouvement de retour, pas un solde. Un `SUM` naïf compte donc l'encaissement puis le remboursement comme deux recettes. Un organisateur ayant encaissé 10 000 F et remboursé la totalité lirait 20 000 F de chiffre d'affaires. Le scope `originals()` évite l'erreur inverse — ne jamais déduire les remboursements — mais aucun des deux ne donne le chiffre juste à lui seul.

C'est une erreur **silencieuse** : elle ne casse rien, elle ment, et elle ment précisément le jour où quelqu'un vient vérifier pourquoi il a reçu moins que prévu.

**La règle juste — origines moins ajustements — est donc écrite une seule fois**, dans `TransactionLedger`, sous forme d'un `CASE` SQL d'un seul tenant. L'écran et l'export la lisent au même endroit ; les laisser recalculer chacun de leur côté aurait produit tôt ou tard un export qui contredit l'écran, et c'est l'export qu'on garde.

**Dans le fichier, les ajustements sortent en négatif** alors qu'ils sont positifs en base. Quelqu'un va sélectionner la colonne « Net » dans un tableur et lire le total en bas de son écran : ce total doit être le bon sans qu'il ait à savoir qu'il y avait quelque chose à soustraire. La colonne « Nature » dit lequel des deux on lit, pour que le signe ne soit jamais à interpréter.

**Faille refermée au passage.** L'écran des remboursements ne vérifiait que le droit de *trancher* (`finances.refund`), jamais celui de *regarder*. La route ne porte aucune permission et le menu masquait l'entrée — mais un menu qui cache n'est pas un verrou : l'adresse restait atteignable en la tapant, et un co-organisateur y lisait des montants et les numéros de téléphone des acheteurs (section 10). `finances.view` est désormais exigée au montage, comme sur tous les autres écrans.

**Écarté** : une entrée « Rapports » séparée. L'export se propose là où les chiffres sont lus — c'est en regardant un panneau qu'on décide de l'emporter, pas en se rendant dans un écran dédié qu'il faut d'abord penser à ouvrir.

---

## D-060 — Les termes d'une catégorie se figent au premier bulletin, pas à l'ouverture
**2026-09-01**

Point laissé ouvert au jalon 9.2, refermé maintenant. Gratuité, prix unitaire, plafond, fenêtre et visibilité d'une catégorie restaient modifiables jusqu'à l'ouverture du vote ; ils le restent désormais **tant qu'aucun bulletin n'existe dans cette catégorie**.

**Pourquoi l'ancien seuil était trop large.** Un organisateur qui ouvre son concours, s'aperçoit que le prix du vote comporte un zéro de trop et n'a encore aucun votant devait annuler tout le scrutin et le recréer — en perdant le lien déjà partagé. Le coût était réel, le bénéfice nul.

**Le seuil retenu suit le précédent de la billetterie**, où un type de billet reste modifiable et supprimable tant que rien n'a été vendu, même sur un événement publié. C'est ce précédent qui a tranché, plutôt qu'un arbitrage neuf : ce qui engage n'est pas l'annonce, c'est l'acte. Tant que personne n'a voté, personne n'a rien décidé sur la foi de ces termes. Dès le premier bulletin ils se figent — quelqu'un qui a payé 500 francs son vote l'a fait sous ces règles-là.

**Par catégorie, et non par scrutin.** Les catégories sont indépendantes (section 86.4) : un vote dans le prix du public n'a aucune raison de verrouiller le tarif du titre principal.

Le bandeau de l'écran dit désormais la vraie raison — « des votes ont été exprimés dans cette catégorie » — au lieu de « le vote est ouvert », qui était devenu faux.

---

## D-059 — Un classement proclamé se fige, le reste se calcule
**2026-09-01**

Jalon 9.5. `poll_results` est **la seule table du module Votes qui stocke un nombre de voix**, et c'est une exception assumée à la règle qui l'interdit partout ailleurs.

**Pourquoi l'exception.** Pendant le scrutin, aucun total n'existe en base : tout est agrégé en SQL sur les bulletins, précisément pour qu'il n'y ait rien à falsifier (section 86.5). Mais un classement **publié** doit cesser de bouger. Un remboursement arrivé après la clôture retire de l'argent, et un recalcul ferait alors varier un revenu déjà annoncé — voire, si un bulletin changeait de statut, un rang déjà proclamé. Un concours dont le gagnant change trois jours après l'annonce n'est plus un concours.

L'instantané ne remplace donc pas le calcul, il le **conserve**. Avant la clôture le classement se calcule ; après, il se lit.

**En ajout seul**, comme les commissions : deux déclencheurs PostgreSQL refusent toute modification et toute suppression, y compris depuis une commande artisan ou psql.

**Le nom du candidat est recopié** dans l'instantané. Si l'organisateur corrige une orthographe après la clôture, le rapport archivé garde le nom sous lequel le public a voté.

**Les candidats désactivés y figurent**, à leur rang. Les retirer ferait mentir les totaux et donnerait le moyen d'effacer un candidat gênant après coup.

**L'écran ne confond jamais les deux états** : bandeau « classement en cours, n'annoncez pas de gagnant » tant que rien n'est figé, bandeau « définitif » ensuite. Le rapport exporté porte la même distinction dans une colonne — un fichier téléchargé pendant le vote ne doit pas circuler comme s'il était le résultat.

La tâche `pass241:close-polls` tourne à l'heure, et non chaque nuit : un concours se termine souvent à une heure précise, et le public vient lire le résultat dans la foulée. Elle est rejouable — l'unicité en base fait qu'une seconde exécution n'écrit rien — et balaye tous les scrutins clos, y compris ceux clos à la main.

---

## D-058 — Le callback fait foi sur les frais, pas `get-fees`
**2026-09-01**

**Corrige la décision D-057**, prise la veille avec une information incomplète.

Une fois le callback PVit reçu pour de bon, les deux composantes de frais sont devenues lisibles ensemble — `fees` et `operatorFees`. Mesuré sur six transactions réelles :

| Opérateur | `fees` | `operatorFees` | Taux réel |
|---|---|---|---|
| Airtel | 2,5 % | 1,025 % | **3,525 %** |
| Moov | 2,5 % | 0 % | **2,500 %** |

**`get-fees` annonce 3,5 % sur Airtel : il se trompe de 0,025 point.** L'écart est infime par transaction — 1,25 F sur 5 000 — mais systématique et toujours dans le même sens : un manque à combler sur le compte marchand.

Airtel passe donc de 350 à **353** points de base. 352,5 n'est pas un entier, et arrondir au supérieur est la seule direction qui ne crée jamais de découvert : le payeur avance quelques centimes de plus que le prestataire ne prend, ce qui est sans conséquence — l'inverse ne l'est pas. Vérifié sur toute la plage mesurée, l'écart reste positif partout.

Moov ne bouge pas : sans part opérateur, `get-fees` y était juste.

**Ce que cette décision enseigne au-delà de PVit** : une API de devis n'est pas une source d'autorité sur ce qui sera réellement prélevé. Seule la transaction aboutie l'est. Un barème doit être relevé sur des callbacks réels, opérateur par opérateur, avant toute mise en production.

`amountCredited` confirme au passage que l'organisateur reçoit exactement le sous-total annoncé, frais exclus — ce que promet la décision D-023.

---

## D-057 — Le barème PVit dépend de l'opérateur, et `get-fees` fait foi
**2026-08-31** — *taux Airtel corrigé le lendemain par la décision D-058 : le callback a montré une seconde composante de frais.*

Branchement du driver PVit. Deux choses ont été mesurées sur le compte de test plutôt que supposées, et toutes deux contredisaient une hypothèse du code.

### Le barème n'est pas propre au prestataire, mais à l'opérateur

`PspFeeCalculator` indexait les frais par **pilote** (`fees.fake`, `fees.pvit`). PVit prend **3,5 % sur Airtel Money et 2,5 % sur Moov Money** : un barème unique aurait surfacturé les uns ou sous-facturé les autres, et la sous-facturation se paie en découvert sur le compte marchand.

Le calculateur accepte désormais un opérateur. Les trois créateurs de transaction — commande, don, bulletin — le lui transmettent. Quand il n'est pas encore connu (un écran affiche le total avant le choix), **le barème le plus cher est retenu** : annoncer moins que ce qui sera débité est la seule erreur qui se voit sur le relevé du client.

### `get-fees` donne le total, l'API de statut n'en montre qu'une part

Un écart apparent avait été diagnostiqué — nous semblions surfacturer de 5 F sur 500 F. **Ce diagnostic était faux**, et c'est la mesure qui l'a montré :

|  | `get-fees` | `fees` du statut | écart |
|---|---|---|---|
| Airtel | 3,5 % | 2,5 % | 1 point |
| Moov | 2,5 % | 2,5 % | aucun |

Moov concorde exactement ; Airtel diffère d'exactement un point. PVit facture **deux composantes** — sa part et celle de l'opérateur — et l'API de statut ne renvoie que la sienne. L'exemple de callback de leur documentation le confirme : `amount 150 + fees 3,5 + operatorFees 1,5 = totalAmount 155`.

**`get-fees` fait donc foi** pour ce que le payeur voit partir de son téléphone, et les taux configurés sont justes. `PvitProvider::debitedAmount()` ne recompose un montant que lorsque PVit le donne complet ; à défaut il rend `null`, pour que l'appelant sache qu'il ne sait pas plutôt que de recevoir un nombre faux.

### Ce que la documentation PVit dit de travers

Trois écarts relevés en tapant sur l'API, tous consignés dans [docs/12-pvit-paiements.md](12-pvit-paiements.md) :

1. L'API de statut attend **notre référence marchande** dans le paramètre `transactionId`, et non l'identifiant `PAY…` que la doc montre en exemple.
2. Les références doivent être **purement alphanumériques** — les nôtres portent des tirets, elles partent compactées.
3. L'identifiant de transaction s'appelle `reference_id` à l'initiation et `transactionId` dans le webhook.

---

## D-056 — La page de vote ne dit plus que le résultat est indicatif
**2026-08-31**

Demandé par Jeff. La phrase « Le résultat reste indicatif — nous limitons les votes multiples sans pouvoir les empêcher totalement » est retirée de la page publique. Ce qui reste explique à quoi sert le numéro : compter une fois, ne jamais être conservé en clair.

**C'est un assouplissement de la décision D-047**, et il faut le dire : cette décision faisait de l'annonce dans l'interface la contrepartie explicite d'assumer la faiblesse du vote gratuit — « à condition que l'interface le DISE ». Un test la verrouillait ; il est remplacé par un test sur la mention qui subsiste.

**Ce qui a été mis en balance.** La phrase jetait un doute sur le concours au moment précis où le visiteur s'apprête à voter, et cette hésitation a un coût réel pour l'organisateur, qui est le client. En face : un concours dont le résultat serait contesté après coup se retourne contre l'organisateur autant que contre nous.

**Ce qui n'a pas bougé, et qui compte davantage** : la faiblesse elle-même est traitée par le mécanisme, pas par la mention. Le plafond par personne est tenu sur le numéro (décision D-055), les bulletins sont immuables, l'adresse IP reste consignée pour l'analyse d'abus, et aucun compteur de votes n'existe en base. Le produit ne promet toujours rien qu'il ne tienne — il cesse simplement de commenter sa propre limite sur l'écran de vote.

---

## D-055 — Le numéro, et non le nom, porte le « un vote par personne »
**2026-08-31**

**Remplace la décision D-053**, prise le même jour. Jeff a tranché pour « par personne » et m'a laissé choisir l'identifiant. Le numéro de téléphone est redemandé dès qu'une catégorie pose un plafond ; sans plafond, rien n'est demandé.

### Pourquoi pas le nom

Un nom n'est pas un identifiant, c'est une étiquette. Il échoue **dans les deux sens à la fois** :

- on le contourne en tapant « Marie 2 » — coût de la fraude : zéro seconde ;
- il bloque des votants honnêtes, puisque deux Jean Obame existent réellement ;
- il n'est pas normalisable : « J. Obame », « jean obame » et « Jean Obame » sont trois identités pour une seule personne.

### Pourquoi pas l'empreinte d'appareil seule

C'était la décision D-053. Elle achetait la fluidité au prix d'un **faux positif inacceptable** : au Gabon, une famille ou un bureau passe souvent par le même partage de connexion. Deux personnes avec le même modèle de téléphone y partagent une empreinte, et la seconde se voit refuser un vote légitime. Refuser un votant réel est pire que laisser passer un doublon.

### Pourquoi le numéro

C'est le seul des trois qui se **normalise** en forme canonique — le code le fait déjà : indicatif 241 et zéros initiaux retirés — et le seul qui **coûte quelque chose à multiplier**, puisqu'il faut acheter une puce.

Ce n'est pas une garantie d'unicité, et la décision D-047 ne prétend pas le contraire : c'est un **coût de fraude**, et c'est tout ce qu'on peut tenir sans OTP SMS. La différence avec le nom n'est pas de nature mais d'ordre de grandeur, et elle suffit.

Argument décisif pour ce marché : c'est déjà l'identité que le produit utilise partout ailleurs — mobile money, billetterie. Le votant ne découvre pas une exigence nouvelle.

### Ce qui ne change pas

Le numéro n'est **jamais stocké en clair** et son empreinte reste salée par catégorie (décision D-048). L'adresse IP continue d'être consignée sur chaque bulletin : elle ne porte plus le plafond, mais elle reste ce qui permet de constater après coup qu'une centaine de numéros ont voté depuis une seule connexion.

L'interface revient à « un vote par personne », qui redevient vrai.

---

## D-053 — Le vote gratuit ne demande plus de numéro : l'IP et l'appareil suffisent
**2026-08-31** — *remplacée le jour même par la décision D-055 : l'empreinte d'appareil refusait des votants légitimes derrière un partage de connexion.*

Demandé par Jeff. Le numéro de téléphone était exigé dès qu'une catégorie posait un plafond. Il ne l'est plus pour aucun vote gratuit ; le votant est compté sur son adresse IP et son navigateur, tous deux déjà enregistrés sur chaque bulletin.

**Raison** : il coûtait plus qu'il ne rapportait. C'est le champ qui fait abandonner — une donnée personnelle demandée pour un geste gratuit qui prend une seconde. Et il ne garantissait rien : rien n'empêche de saisir cinq numéros, ce que la décision D-047 assumait déjà en qualifiant le vote gratuit d'indicatif.

**Ce qui est assumé, et doit être dit** : deux personnes avec le même modèle de téléphone, le même navigateur et la même connexion partagent une empreinte. La seconde se verra refuser son vote. C'est le prix de ne plus demander de numéro, et il est payé dans le bon sens — un concours gratuit dont le résultat est de toute façon indicatif doit d'abord être facile à voter.

**Conséquence sur les mots** : l'interface annonce « un vote par appareil » et non « un vote par personne », qui serait faux. Un vote **payant** continuera de demander un numéro au jalon 9.4 — mais pour pousser la demande de paiement, pas pour compter.

---

## D-054 — Ne mettre en cache que ce que n'importe quel pilote sait porter
**2026-08-31**

`VotingStatistics::standings()` met en cache un tableau PHP de tableaux associatifs, jamais une `Collection` ni un `stdClass`.

**Raison** : la page publique de vote répondait 500 **à partir de la deuxième requête**. Le cache de développement est un tableau en mémoire, qui rend l'objet tel quel ; les caches réels — fichier, base, Redis — sérialisent. À la relecture, PHP reconstruisait un `__PHP_Incomplete_Class` et tout appel de méthode dessus levait « The script tried to call a method on an incomplete object ».

Le défaut ne pouvait pas apparaître en test, où le pilote `array` ne sérialise rien — le même angle mort que celui de la décision D-051, sous une autre forme. Il ne se voyait pas non plus sur la première requête, ce qui le rendait d'autant plus déroutant.

**Règle générale** : ce qui traverse le cache doit être du scalaire, du tableau ou du null. Les objets se reconstruisent au retour. Les agrégats sont convertis en entiers au passage, ce qui corrige au passage un typage qui promettait des `int` et rendait les chaînes de PostgreSQL.

---

## D-051 — Un modèle du module Votes retrouve toujours son parent lui-même
**2026-08-31**

Jalon 9.3. `PollCategory::resolvedPoll()` et `Candidate::resolvedCategory()` remplacent les accès directs `$this->poll` et `$this->category` partout où ils sont atteignables depuis la page publique.

**Raison** : les tests du jalon 9.3, écrits après le code, ont fait apparaître que **la page publique de vote répondait 500 pour tout visiteur réel** — et que le vote lui-même était impossible. Deux causes distinctes, qu'aucun test antérieur ne pouvait voir parce qu'aucun ne se plaçait hors contexte d'organisation.

1. **`Poll` porte une portée par organisation, `PollCategory` non.** Un visiteur n'étant membre d'aucune organisation, `$category->poll` chargé en différé revenait `null`. Les sept méthodes de `PollCategory` qui s'en servent — dont `acceptsBallots()` — levaient « Attempt to read property on null ». C'est exactement le piège que le commentaire de `Poll::publicBySlug` annonçait, et qui s'était « déjà refermé plusieurs fois ».

2. **Le chargement différé est interdit hors production.** `Candidate::acceptsBallots()` remontait à sa catégorie ; un candidat chargé par `with('candidates')` ignore d'où il vient. Erreur dure en développement, N+1 silencieux en production, sur la page la plus consultée du module.

**Choix** : porter le repli sur le MODÈLE plutôt que sur chaque appelant, comme `TicketType::resolvedEvent()` le fait déjà pour la page d'un événement. Un appelant qui pose la relation n'en paie rien ; un appelant qui l'oublie obtient quand même une réponse juste. Les deux méthodes échouent en fermé — sans parent lisible, le vote est fermé et le classement reste scellé : une anomalie ne doit jamais ouvrir un droit.

`resolvedPoll()` est publique, contrairement à son équivalent sur `TicketType` : `CastFreeBallot` en a besoin pour retrouver l'organisation à créditer depuis un chemin sans contexte.

**Conséquence** : `CastFreeBallot` ne dépend plus de ce que l'appelant a pensé à pré-charger. C'est ce qui a permis au seeder d'emprunter l'action réelle.

---

## D-052 — Un bulletin remboursé garde sa date de confirmation
**2026-08-31**

La contrainte `ballots_confirmed_check` devient `(status IN ('CONFIRMED','REFUNDED')) = (confirmed_at IS NOT NULL)`.

**Raison** : écrite `(status = 'CONFIRMED')`, elle rendait le statut `REFUNDED` **littéralement inatteignable**. Passer un bulletin confirmé au remboursement violait la contrainte, et l'effacer d'abord était interdit par le déclencheur d'immuabilité. Le remboursement d'un vote payé — attendu au jalon 9.5 — n'avait aucun chemin possible. Le commentaire du déclencheur, juste en dessous, annonçait pourtant la transition « PENDING → CONFIRMED → REFUNDED ».

Un bulletin remboursé **a été** confirmé, sinon il n'y aurait rien eu à rembourser : sa date de confirmation reste vraie. C'est cohérent avec le comptage — `VotingStatistics` retient les voix des bulletins `CONFIRMED` et `REFUNDED`, et ne compte le revenu que des `CONFIRMED`. **Un remboursement rend l'argent, pas la voix.**

Corrigé dans la migration d'origine plutôt que par une migration de rattrapage : le jalon 9.3 n'est pas encore livré.

---

## D-050 — La coupure après inactivité est un middleware, pas un réglage de session
**2026-08-28**

Demandé par Jeff. `SESSION_LIFETIME` était déjà à 120 minutes — il n'a jamais suffi, pour trois raisons.

### 1. « Se souvenir de moi » annulait tout

La case était **cochée par défaut**, ce qui la rendait décorative : personne ne décoche une option déjà cochée. Le cookie de rappel reconnecte silencieusement dès la requête suivante — l'expiration de session ne déconnectait donc **rien** pour la quasi-totalité des utilisateurs.

Seul `Auth::logout()` fait tourner le jeton de rappel en base. C'est la raison d'être du middleware : l'expiration passive d'une session ne le touche pas.

La case est désormais décochée par défaut. Elle ne dispense plus que de se reconnecter après avoir fermé le navigateur — elle ne permet pas de contourner le délai d'inactivité.

### 2. L'expiration passive ne dit rien

L'utilisateur revenait sur l'écran de connexion sans savoir pourquoi, et croyait à une panne plutôt qu'à une mesure de sécurité. Le message fait la moitié de la fonctionnalité : il nomme le délai réel, et la vue de connexion l'affiche — elle ne rendait `session('notice')` nulle part.

### 3. Les deux réglages répondent à deux questions différentes

`SESSION_LIFETIME` : « combien de temps une session peut-elle vivre au maximum ». `AUTH_IDLE_TIMEOUT` : « au bout de combien de temps sans rien faire coupe-t-on ». Les confondre obligerait à retenir la plus courte des deux valeurs pour les deux usages. Trente minutes par défaut, zéro pour désactiver — réglage explicite plutôt que middleware retiré du groupe et oublié.

### Deux détails qui se paient sinon

**Livewire reçoit un 419, pas une redirection.** Il ne sait pas suivre une redirection au milieu d'une requête de composant : il recevrait du HTML là où il attend du JSON, et l'écran resterait figé sur un état qui n'existe plus côté serveur. Le 419 est le code qu'il sait déjà traiter — il recharge la page.

**Le groupe `web` uniquement.** L'API agent s'authentifie par jeton Sanctum, sans session. Un agent au contrôle des entrées ne doit surtout pas être déconnecté parce qu'il n'a rien scanné pendant trente minutes — c'est le creux entre deux vagues d'arrivées.

---

## D-049 — Publier est un seul geste, et les règles du jeu se figent à l'ouverture
**2026-08-28**

Jalon 9.2, le dashboard organisateur des votes. Trois décisions, plus un piège de langage qui mérite d'être écrit.

### Un seul bouton « publier »

Pas de « publier » d'un côté et « programmer » de l'autre. L'organisateur publie, et **la date d'ouverture décide** : passée, le vote s'ouvre ; à venir, il est programmé. Lui demander de choisir lui ferait porter une distinction qui se lit déjà dans les dates qu'il vient de saisir.

Conséquence sur le slug : il se fige **à la publication**, pas à la perte de modifiabilité. Un scrutin programmé est déjà publié — sa page circule pendant la campagne d'annonce — tout en restant modifiable. Ces deux notions se recouvrent partout ailleurs dans le produit et se séparent ici.

### Les termes économiques se figent à l'ouverture

Gratuité, prix unitaire, plafond par votant, fenêtre et visibilité des résultats ne bougent plus dès que le vote est ouvert. **Quelqu'un qui a payé 500 francs son vote l'a fait sous ces règles** ; les changer en cours de partie créerait deux catégories de votants dans un même scrutin, ou dévoilerait un classement promis scellé.

Le nom, la description et l'ordre restent modifiables : corriger une faute de frappe ne change ce que personne a payé.

L'action **retire** les attributs gelés au lieu de rejeter tout l'enregistrement. Une requête qui les porterait encore vient d'un onglet resté ouvert, pas d'une intention — refuser en bloc ferait perdre la correction de description que l'organisateur voulait vraiment.

### Catégories et candidats sur un seul écran

Une catégorie sans candidat n'existe pas vraiment. Les séparer obligerait à naviguer entre deux pages pour monter un concours, alors que le geste naturel est « je crée Miss Libreville, j'y mets mes cinq candidates, je passe à la suivante ».

L'isolation multi-tenant y demande une précaution : `poll_categories` et `candidates` **ne portent pas de portée** — ils descendent du scrutin. Toute recherche part donc de `$this->poll`, déjà filtré. Un `PollCategory::find($id)` ouvrirait exactement le trou que la règle 80.4 interdit, et deux tests le vérifient en poussant un identifiant étranger.

### Le piège : `QueryException` est une `RuntimeException`

`QueryException` descend de `PDOException`, qui descend de `RuntimeException`. Un `catch (RuntimeException)` placé avant capture donc les erreurs SQL — et le doublon de numéro de candidat partait en simple notification au lieu de se poser sur le champ fautif. L'ordre des blocs `catch` n'est pas négociable ici.

---

## D-047 — Les trois questions ouvertes du module Votes, tranchées à l'ouverture de la phase 9
**2026-08-28**

L'analyse d'architecture (section 86.5) laissait trois points en suspens, à trancher avant d'écrire. Ils l'ont été à l'ouverture du jalon 9.1, par Jeff. La phase 9 est traitée **avant** la phase 8, à sa demande — l'ordre de la roadmap (section 78) n'était contraignant que de 1 à 7, où les modules partagent le chemin de l'argent.

### 1. Commission des votes payants : 5 %

Comme la billetterie, pas comme les collectes. Un vote payant est une **vente de service à un public**, pas un don à une cause : le votant achète une influence sur un résultat, il ne contribue pas à une œuvre. Assiette : le sous-total des bulletins, jamais le montant débité frais PSP compris (décision D-023).

Le taux vit dans `config/commission.php` et reste réglable sans déploiement par le Super Admin, comme les deux autres. La contrainte `commissions_type_check` accepte désormais `VOTING`.

### 2. Vote gratuit : assumé comme indicatif, et dit dans l'interface

Sans compte utilisateur et sans OTP SMS (décision D-004), il n'existe aucun moyen honnête de garantir « une personne, un vote » : on peut exiger un numéro, en dériver un `voter_hash`, y ajouter IP et empreinte d'appareil, limiter le débit — on ne peut pas empêcher quelqu'un de saisir cinq numéros.

Les deux autres issues ont été écartées : l'OTP SMS suppose un budget et rouvre D-004 ; le tout-payant fermerait les concours scolaires et associatifs, qui sont un usage visé.

**Conséquence, et c'est la partie qui engage** : l'interface publique doit dire que le résultat d'une catégorie gratuite est indicatif. Le taire reviendrait à laisser croire à une garantie que le produit ne tient pas — et un concours dont le résultat est contesté après coup se retourne contre l'organisateur autant que contre nous.

### 3. Rapport final : écran exportable, PDF plus tard

L'organisateur consulte classement, totaux et revenus à l'écran, et exporte en CSV. Le PDF réutilisera le module de reçus (décision D-029) quand le besoin se confirmera, plutôt que d'être construit d'avance sur une mise en forme que personne n'a encore demandée.

---

## D-048 — `Poll` en code, « Vote » en interface — et aucun compteur nulle part
**2026-08-28**

Jalon 9.1. Deux choix de fondation, tous deux difficiles à corriger plus tard.

### Le nommage

En français, « vote » désigne à la fois le scrutin et le bulletin — `$vote->vote` ne veut rien dire. En base et en code : `Poll` (le scrutin), `PollCategory`, `Candidate`, `Ballot` (le bulletin). En interface, tout reste « Vote », « Catégorie », « Candidat » : le vocabulaire de l'organisateur ne change pas.

### Aucune colonne de compteur

Ni `votes_count` sur `candidates`, ni total sur `poll_categories`, ni revenu sur `polls`. Le total est la somme des bulletins confirmés, agrégée en SQL avec un cache court — le patron de `CampaignProgress` (décision D-031).

C'est la traduction en schéma de « l'organisateur ne modifie jamais le nombre de votes » (section 86.4). Une colonne porterait le chiffre en double, et cette seconde vérité serait modifiable. **On ne peut pas falsifier ce qui n'est stocké nulle part** : la règle n'est pas seulement interdite, elle est impossible. Un test le vérifie explicitement, colonne par colonne.

### Ce que la base refuse, plutôt que le formulaire

Catégorie gratuite portant un prix, catégorie payante à zéro franc, plafond de votes nul, fenêtre à moitié renseignée, deux candidats au même numéro dans une catégorie, statut annulé sans date. Toutes ces règles sont des `CHECK` PostgreSQL : elles tiennent aussi contre une commande artisan et une correction manuelle en base.

### Une catégorie ne peut jamais ouvrir ce que le scrutin a fermé

Les catégories sont indépendantes entre elles, mais **subordonnées** au scrutin : leur fenêtre propre ne fait que restreindre. Sans cette subordination, une catégorie mal réglée laisserait voter après la clôture du concours, et le classement figé n'aurait plus de sens.

La fenêtre héritée n'est **jamais recopiée en base** : `starts_at` et `ends_at` restent nuls tant que la catégorie n'a pas de réglage propre. Une copie se désynchroniserait le jour où l'organisateur décale la fenêtre du scrutin.

---

## D-046 — Le reçu nomme le donateur, même quand le don est anonyme
**2026-08-27**

Jalon 7.5, dernier de la phase 7. Une seule décision méritait d'être écrite, et c'est la seule apparente contradiction du module.

### L'anonymat ne s'applique pas au reçu

La décision D-044 pose que le donateur anonyme n'est nommé nulle part — pas même pour l'organisateur. **Son reçu fait exception, et ce n'en est pas une** : l'anonymat porte sur l'affichage *public* (sections 40, 41), et ce document n'est pas public. Il est remis au donateur seul, par un lien signé qu'il est le seul à posséder.

Y écrire « Donateur anonyme » rendrait à ce donateur **son propre reçu inutilisable** — or c'est exactement la pièce qu'il joint à une note de frais ou classe dans sa comptabilité. Le reçu mentionne en revanche que le don est anonyme publiquement, pour qu'il voie que son choix a été pris en compte.

Le reste du reçu suit les règles du reçu de billetterie (décision D-029), pour les mêmes raisons : **le bénéficiaire est l'organisateur**, jamais Pass241 (section 21.2) ; **la commission n'y figure pas**, le donateur ne l'a pas payée ; **les frais de paiement y figurent** sur leur propre ligne, sans quoi le donateur ne peut pas réconcilier avec son relevé mobile money. Un remboursement ultérieur y apparaît — un reçu qui continuerait d'attester un don intégral serait faux.

### Les sept tests obligatoires sont rassemblés, pas dispersés

La section 75 nomme sept tests anti-fraude et les déclare obligatoires. Ils vivent désormais dans un seul fichier, numérotés comme dans la spécification, pour qu'on vérifie d'un coup d'œil qu'aucun ne manque. Chacun est écrit contre le chemin réel — l'écran, l'action ou la base — jamais contre un raccourci qui prouverait seulement que le test sait appeler une méthode.

---

## D-045 — Une collecte se reverse quand elle se clôture, pas avant
**2026-08-27**

Jalon 7.4. La spécification dit quand reverser un événement — après sa fin (décision D-026) — mais **ne dit rien pour une collecte**, qui peut rester ouverte indéfiniment. Il fallait trancher.

### La règle retenue

Le net d'une collecte part quand elle est **terminée, annulée ou échue**. Tant qu'elle est ouverte — ou seulement suspendue, car une suspension se lève — rien ne bouge.

Une collecte ouverte peut recevoir un don à la minute suivante. La reverser reviendrait à la découper en tranches arbitraires, alors que le produit ne prévoit qu'**un seul virement par objet** — c'est cette unicité, garantie en base, qui empêche de payer deux fois après un échec transitoire.

### Ce que cela implique, et qui est assumé

Un organisateur qui mène une collecte sur six mois **ne touche rien avant de l'avoir clôturée**. C'est une contrainte réelle, et c'est le principal défaut de ce choix.

Elle a une contrepartie : clôturer est un geste qui a un sens pour les donateurs — la collecte affiche son résultat final — alors qu'un bouton « retirer » n'en a aucun, et que la section 21.2 l'exclut explicitement. L'organisateur qui veut ses fonds plus tôt clôture et rouvre une nouvelle collecte ; c'est plus honnête vis-à-vis de ceux qui ont donné qu'un retrait discret en cours de route.

**À rouvrir si** l'usage montre que les collectes longues sont la norme. La sortie serait alors des reversements périodiques — plusieurs virements par collecte — ce qui demande de lever la contrainte d'unicité et de tenir un cumul de ce qui a déjà été versé. C'est une évolution, pas une réécriture.

### Une collecte annulée se reverse quand même

L'annulation ne rembourse pas automatiquement (décision D-042) : les dons reçus restent acquis, don par don. Ils doivent donc pouvoir être reversés — ne pas le faire immobiliserait l'argent des donateurs sans que personne l'ait décidé.

### Le reversement est devenu polymorphe

`settlements.event_id` devient `settlable_type` / `settlable_id`, et l'unicité passe au couple. `SettleEvent` devient `SettleFunds`, typé sur un contrat `Settlable` — symétrique de `Payable` : l'un décrit ce qui entre, l'autre ce qui sort.

Le grand livre ne sait plus s'il compte des billets ou des dons : le `Settlable` dit **quelles commissions sont les siennes**, et la règle de calcul — somme signée des lignes figées, ajustements de remboursement soustraits — reste écrite une seule fois. C'était la dernière pièce non générique du parcours financier.

---

## D-044 — L'anonymat vaut aussi contre l'organisateur
**2026-08-27**

Jalon 7.3, transparence de la collecte. Une seule décision mérite d'être écrite, parce qu'elle n'est pas évidente et qu'elle sera tentante à assouplir.

### L'organisateur voit « Donateur anonyme », comme tout le monde

La section 41 dit que l'organisateur ne doit pas pouvoir révéler l'identité d'un donateur anonyme. Reste à savoir ce que *lui* voit sur son propre tableau de bord. La réponse retenue : **exactement ce que voit le public**.

La tentation était forte de faire l'inverse — « c'est son argent, il a le droit de savoir qui donne ». Mais un anonymat que le destinataire peut lever n'est pas un anonymat, c'est une case à cocher décorative. Sur ce marché, un donateur qui coche « anonyme » se protège souvent de la pression sociale de son entourage, pas d'un inconnu.

La plateforme, elle, **conserve l'identité réelle** : le paiement, l'audit, le rapprochement financier et la lutte contre la fraude en dépendent (section 41). Elle ne la montre simplement à personne. Un seul point de code applique la règle — `Donation::publicDonorName()` — pour que la page publique et le tableau de bord ne puissent pas diverger.

Corollaire assumé : si un organisateur doit un jour contacter un donateur anonyme (remboursement, erreur de montant), cela passera par un chemin dédié et audité, jamais par un affichage.

### L'écran des dons n'a aucune méthode d'écriture

Le tableau de bord des dons est en lecture seule **définitive**, et un test vérifie qu'aucune méthode `save`, `delete`, `edit`, `hide` ou `anonymize` n'apparaît sur le composant. Le déclencheur PostgreSQL l'interdit déjà (décision D-043) ; l'écran ne doit pas même suggérer que ce serait possible.

### Ce qui est public et ce qui ne l'est pas

Le **montant collecté est public** (section 37) : le masquer à un membre de l'organisation n'aurait aucun sens, il suffit d'ouvrir la page de la collecte. Le **net après commission**, lui, ne l'est pas — il ne s'affiche qu'avec la permission `finances.view`.

L'historique public affiche dix dons puis « voir plus », mais **annonce toujours le total** : c'est le total qui fait la transparence, pas la longueur de la liste.

---

## D-043 — Le contrat `Payable` suffit : la table `payments` n'a pas été créée
**2026-08-27**

Jalon 7.2. La décision D-041 annonçait deux choses : un contrat `Payable` **et** une table `payments` polymorphe. Le contrat a été construit. **La table, non** — et c'est un revirement assumé, motivé par ce qu'on a vu en écrivant le code.

### Pourquoi la table ne rapportait rien

Une table `payments` ne paie que si **tout** y migre. Or la billetterie ne peut pas y migrer à bon compte : `orders` porte ses colonnes de paiement depuis le début, et elles sont lues par les statistiques, les reversements, les remboursements, les reçus et la console d'administration.

Restaient deux options, toutes deux mauvaises :

- migrer `orders` vers `payments` — un refactor du cœur financier pour un bénéfice de forme ;
- créer `payments` pour les seuls dons — deux vérités pour un même concept, une jointure de plus, et la billetterie qui continue comme avant. Le pire des deux.

**Ce qui devait être partagé n'était pas le schéma, c'était la logique.** Chaque encaissable porte ses propres colonnes ; l'idempotence, le verrou de ligne, la vérification de montant, la commission et le journal vivent une seule fois, dans `ConfirmPayment`.

### Ce qui est effectivement partagé

- `ConfirmPayment` — déplacé de `Actions\Orders` vers `Actions\Payments`, il ne sait plus ce qu'il confirme.
- `CollectionProvider` et `FakeProvider` — typés sur `Payable` : un prestataire encaisse un montant vers un numéro, il n'a aucune raison de savoir si c'est un billet ou un don.
- `payment_webhooks` — `order_id` devient `payable_type` / `payable_id`, lignes existantes reportées.
- `PayableResolver` — retrouve ce qu'un webhook paie à partir de sa référence. **Le seul endroit à modifier quand un module encaissable s'ajoute.**
- `PspFeeCalculator` et `CommissionCalculator` — déjà génériques, inchangés.

Ce qui reste propre à chaque module tient dans le contrat : une commande vérifie son stock et émet des billets, un don n'a rien à vérifier et n'émet rien.

### La preuve que le refactor est neutre

**547 tests de billetterie, inchangés, tous verts après le déplacement.** C'était le filet, et c'est la seule raison pour laquelle ce refactor pouvait être fait sans risque. Aucun comportement observable n'a bougé.

### Ce que le module Votes aura à faire

`Ballot implements Payable`, et une ligne dans `PayableResolver`. Rien d'autre du chemin de l'argent n'est à écrire.

### Ce qui reste ouvert

`settlements.event_id` est toujours une clé étrangère unique vers un événement : le reversement des collectes n'a pas encore de chemin. C'est le jalon 7.4, et c'est la dernière pièce non générique du parcours financier.

---

## D-042 — La campagne ne porte aucun chiffre financier, et ne revient jamais au brouillon
**2026-08-27**

Jalon 7.1 : la campagne de collecte et sa page publique, sans une ligne de paiement. Trois choix méritent d'être écrits.

### Le montant collecté n'existe pas en colonne

`campaigns` n'a ni `collected_amount`, ni `percentage`, ni `donations_count`. Ce n'est pas un oubli : c'est la règle 80.1 traduite en schéma.

Le montant collecté est la somme des dons confirmés, moins les remboursements (sections 38, 47). Une colonne le porterait en double, et cette seconde vérité serait modifiable — par un formulaire, par une commande artisan, par une correction manuelle un soir de panique. **On ne falsifie pas ce qui n'existe pas.**

Le calcul vit dans `CampaignProgress`, seul point de vérité, appelé aussi bien par la page publique que par le tableau de bord : le visiteur et l'organisateur lisent le même chiffre, produit par le même code. Un test vérifie l'absence des colonnes interdites — il tombera si quelqu'un les ajoute par commodité.

### Aucun retour au brouillon, contrairement aux événements

Un événement publié peut revenir en brouillon. Une campagne, non — et c'est délibéré : elle a pu recevoir des dons, et son historique est public (section 43). La repasser en brouillon masquerait des dons confirmés, ce que les sections 44 et 45 interdisent.

La **suspension** existe pour cela : elle ferme les dons en laissant la page et son historique en ligne. Le donateur qui revient retrouve sa contribution ; le visiteur lit que la collecte est fermée.

Même raisonnement pour la suppression : seul un brouillon jamais publié se supprime. Au-delà, la suppression emporterait les dons avec la campagne.

### L'objectif et le bénéficiaire sont figés à la publication

Après publication, seules la description et les images restent modifiables. L'objectif, le bénéficiaire et les dates engagent ceux qui ont déjà donné : les changer après coup reviendrait à **déplacer la cible**, et un donateur qui a contribué pour 1 000 000 F ne doit pas découvrir que l'objectif est descendu à 50 000 F pour afficher 100 %.

### Deux points de méthode, repris de la billetterie

L'échéance est vérifiée **sur la date, jamais sur le statut** : `Campaign::acceptsDonations()` refuse un don passé la date de fin même si la tâche planifiée n'a pas encore tourné. `pass241:expire-campaigns` met ensuite les mots justes sur un fait acquis — si elle prend trois jours de retard, aucun don hors délai n'est accepté pour autant.

L'annulation d'une campagne **ne rembourse pas automatiquement**, contrairement à l'annulation d'un événement (décision D-028). Un billet non honoré n'a plus d'objet ; un don remis à une cause a pu être employé. La décision revient à l'organisateur, don par don.

---

## D-041 — La couche paiement devient générique en phase 7, pas en phase 9
**2026-08-27**

Analyse d'architecture du module Votes (phase 9), menée à la demande de Jeff avant toute écriture. Le détail fonctionnel et le modèle de données proposé vivent dans [docs/11-votes-futur.md](11-votes-futur.md), sections 86.4 et 86.5. Ce qui est décidé ici est ce qui engage les **autres** modules.

### Le constat

Le chemin de l'argent est typé sur `Order` de bout en bout : `CollectionProvider` prend un `Order` dans ses quatre méthodes, `payment_webhooks` porte une clé étrangère `order_id`, `ProcessPaymentWebhook` résout `payload['order_reference']`, `RefundRequest` est par commande, `settlements.event_id` est unique.

Seule la commission est déjà polymorphe — et sa contrainte en base n'accepte que `TICKETING` et `DONATION`.

**Les collectes butent sur ce mur avant les votes.** Un don n'est pas une commande : pas d'événement, pas de type de billet, pas de stock. La consigne « ne pas dupliquer le système de paiement » ne peut donc pas être tenue en l'état, quel que soit le module qui arrive ensuite.

### Ce qui est décidé

**L'extraction d'un contrat `Payable` appartient à la phase 7**, en construisant les dons — pas à la phase 9. La faire au moment des votes reviendrait à écrire le chemin de l'argent une troisième fois, et à laisser entre-temps une seconde implémentation de l'idempotence diverger de la première.

```php
interface Payable {
    public function paymentReference(): string;
    public function payableAmount(): int;    // ce que le PSP encaisse
    public function commissionBase(): int;   // l'assiette, jamais le total débité
    public function commissionType(): CommissionType;
    public function organization(): Organization;
    public function onPaymentConfirmed(Payment $payment): void;
    public function onPaymentFailed(Payment $payment, string $reason): void;
}
```

Implémenté par `Order` (billetterie, existant), `Donation` (phase 7) et `Ballot` (phase 9). `ConfirmPayment` porte alors l'idempotence, le verrou, la vérification de montant et la commission **une seule fois pour les trois modules** ; ce que fait aujourd'hui `TicketIssuer` devient le `onPaymentConfirmed` de la commande.

**`orders` n'est pas touchée.** Rendre `event_id` nullable pour y loger dons et bulletins atteindrait la table la plus sensible du produit — celle que lisent les statistiques, les reversements, les remboursements et les reçus. C'est le raisonnement de rayon d'explosion de la décision D-039, qui conclut ici dans l'autre sens : une invitation a un événement, un don et un bulletin n'en ont pas.

**Les 517 tests existants sont le filet.** Ce refactor ne change aucun comportement observable de la billetterie ; s'il en change un, un test tombe.

### À préparer pendant la phase 7, dans la même passe

Par ordre d'importance :

1. **`Payable` et la table `payments`** — le point unique dont dépend tout le reste.
2. **`settlements` polymorphe** (`settlable_type` / `settlable_id`, unique) — sinon l'argent des campagnes, puis des votes, est encaissé sans chemin de reversement. La règle « un seul virement, pas un par transaction » est conservée.
3. **Ouvrir `commissions_type_check`** aux types à venir dès la migration des dons.
4. **Figer les préfixes d'URL** : `/e/` billetterie, `/collecte/` collectes, `/vote/` votes. Un slug créé après coup ne doit pas pouvoir entrer en collision.

### Ce qui reste à trancher, et n'engage rien pour l'instant

Le **taux de commission des votes** n'existe nulle part dans la spécification (`CLAUDE.md` fixe 5 % billetterie et 2,5 % collectes). Recommandation consignée : 5 %, comme la billetterie — un vote payant est une vente de service, pas un don.

La **protection du vote gratuit** est structurellement limitée sans compte ni OTP SMS. Les trois issues sont écrites en section 86.5 ; aucune n'est choisie aujourd'hui.

### Ce qui ne change pas

La règle de séquencement de `CLAUDE.md` tient : collectes (phase 7) avant votes (phase 9). Cette analyse ne fait pas commencer les votes — elle évite seulement de construire la phase 7 d'une façon qui obligerait à tout reprendre à la phase 9.

---

## D-040 — Le lien d'invitation part par email, et les codes promo attendent
**2026-08-27**

Deux décisions prises ensemble, parce qu'elles referment la phase 6.

### L'email vient en plus du lien, jamais à sa place

La décision D-039 laissait l'envoi entièrement à l'organisateur. Ce choix reste juste pour le canal principal — ici une invitation circule dans un message — mais il faisait recopier une adresse déjà saisie : la liste collée accepte « Nom, adresse », et cette adresse ne servait qu'après l'acceptation, pour le billet.

L'email part donc désormais à la création, pour les invités qui ont une adresse, avec une case décochable pour l'organisateur qui prépare ses invitations à l'avance. **Le lien reste affiché pour tout le monde** : les invités sans adresse sont le cas courant, pas l'exception.

**Le statut est posé après l'envoi, jamais à la mise en file.** « Envoyée » doit vouloir dire que le message est parti. Si le serveur de messagerie refuse, l'invitation reste « créée » et l'organisateur voit qu'il lui reste quelque chose à faire — transmettre le lien lui-même, ce qu'il sait faire. Un statut posé à l'avance lui aurait caché le problème, et l'invité se serait présenté à une porte sans rien.

Le journal d'audit distingue les deux canaux (`channel` : `email` ou `manuel`). C'est la seule façon de savoir, six mois plus tard, si un invité qui affirme n'avoir rien reçu peut être cru.

**La réponse revient à l'organisateur** (`Reply-To` sur l'adresse de contact de l'organisation), pas à la plateforme : un invité répond toujours à ce genre de message, et seul celui qui invite peut lui répondre.

Le renvoi est limité à une fois par minute. Un organisateur qui ne voit rien arriver reclique ; sans garde-fou, l'invité reçoit cinq fois le même message et l'adresse d'expédition finit en indésirable.

### Les codes promo sont reportés

La section 26 reste au périmètre, mais pas au lancement. Un code promo est un levier commercial — il suppose une audience installée et des partenaires à mesurer, ce qu'un organisateur n'a pas le premier jour. L'invitation, elle, est indispensable dès le premier événement : la liste d'invités existe toujours, et sans elle les places offertes ne sont décomptées d'aucun stock.

Rien n'a été écrit côté codes promo : il n'y a donc aucune dette à porter, seulement une décision à reprendre. Quand elle le sera, quatre points sont déjà tranchés et à ne pas rouvrir sans raison : la commission s'assied sur le montant **réellement payé**, un seul code par commande, la limite par personne s'appuie sur le numéro de téléphone (l'acheteur n'a pas de compte), et la remise à 100 % est refusée — offrir une place a déjà son outil.

---

## D-039 — Invitations : une commande à zéro franc, et surtout pas une vente
**2026-08-26**

Phase 6, section 25. Une invitation est **une place mise de côté** pour quelqu'un, qui devient un billet le jour où il l'accepte.

### Elle emprunte le chemin des commandes

Chaque invitation porte une commande à zéro franc. Ce n'est pas une coquetterie comptable : c'est la commande qui **retient le stock**. La décision D-017 définit « réservé » comme une commande en attente non expirée — une invitation est exactement cela, et hérite donc gratuitement de tout le mécanisme, y compris de sa meilleure propriété : *la place revient au public à la seconde où l'échéance passe, sans qu'aucune tâche n'ait à s'exécuter*.

Le même choix donne, sans une ligne de plus : le billet, le QR signé, le PDF, l'espace « Mes billets », le contrôle à l'entrée. Un invité franchit la porte exactement comme un acheteur, et l'agent n'a rien à savoir des invitations.

**L'alternative examinée** — une entité indépendante, avec `tickets.order_id` rendu nullable — aurait été plus « pure » mais aurait touché la table la plus sensible du produit et tout ce qui lit `ticket->orderItem` : prix payé sur le PDF, reçus, statistiques par tarif. Le rayon d'explosion décidait.

### Mais elle n'est jamais une vente, et la base le garantit

`orders.origin` distingue `PURCHASE` de `INVITATION`. Sans cette colonne, le tableau de bord annoncerait des billets vendus qui n'ont rien rapporté — un organisateur qui invite cinquante personnes lirait un chiffre faux.

Trois garde-fous vivent **en base**, donc valables aussi pour un import, une commande artisan ou une correction manuelle :

- une invitation a forcément `amount = subtotal = 0` ;
- un achat a forcément son opérateur et son numéro, une invitation n'en a **jamais** — écrire « AIRTEL » sur une commande qui n'a jamais touché Airtel corromprait la seule colonne sur laquelle on rapproche les paiements ;
- les statuts et leurs dates vont ensemble dans les deux sens.

**Aucune commission n'est enregistrée** à l'acceptation. Une ligne à zéro franc encombrerait un journal financier qui doit rester lisible, et laisserait croire qu'une vente a eu lieu.

Les statistiques comptent désormais `sold` et `invited` séparément — mais **les places restantes retranchent les deux** : un siège offert est un siège occupé.

### Accepter est un geste explicite

Émettre le billet à la simple ouverture du lien remplirait l'événement de billets jamais réclamés : un lien s'ouvre par curiosité, souvent depuis un groupe où il a été transféré. La place n'est définitivement prise que lorsque quelqu'un le dit.

Rouvrir le lien après acceptation ramène **au billet**, jamais à une erreur : c'est le geste du jour de l'événement, quand on cherche son QR.

Le lien n'est pas signé, contrairement au billet : c'est le **jeton** (40 caractères aléatoires) qui fait l'accès. Une signature en plus rendrait le lien intransmissible par copier-coller — or une invitation circule dans un message, c'est sa raison d'être.

### `USED` n'est pas stocké

La section 25 liste six états ; cinq vivent en base. Le sixième se lit **sur le billet** : le passage à l'entrée est enregistré par le contrôle d'accès, qui ne sait rien des invitations. Le dupliquer donnerait deux vérités pour un même fait, et la seconde serait fausse dès qu'un scan hors connexion remonterait après coup (section 31.1).

### L'envoi reste à l'organisateur

*Amendé par la décision D-040 : l'email vient en plus du lien, pour les invités qui ont une adresse. Ce qui suit reste vrai du canal principal.*

La plateforme ne prétend pas envoyer l'invitation : elle affiche le lien, et enregistre que l'organisateur dit l'avoir transmis. C'est ce que `SENT` signifie ici. Sur ce marché, une invitation part dans un message — pas dans un email que personne n'ouvrira.

### Refactorisation au passage

L'émission des billets quitte `ConfirmPayment` pour `TicketIssuer`. Deux chemins produisent des billets, et ils doivent en produire exactement les mêmes ; une seconde implémentation aurait fini par diverger, du côté le moins parcouru — donc celui où un billet mal formé se serait vu le plus tard, à une porte, un soir.

---

## D-038 — Application agent : React Native, Expo SDK 57, et le cœur en modules purs
**2026-08-26**

L'application de contrôle d'accès (`/mobile`), périmètre V1 de la section 27.17. Expo SDK 57, React Native 0.86, TypeScript strict, `expo-camera` pour le scan, `expo-sqlite` pour la base locale, `expo-secure-store` pour la session, `expo-crypto` pour les empreintes.

### Ce qui est testable est isolé, et testé

La justesse ne vit pas dans les écrans : elle vit dans `src/domain/`, quatre modules **purs** — décision locale, file de synchronisation, écart d'horloge, libellés de verdict. Aucun ne touche au réseau, à la base ni à l'horloge : tout leur est donné.

C'est la seule partie qu'on peut vérifier sans appareil, et c'est précisément celle où une erreur laisse entrer quelqu'un qui ne devrait pas. **31 tests** la couvrent. Les écrans, eux, sont minces par construction — ils affichent ce que le domaine décide.

### Les trois règles reprises du serveur

1. **La décision est locale, le verdict est du serveur.** L'agent voit un résultat immédiatement, hors connexion comprise ; le serveur arbitre ensuite et peut contredire (section 31.1). L'application remplace son verdict sans discuter — et le bandeau d'état signale combien de contrôles ont été retournés, sans quoi l'agent resterait sur un « entrée autorisée » périmé.

2. **Aucun secret sur l'appareil.** La base locale ne stocke que des **empreintes SHA-256** de jetons. Au scan, l'application hache ce qu'elle lit et cherche l'empreinte : aucun secret n'a eu besoin de descendre. Le seul jeton conservé est celui d'un contrôle pas encore remonté — le serveur en a besoin pour vérifier la signature — et il est **effacé dès la confirmation**.

3. **Un contrôle enregistré ne se perd jamais.** Écriture locale avant toute tentative réseau ; file rejouable ; idempotence garantie côté serveur. À la synchronisation, **les contrôles partent avant que les billets n'arrivent** : si la connexion ne dure que quelques secondes, ce qu'on veut sauver, ce sont les passages déjà effectués.

### Deux écarts assumés avec le serveur

- Un billet d'un **autre événement** est absent de la base locale : l'application dit « invalide » là où le serveur dira « autre événement ». Les deux refusent l'entrée, donc le geste de l'agent est identique, et l'historique se corrige à la synchronisation.
- Un **double scan simultané à deux portes** n'est pas arbitrable localement — aucun téléphone ne sait ce que fait l'autre. Les deux agents voient « entrée autorisée », et le serveur déclasse le plus récent. C'est le compromis que le mode hors connexion impose : mieux vaut laisser passer deux fois dans un cas rare que bloquer une file entière dès que le réseau tombe.

### Choix d'interface

Pas de NativeWind ni de bibliothèque de composants : `StyleSheet` et un fichier de thème reprenant la palette. L'application a cinq écrans utilitaires ; une couche de style supplémentaire coûterait plus à maintenir qu'elle ne rapporterait.

**Pas de verre liquide non plus.** Il suppose un écran posé dans une lumière choisie ; l'agent tient son téléphone à bout de bras, la nuit. Le verdict occupe tout l'écran, en aplat, et se lit avant le texte — trois tons et non deux, parce que « déjà utilisé » envoie chercher le vrai porteur là où « invalide » signale une contrefaçon.

Le retour au scanner est **automatique** après deux secondes et demie : demander un geste par personne ajouterait des centaines de gestes à la soirée. Un verrou entre deux lectures empêche la caméra de produire dix contrôles pour une seule présentation.

### Ce qui n'est pas vérifié, et ne peut pas l'être ici

Le contrat d'API est vérifié de bout en bout contre le serveur local — connexion, événements, empreintes, compteurs, historique, recherche répondent exactement dans la forme attendue par le client. **Le reste ne l'est pas** : caméra, SQLite, SecureStore et NetInfo n'existent que sur un appareil. `npm run typecheck` et `npm test` passent ; le premier lancement réel reste à faire.

### Reste ouvert

- **Signature asymétrique du jeton** (section 18, décision D-018). L'empreinte protège déjà le téléphone perdu ; la clé publique permettrait en plus de vérifier une signature hors ligne, sans télécharger la moindre empreinte. Le préfixe de version du jeton permet de l'ajouter sans invalider les billets en circulation.
- Révocation d'un appareil à distance (section 27.3) : le serveur sait révoquer une affectation, l'application ne l'apprend qu'à sa prochaine requête.
- Build de développement et distribution interne (skill `expo-dev-client`).

---

## D-037 — Écran des entrées : en lecture seule, et l'admission manuelle bien visible
**2026-08-26**

Le pendant organisateur du contrôle d'accès : qui est passé, par quelle porte, et ce qui a été refusé. Pendant la soirée, c'est l'écran qu'on garde ouvert ; le lendemain, c'est celui qui répond aux questions posées après coup.

### Aucun chemin d'écriture, et un test qui le vérifie

Le journal des scans est en ajout seul, protégé par déclencheur PostgreSQL (section 68). L'écran n'expose aucune correction, aucune suppression — **un organisateur qui pourrait effacer une entrée pourrait nier une présence**.

La garantie ne repose pas seulement sur l'intention : un test énumère les méthodes publiques du composant et échoue si l'une commence par `save`, `update`, `delete` ou `edit`. C'est ce qui empêche qu'un ajout distrait, dans six mois, ouvre discrètement la porte.

### `attendees.view`, et non `access_control.manage`

Cet écran nomme des personnes. Gérer les portes et lire la liste de ceux qui sont entrés sont deux droits différents, et c'est le second qui est en jeu. Les trois rôles qui gèrent le contrôle d'accès possèdent déjà `attendees.view` par défaut : la distinction ne gêne personne aujourd'hui, mais elle tient si un organisateur restreint un jour les permissions d'un Manager.

### Ce que l'écran refuse de lisser

- **L'admission manuelle a son propre compteur.** Elle est légitime — un QR illisible arrive — mais sa vérification est plus faible qu'un scan signé, et c'est la seule chose qu'on veut pouvoir compter après coup. La noyer dans le total la rendrait indétectable.
- **Les billets attendus excluent les remboursés et annulés**, sans quoi le taux de présence ne pourrait jamais atteindre 100 % — un chiffre faux est pire qu'aucun chiffre.
- **Les arbitrages de double scan sont signalés en tête**, avec l'écart d'horloge quand il est anormal (section 31.1). Les deux causes possibles — horloge déréglée ou tentative de passer à deux — méritent d'être vues.
- **Le tri se fait sur l'heure du scan, pas de la synchronisation.** Un lot remonté après une coupure se range à l'heure où les gens sont passés.

### Le filtre « sans porte déclarée »

Le point d'entrée est facultatif (décision D-034) : un lieu à porte unique n'en déclare aucune. Ces contrôles doivent rester retrouvables, donc le filtre distingue trois états — une porte précise, toutes, et aucune.

---

## D-036 — Nouveau logo : le pictogramme seul, découpé à l'encre
**2026-08-26**

Un nouveau `docs/design/logo.svg` a été fourni : le P à traits de vitesse contenant un QR Code, le mot-symbole « Pass 241 », et la signature « Vivez, réservez, profitez. ».

### Ce que l'interface reprend, et ce qu'elle laisse

**Le pictogramme seul.** Le mot-symbole reste du texte vivant, comme avant : un mot vectorisé devient flou sous vingt pixels, ne se sélectionne pas, ne se lit pas à la voix, et ne suit pas la graisse du reste de la page. La signature suit la même règle.

Le tracé est monochrome, donc `currentColor` s'applique : azur dans l'en-tête, blanc sur le bandeau du billet, sans dupliquer un seul fichier.

### Le cadrage vient de l'encre, pas des coordonnées

Le logo a été rasterisé puis parcouru pixel par pixel. Trois bandes d'encre séparées par du vide parfait : le pictogramme (y 215–681), le mot-symbole (706–860), la signature (884–911). Le pictogramme occupe donc **x 275–799, y 215–681** — d'où le cadrage `viewBox="275 215 525 467"`.

Déduire ce cadrage des coordonnées du fichier donne un résultat décentré : les points de contrôle des courbes débordent largement du dessin visible (x commence à 155 dans les données, à 275 à l'encre).

### Quatre tracés, jamais fusionnés

Le fichier répartit le dessin sur quatre tracés en `fill-rule="evenodd"`. Les fusionner en un seul **changerait le dessin** : deux contours superposés s'y soustraient au lieu de s'additionner, et le creux du P viendrait trouer le plein d'un autre tracé. Le découpage retire les sous-tracés du mot-symbole (50 sur 91) mais conserve la répartition d'origine — 61 ko de source ramenés à 18 ko de balisage.

### Favicon

`favicon.svg` reprend le même pictogramme avec une couleur figée : un fichier d'icône n'a aucun contexte dont hériter. Les deux schémas sont prévus, azur 700 en clair et azur 300 en sombre — l'onglet d'un navigateur sombre affiche l'icône sur un fond sombre. Le `favicon.ico` d'origine, vide (zéro octet), reste en dernier recours.

**Reste à faire, non bloquant** : `apple-touch-icon.png` pour l'ajout à l'écran d'accueil sur iOS, qui ne lit pas les favicons SVG — c'est un geste réel sur un produit dont le billet vit dans le navigateur du téléphone.

### Le billet de la page d'accueil, réduit

Le même composant sert de vitrine sur la page d'accueil et de billet réel après paiement. À taille réelle il fait 958 px de haut : plus que la hauteur d'un écran, et il écrasait la colonne de texte qu'il devait illustrer.

La vitrine est donc réduite par `zoom` — et non par `scale`, qui ne réduit que le dessin en laissant la boîte d'origine, donc un grand vide sous le billet. **Le composant n'est pas touché** : là où le billet *est* le produit, il garde sa taille réelle.

---

## D-035 — Tableau de bord, historique, et l'entrée accordée sans QR
**2026-08-26**

Fin de la partie serveur de la Phase 5 : les trois routes que l'application Expo consommera pour ses écrans de compteurs, d'historique et de recherche (sections 27.14, 27.15) — et le chemin d'entrée qui manquait derrière la recherche manuelle.

### Ce que le téléphone ne peut pas savoir

L'application tient ses compteurs dans sa base locale, et c'est elle qui les affiche hors connexion. Le tableau de bord serveur ne les remplace pas : il donne **ce que font les autres portes**. À une entrée tenue par quatre agents, la question n'est pas « combien ai-je scanné » mais « combien de personnes sont entrées » — et la réponse est répartie sur quatre téléphones.

La réponse porte donc les deux : `counters` pour l'entrée entière, `mine` pour l'agent. Les billets attendus excluent les remboursés et annulés, sans quoi le nombre de billets restants ne tomberait jamais à zéro.

### L'historique corrige le téléphone, il ne le remplace pas

Deux situations le rendent nécessaire, et les deux arrivent à une vraie entrée. Un scan affiché « entrée autorisée » peut **perdre l'arbitrage** quand un scan plus ancien remonte d'une autre porte (section 31.1) : l'application ne peut pas le deviner, seul le serveur arbitre. Et un téléphone réinstallé en pleine soirée repart sinon d'un historique vide.

**Périmètre : ses propres contrôles, pas ceux de l'entrée entière.** Un téléphone d'agent se perd ; il n'a pas à contenir la liste nominative de tous ceux qui sont entrés par les autres portes (sections 27.5, 27.16). Les compteurs globaux, eux, ne nomment personne — c'est pourquoi ils sont donnés sans réserve.

### La recherche manuelle appelait un chemin d'entrée, qui n'existait pas

La section 27.15 prévoit qu'un agent retrouve un billet par sa référence quand le QR est illisible. La remontée de scans, elle, n'acceptait qu'un jeton. **La recherche n'aurait donc été qu'un écran de consultation** : l'agent identifiait le billet, laissait la personne entrer, et ce passage n'existait nulle part.

Un scan porte désormais un jeton **ou** une référence. Les deux passent par la même décision — même verrou, même arbitrage, mêmes refus. La référence évite de lire un QR abîmé, elle n'ouvre rien de plus : billet d'un autre événement, remboursé, annulé ou déjà passé se refusent identiquement, et trois tests le vérifient.

**La correspondance est exacte, jamais approchante.** C'est la recherche qui tolère une saisie partielle et propose des candidats ; le geste qui fait entrer quelqu'un désigne un billet précis. Une approximation y ferait entrer le voisin de référence.

Quand les deux sont envoyés, **le jeton l'emporte** : sinon une application pourrait dégrader volontairement la vérification en joignant une référence à un QR parfaitement lisible.

### La colonne `manual`, et pourquoi elle n'est pas cosmétique

Une admission à la référence et un scan signé se ressembleraient parfaitement dans le journal. Or l'un prouve une signature, l'autre une référence lue à l'œil — **c'est précisément la distinction qu'on cherche le lendemain**, quand on se demande comment quelqu'un est entré. Un agent qui admettrait à la main la moitié d'une entrée se verrait immédiatement.

Le fait est figé au même titre que les autres (déclencheur PostgreSQL étendu), et l'audit le dit à part : `TICKET_ADMITTED_MANUALLY`, jamais confondu avec `TICKET_USED`.

### La recherche n'est pas un export

Trois caractères minimum, vingt résultats au plus, jokers `LIKE` neutralisés, limitation à 30 appels par minute. Sans cela, une recherche sur `%` rendrait la liste entière des participants — sur un téléphone qui se perd. Aucun jeton n'y descend, même règle que la synchronisation : une empreinte permet de reconnaître un billet, pas d'en fabriquer un.

---

## D-034 — Écrans de gestion : une porte facultative, plusieurs agents dessus
**2026-08-26**

Partie web de la Phase 5 : l'organisateur déclare ses portes et affecte ses agents depuis un seul écran, attaché à l'événement.

### Un seul écran pour les deux

Les points d'entrée et les agents partagent une page. Un agent s'affecte à une porte : les séparer obligerait à naviguer entre deux écrans pour une seule décision, la veille d'un événement où l'organisateur n'a pas de temps à perdre.

### La porte est facultative, et n'impose aucune correspondance

**La plupart des lieux n'ont qu'une entrée.** Exiger un point d'entrée avant de pouvoir affecter un agent ajouterait une étape sans contrepartie à la majorité des organisateurs : `entry_point_id` est nullable, et l'écran propose « Toutes les entrées ».

Symétriquement, **rien ne limite le nombre d'agents sur une porte** — une entrée à fort débit en demande plusieurs. La seule unicité posée est `(event_id, user_id)` : un agent ne figure qu'une fois sur un événement. Deux tests fixent ces deux libertés.

### Ce qui ne se supprime pas

Une entrée se **ferme**, elle ne se supprime pas : fermée en cours de soirée, elle ne reçoit plus de scan, mais l'historique de ceux qui y sont passés reste lisible. Un agent se **retire** de la même façon — son affectation porte `revoked_at`, et c'est elle qui explique dans six mois pourquoi tel agent a scanné tel billet (section 67).

Les noms d'entrée sont uniques par événement : deux « Entrée VIP » rendraient l'historique inexploitable, la question « qui est passé par l'entrée VIP ? » n'ayant plus de réponse unique.

### Le mot de passe se transmet de vive voix

Quand l'adresse est inconnue, un compte est créé avec un mot de passe généré, **affiché une seule fois** à l'organisateur et jamais stocké en clair. Sur ce marché, un agent recruté pour une soirée n'a souvent pas d'adresse qu'il consulte : attendre qu'il clique sur un lien d'invitation le laisserait dehors le soir venu.

Une adresse déjà connue **réutilise le compte** : un agent qui travaille pour deux organisateurs n'en a pas deux, et un organisateur qui tient lui-même la porte s'affecte avec le sien. Les rôles Spatie étant portés par organisation, aucun droit ne fuit de l'une à l'autre.

### Ce que l'écran ne croit pas

L'identifiant de point d'entrée venu du navigateur est **revérifié contre l'événement courant** avant toute affectation : sans cela, un agent pourrait être placé à la porte d'un autre événement (règle 80.4).

L'activité des agents est **agrégée en une seule requête SQL**. La compter agent par agent ferait autant de requêtes que d'agents, chacune parcourant, sur un événement de cent mille billets, un volume conséquent.

### Le piège PostgreSQL, troisième fois

Rattraper une violation d'unicité ne suffit pas : PostgreSQL a déjà avorté la transaction entière, et la requête suivante échoue. Sans point de sauvegarde, un nom d'entrée en double faisait planter l'écran au lieu d'afficher une erreur de saisie. **Toute insertion dont l'échec est attendu vit désormais dans sa propre transaction imbriquée** — c'est la troisième fois que ce piège se referme sur ce projet.

---

## D-033 — Contrôle d'accès : socle serveur et API agent
**2026-08-26**

Phase 5, partie serveur : points d'entrée, affectation des agents, validation d'un billet, et l'API que consommera l'application Expo.

### Ce qui décide qu'une personne entre

Le billet est relu **sous verrou** avant d'être marqué utilisé. Deux agents qui scannent le même QR au même instant à deux portes ne peuvent pas le faire passer deux fois — sans le verrou, les deux liraient « actif » avant que l'un n'écrive.

**Le scan au timestamp local le plus ancien gagne** (section 31.1), quel que soit l'ordre d'arrivée réseau. Un agent hors connexion qui synchronise une heure plus tard ne perd pas son antériorité — c'est ce qui rend le mode hors ligne utilisable à une vraie entrée. Quand un scan plus ancien arrive après coup, celui qui avait été accepté est déclassé et lié à son vainqueur : l'historique dit ce qui s'est passé plutôt que de faire disparaître un passage.

**Tout est journalisé, refus compris**, y compris les QR non reconnus enregistrés sans billet — c'est le cas qui signale une contrefaçon.

**Le point d'entrée ne filtre rien.** Il ne décide pas qu'un billet VIP passe par l'entrée VIP : la section 29 ne le prévoit pas, et l'inventer ferait refuser des porteurs valides — le pire défaut possible pour un contrôle d'accès.

### L'application ne reçoit jamais de secret

L'API envoie des **empreintes SHA-256 des jetons**, jamais les jetons. Un téléphone d'agent se perd et se vole à l'entrée d'un concert : avec les jetons, le voleur repart avec de quoi fabriquer tous les QR de l'événement ; avec les empreintes, il peut reconnaître un billet, pas en créer un.

C'est la réponse pratique à la limite notée en D-018 : la signature HMAC ne peut pas être vérifiée hors ligne sans exposer la clé. La signature asymétrique reste la solution propre, et le préfixe de version du jeton permettra de l'ajouter sans invalider les billets en circulation.

### L'agent n'a pas de contexte d'organisation

Un agent peut servir plusieurs organisations : il n'a donc aucun contexte actif, et c'est **l'affectation** qui définit son périmètre. Toutes les lectures passent par `AgentAccess`, qui lève la portée **d'un seul bloc** — lever relation par relation ne fonctionne pas, une fermeture de chargement imbriqué recevant une `Relation` et non un `Builder`.

`eventFor()` réunit résolution et autorisation, et répond **404 et non 403** : un agent n'a pas à apprendre quels événements existent ailleurs sur la plateforme.

Le **point d'entrée enregistré vient de l'affectation, jamais de la requête** : une application ne doit pas pouvoir se déclarer à une autre porte que celle où l'organisateur l'a placée.

### Faille fermée en écrivant les tests

**Sanctum, faute de jeton, retombe sur la session web** — et une session obtient un `TransientToken` qui répond « oui » à **toutes** les habilitations, `agent` comprise. L'API agent était donc joignable depuis un navigateur connecté au dashboard, avec les droits d'un agent. `EnsureAgentToken` exige désormais un vrai jeton personnel d'appareil. Un test le verrouille.

Découvert par accident : un test de déconnexion passait alors qu'il n'aurait pas dû.

### Le reste

Jetons Sanctum portant l'habilitation `agent` **et elle seule**, nommés d'après l'appareil pour permettre une révocation à distance ciblée. Un seul jeton par appareil : une reconnexion remplace le précédent plutôt que d'empiler des accès oubliés.

Limitation de connexion **par couple compte + appareil** : par email seul, quelqu'un pourrait fermer la porte d'un agent légitime en échouant volontairement depuis un autre téléphone.

La remontée des scans se fait **par lot**, rejouable, et tolérante à l'échec partiel — un scan qui échoue n'annule pas les autres, sinon l'application retransmettrait indéfiniment un lot dont un seul élément pose problème. La synchronisation est **paginée par curseur** (un `OFFSET 90000` relirait quatre-vingt-dix mille lignes) et **incrémentale** via `since`.

L'**écart d'horloge** de l'appareil est conservé à chaque scan. La règle du double scan repose sur l'horodatage local ; un appareil déréglé peut faire gagner le mauvais scan. Ce signal ne bloque rien — il permet de le démontrer après coup, ce que la spec elle-même demande de documenter.

**Deux bugs corrigés au passage** : sur une retransmission, le scan gagnant était réécrit pour pointer vers lui-même ; et PostgreSQL avortant toute la transaction sur une violation de contrainte, la reprise après doublon échouait — l'insertion vit maintenant dans un point de sauvegarde.

---

## D-032 — Console d'administration étoffée, en verre, en consultation seule
**2026-08-26**

Quatre écrans supplémentaires (section 58) : vue générale, organisations, transactions, journal d'audit — en plus des reversements.

### Ce qui structure ces écrans

**Consultation seule, structurellement, partout.** Aucun écran n'expose de méthode qui modifie quoi que ce soit : ni suspendre, ni rembourser, ni rejouer, ni supprimer. Des tests parcourent les méthodes publiques de chaque composant pour interdire leur réapparition — c'est le genre de bouton qu'on rajoute « juste pour dépanner » six mois plus tard.

La **suspension d'une organisation** est prévue par la section 10 mais n'est pas exposée ici : elle coupe des ventes en cours et bloque des reversements, elle mérite un chemin dédié, audité et confirmé, pas un bouton dans une liste.

**Le journal n'expose aucune action destructive et n'en exposera jamais** : un journal qu'on peut nettoyer ne prouve rien (section 67).

**La commission est distinguée du volume qui transite.** Le volume passe par la plateforme sans lui appartenir (section 21.2) ; les confondre donnerait à Pass241 un chiffre d'affaires imaginaire. Les deux chiffres sont côte à côte, étiquetés.

**Ce qui demande une intervention n'est jamais mis en cache**, contrairement au reste (cinq minutes) : un reversement en échec doit apparaître à l'instant où il échoue, pas cinq minutes plus tard. Il passe aussi **avant** les volumes à l'écran — c'est un outil de surveillance, pas un tableau de bord commercial.

**Aucun numéro de téléphone n'apparaît nulle part**, même partiellement. Ces écrans servent à repérer un écart de montant ou un statut anormal, pas à retrouver quelqu'un (section 52).

### Le verre

La colonne latérale est le **seul endroit du produit où une colonne entière est en verre** : elle est fixe, ne défile pas, et se détache d'une nappe ambiante — les trois conditions qui rendent l'effet lisible plutôt que décoratif. Les cartes de chiffres sont en verre avec un liseré de lumière sur l'arête haute : c'est ce qui distingue une vitre d'un rectangle blanc translucide.

**Les tableaux restent des surfaces pleines** (règle 70.4) : la lisibilité y prime sur la matière.

Les cartes se révèlent au défilement et se soulèvent de deux pixels au survol, avec le reflet spéculaire qui suit le curseur. Deux pixels et pas plus — au-delà, la grille bouge et l'œil suit le mouvement au lieu de lire le chiffre. Tout est désactivé sur appareil modeste, sur écran tactile et en mouvement réduit.

### Deux bugs trouvés en construisant

1. **Mes règles CSS ajoutées hors de `@layer components` passaient devant les utilitaires Tailwind.** Une classe `border-l-[3px]` posée dans le gabarit ne s'appliquait plus — le liseré des actions financières du journal était invisible. Les nouvelles règles vivent maintenant dans la couche.
2. **`select()` appelé après `withCount` emporte la sous-requête de comptage** : le nombre de membres d'une organisation ressortait vide. L'ordre est inversé, et un test verrouille la valeur.

`AuditAction` reçoit un `label()` lisible et un `isFinancial()`. Les libellés sont écrits à la main : « PAYOUT_DESTINATION_CHANGED » se transforme mal en français, et c'est un écran qu'on parcourt vite.

---

## D-031 — Statistiques du dashboard : agrégation SQL, cache court, montants sous permission
**2026-08-26**

Chiffres de billetterie du dashboard organisateur (section 56) : billets vendus, recettes, net après commission, billets restants, entrées et taux de présence, plus la répartition par catégorie de billet.

**Tout est agrégé en SQL. Aucune collection de billets n'est chargée, jamais.** Un événement de 100 000 billets doit produire les mêmes quelques lignes de résultat qu'un événement de dix. Compter en PHP marcherait six mois, puis ferait tomber le dashboard le jour du premier gros événement — c'est-à-dire exactement le jour où l'organisateur le regarde le plus. Un test compte les requêtes et vérifie qu'elles contiennent `COUNT(` ou `SUM(`.

**Deux règles de comptage, qui expliquent les chiffres :**

1. Un billet **remboursé, annulé ou expiré n'est pas vendu**. Le compter gonflerait les recettes d'un argent rendu au client.
2. La recette d'un billet est le **prix réellement payé**, relevé sur la ligne de commande, jamais le tarif courant du type : le prix a pu changer depuis l'achat.

**Le net figure à côté de la recette brute, dès la première lecture.** Afficher 70 000 quand l'organisateur recevra 66 500 ferait lire le reversement comme une erreur — c'est le même raisonnement que pour l'email de reversement (décision D-026).

**Les montants sont conditionnés par `finances.view`, pas les décomptes de billets.** Un CO_ORGANIZER gère la billetterie « sans accès aux finances par défaut » (section 10) : il doit savoir combien de billets sont partis pour organiser l'entrée, sans voir les recettes. Masquer les deux le rendrait incapable de faire son travail.

**Cache d'une minute.** Assez pour qu'un dashboard rafraîchi en boucle ne réagrège pas à chaque fois, assez court pour qu'une vente faite à l'instant apparaisse avant que quelqu'un ne s'inquiète. `forget()` permet d'invalider explicitement.

**Répartition par NOM de catégorie et non par identifiant** : « VIP » veut dire la même chose d'un événement à l'autre, et c'est cette lecture qui intéresse l'organisateur. Une catégorie **jamais vendue apparaît quand même**, avec zéro — c'est souvent celle-là qu'on cherche, la faire disparaître serait contre-productif.

**Entrées et taux de présence sont calculés mais masqués tant qu'aucun billet n'a été scanné.** Le contrôle d'accès arrive en phase 5 ; annoncer « 0 entrée » et « 0 % de présence » ne renseignerait sur rien et se lirait comme un échec. La carte annonce « À venir » à la place.

**Barres en CSS, sans bibliothèque de graphiques.** Quatre à dix lignes ne justifient pas des dizaines de kilo-octets sur une connexion mobile gabonaise (section 72). Le chiffre reste à côté de la barre : c'est lui qu'on lit, la barre ne sert qu'à comparer.

---

## D-030 — Console d'administration : accès hors rôles, consultation seule
**2026-08-26**

Console Super Admin (section 58), ouverte sur les reversements. Trois choix structurants.

**L'accès ne passe pas par le système de rôles.** Les rôles Spatie sont **scopés par organisation** : un rôle s'évalue dans le contexte d'une organisation, alors que le Super Admin n'appartient à aucune et les voit toutes. Faire passer cet accès par un rôle obligerait à lui inventer une organisation, et ouvrirait la porte à ce qu'un organisateur se l'attribue chez lui. D'où un middleware dédié sur `users.is_super_admin`, et des routes **hors** du middleware `organization` — l'y placer renverrait le Super Admin créer une organisation.

`EnsureSuperAdmin` répond **404 et non 403** : l'existence même de la console n'a pas à être confirmée à qui n'y a pas accès.

**C'est le seul écran du produit qui traverse les organisations.** Chaque requête porte `acrossOrganizations()` de façon explicite et assumée : c'est la raison d'être du rôle. Partout ailleurs, voir deux organisations dans la même liste serait une faille (règle 80.4). Un test vérifie qu'un organisateur reçoit 404.

**Consultation seule, structurellement.** La section 58 exige que « les données financières historiques restent protégées contre les modifications arbitraires ». Ici cela veut dire qu'**aucune méthode n'existe** — ni approuver, ni rejouer, ni annuler, ni marquer comme payé — et non qu'un bouton est désactivé. Un bouton grisé laisserait croire qu'un humain peut libérer des fonds à la demande, alors que le reversement est instruit par une commande planifiée et son montant gelé par déclencheur (décision D-026). Un test parcourt les méthodes publiques du composant pour interdire leur réapparition.

**Le design reprend celui du dashboard organisateur** — même colonne latérale, même palette, mêmes surfaces. J'avais d'abord fait une console sombre et dense, plus proche d'un outil de surveillance ; l'arbitrage produit a été de garder un seul langage visuel, un administrateur passant d'un espace à l'autre. Seule différence conservée : une pastille « Administration » sous la marque, pour qu'on ne confonde jamais cette console avec l'espace d'un organisateur.

Le numéro de destination n'y apparaît jamais, même partiellement : un écran de surveillance n'en a pas besoin pour repérer un écart (section 52). Les totaux sont agrégés en SQL — charger les lignes pour les additionner en PHP marcherait six mois, puis plus du tout.

---

## D-029 — Reçu de paiement : PDF, vendeur = organisateur, sans commission
**2026-08-26**

Reçu de paiement pour toute commande réellement encaissée. Champs repris de la section 53 (reçu de don) adaptée à la billetterie : référence unique, objet, montant, date, méthode de paiement, identité.

**En PDF, et téléchargeable — contrairement au billet.** Les deux documents ne servent pas la même chose : le billet doit être le même objet à l'écran et dans la galerie du téléphone, d'où l'image (décision D-021) ; le reçu est une pièce comptable qu'on classe, qu'on transmet à un trésorier ou qu'on joint à une note de frais. Le PDF est son format naturel.

**Trois règles de contenu, chacune pour une raison précise :**

1. **Le vendeur est l'organisateur, jamais Pass241.** La plateforme encaisse pour son compte et n'est jamais dépositaire des fonds (section 21.2). Un reçu qui présenterait Pass241 comme vendeur dirait le contraire de ce que le produit est. Le pied de page l'énonce explicitement.
2. **La commission n'y figure nulle part.** Elle se joue entre la plateforme et l'organisateur ; l'acheteur ne l'a pas payée, et l'imprimer lui ferait croire qu'elle lui a été prélevée. Un test le verrouille.
3. **Les frais de paiement y figurent, sur leur propre ligne.** L'acheteur a été débité de 10 200 pour un billet à 10 000 : sans cette ligne il ne peut pas réconcilier avec son relevé mobile money, et un écart inexpliqué se lit comme une tromperie (décision D-023).

**Référence déterministe**, dérivée de celle de la commande (`PS241-9GG6-TY7` → `RC-9GG6-TY7`). Le même reçu régénéré six mois plus tard porte le même numéro, sans colonne ni séquence à maintenir, et l'unicité découle de celle de la commande.

**Le document reflète l'état actuel**, remboursements compris : un reçu qui continuerait à attester un paiement intégral après remboursement serait faux. Il reste émis après remboursement — `hasBeenPaid()` et non `isPaid()` : une commande remboursée n'est plus payée, mais elle l'a été, et c'est cela que le reçu atteste. Sur une commande jamais payée, il est refusé : attester un paiement qui n'a pas eu lieu serait un faux.

**Joint à l'email une seule fois**, contrairement aux billets qui le sont un par un : il porte sur la commande entière, le dupliquer par billet n'aurait aucun sens comptable.

Le numéro de paiement n'apparaît que par ses quatre derniers chiffres (section 52) : un reçu se transmet et finit archivé.

---

## D-028 — Annulation d'événement : le client est remboursé frais compris
**2026-08-25**

Quand l'organisateur annule son événement, tous les acheteurs sont remboursés **intégralement, frais de paiement compris**. C'est l'organisateur qui les supporte.

**Pourquoi un chemin distinct de la demande client.** Ici l'acheteur n'a rien demandé et n'y est pour rien. Lui faire supporter le coût d'une décision qui n'est pas la sienne serait indéfendable — alors que lorsqu'il change d'avis, les frais restent chez l'opérateur (décision D-023). Trois différences en découlent, et aucune ne se réduit à un paramètre :

1. Les frais sont rendus.
2. Aucune décision n'est demandée : les demandes naissent déjà approuvées, il n'y a rien à arbitrer.
3. Le délai de 48h ne s'applique pas. Il protège l'organisateur des changements d'avis tardifs ; il n'a aucun sens quand c'est lui qui annule.

**Une colonne `origin` sur `refund_requests`**, et un booléen `includes_psp_fee`. Sans eux, `psp_fee_amount` serait ambigu : la même colonne signifierait « frais retenus » dans un cas et « frais rendus » dans l'autre, et aucun rapport ne serait fiable.

**La commission est rendue sur la PART BILLETS seulement.** Les frais n'ont jamais transité par l'organisateur : il n'y a aucune commission à leur rendre. D'où `RefundRequest::ticketPortion()`, utilisé comme assiette d'ajustement dans les deux chemins.

**Les remboursements se font hors de la transaction d'annulation** : chaque commande appelle le prestataire, et une centaine d'appels réseau n'ont rien à faire dans une transaction de base de données. L'annulation, elle, est déjà actée — c'est ce qui compte pour les acheteurs qui consultent la page publique. Un échec individuel est journalisé sans interrompre : un acheteur dont le remboursement échoue ne doit pas empêcher les autres d'être remboursés.

Un billet déjà remboursé n'est pas remboursé une seconde fois, et un billet déjà utilisé reste utilisé.

### Notifications ajoutées (section 69)

- **Décision de remboursement au client.** Seul canal par lequel il l'apprend : il n'a pas de compte, et rien ne le ramène sur sa page de suivi. Sans cet email, un refus ne lui serait jamais expliqué — or la section 24.1 exige qu'il soit motivé.
- **Reversement parti à l'organisateur**, envoyé dès que le prestataire **accepte** l'ordre et non seulement quand le virement aboutit : un reversement programmé reste en attente plusieurs heures. L'email détaille l'éclatement brut / commission / net, parce qu'un montant seul, inférieur au chiffre du tableau de bord, se lit comme une erreur.
- **Demande fermée sans réponse**, pour ne pas laisser le client croire qu'elle est toujours à l'étude.

Aucune de ces notifications ne peut interrompre l'opération qu'elle accompagne, et aucune ne contient un numéro complet.

---

## D-027 — Remboursements : le client demande, l'organisateur décide
**2026-08-25**

Modèle hybride de la section 24.1, implémenté tel quel. Le client **ne déclenche jamais** un remboursement : il ouvre un dossier, et rien d'argent ne bouge tant que l'organisateur n'a pas tranché.

### La fenêtre de 48h n'est pas une politique commerciale

C'est la pierre angulaire du modèle financier. Le reversement intervient **après** l'événement (décision D-026) ; fermer les demandes **avant** garantit qu'aucun remboursement n'exigera jamais de récupérer des fonds déjà partis chez l'organisateur — auprès de gens qui ont peut-être déjà dépensé l'argent. Réduire `payments.refunds.window_hours` sans revoir le reversement ferait de Pass241 le créancier de ses propres clients. Le fichier de configuration le dit à l'endroit où quelqu'un serait tenté de le changer.

### Les frais de paiement ne sont pas remboursés

Ils ont été versés à l'opérateur, qui ne les rend pas (décision D-023). Les rembourser quand même reviendrait à les faire supporter par Pass241 à chaque changement d'avis d'un acheteur. C'est annoncé avant le paiement, rappelé au moment de la demande, et rappelé à l'organisateur sur son écran de décision pour qu'il ne croie pas devoir les rendre.

### La commission est ajustée par une ligne nouvelle

La table des commissions interdisait toute seconde ligne pour une même transaction. La contrainte n'a pas été supprimée mais **resserrée** : elle ne porte plus que sur les lignes d'origine, via un index partiel. C'est elle qui rend le calcul de commission idempotent face à un webhook rejoué (section 23) ; la perdre aurait rouvert cette faille.

Un ajustement porte `parent_commission_id` et reprend **le taux figé de la ligne d'origine**, jamais celui de la configuration du jour : rembourser au taux d'aujourd'hui une transaction facturée au taux d'hier produirait un écart que personne ne saurait expliquer. Un déclencheur interdit qu'un ajustement pointe vers un autre ajustement — une chaîne rendrait le cumul invérifiable.

Conséquence sur le reversement : `SettlementLedger` **soustrait** les ajustements et garde les commandes partiellement remboursées, amputées de la part rendue. Les exclure entièrement priverait l'organisateur des billets non remboursés de la même commande ; les ignorer lui reverserait de l'argent déjà rendu au client.

### Deux bugs trouvés en écrivant les tests, tous deux réels

1. **`getOriginal()` rend l'énum, pas la chaîne.** Le garde-fou censé empêcher de rouvrir une demande tranchée se déclenchait sur la *première* décision. Corrigé avec `getRawOriginal()`.
2. **`RefundPolicy` échouait en OUVERT.** L'acheteur n'appartient à aucune organisation : la lecture de `$order->event` revenait `null` sous la portée globale, la date limite devenait inconnue, et la fenêtre était alors considérée comme ouverte **pour tout le monde, indéfiniment**. L'événement est désormais relu hors portée, et l'absence de date bloque la demande au lieu de l'autoriser. Une règle financière ne doit jamais échouer en ouvert.

### Le reste

`EXPIRED` est distinct de `REJECTED` : le client a demandé dans les temps et personne n'a répondu. La distinction compte pour arbitrer un litige. Une commande planifiée horaire ferme ces demandes — laissées ouvertes, elles bloqueraient l'argent de l'organisateur sans que personne ait rien décidé.

Un index partiel n'autorise **qu'une demande en cours** par billet : un client refusé peut en soumettre une nouvelle si sa situation change, mais ne peut pas en empiler dix pour forcer la main à l'organisateur.

Un refus doit être motivé (section 24.1) ; une acceptation non. Un billet **déjà utilisé** reste utilisé malgré le remboursement : celui-ci règle la question de l'argent, il n'annule pas une entrée déjà effectuée. L'appel au prestataire se fait hors transaction — un service indisponible ne doit pas faire croire au client que sa demande a été refusée.

---

## D-026 — Reversement après l'événement, un virement par événement
**2026-08-25**

Le net revient à l'organisateur **après son événement**, en un seul virement, déclenché par une tâche planifiée. Aucun bouton « retirer » n'existe nulle part dans le produit.

**Pourquoi après l'événement, et pas plus tôt.** La fenêtre de remboursement se ferme **48h avant** l'événement (section 24.1). Reverser après garantit donc qu'aucun remboursement n'exigera jamais de récupérer des fonds déjà partis chez l'organisateur — ce qui ferait de Pass241 le créancier de ses propres clients, auprès de gens qui ont peut-être déjà dépensé l'argent. Les 24 heures supplémentaires absorbent les incidents du jour même : annulation sur place, salle fermée, réclamation massive à la sortie.

**Un virement par événement, pas par commande.** Moins de frais fixes, et un relevé lisible pour l'organisateur.

### La distinction qui rend la promesse vraie

Deux dates différentes, et les confondre viderait la décision de son sens :

- **date de libération** — quand les fonds arrivent : `fin de l'événement + délai` ;
- **date d'instruction** — quand l'ordre part chez le prestataire.

Si le prestataire sait programmer un virement (`scheduledPayout`), l'ordre part **dès la fin de l'événement** avec une date d'exécution future : les fonds restent dans **ses** livres jusque-là, et Pass241 n'en est jamais dépositaire. S'il ne sait pas, l'ordre attend l'échéance et l'argent dort entre-temps sur le compte marchand de la plateforme — c'est exactement ce que `PaymentCapabilities::holdsFunds()` signale, et cela reste un critère éliminatoire dans le choix du PSP.

### Ce qui empêche de payer deux fois

Une somme envoyée en double ne se récupère pas. Quatre barrières indépendantes :

1. Contrainte d'unicité sur `event_id` **et** sur `idempotency_key` en base : deux workers simultanés ne peuvent pas produire deux ordres. La violation est rattrapée et la ligne existante reprise.
2. La clé d'idempotence est transmise au prestataire, qui doit reconnaître un ordre déjà reçu.
3. Un ordre déjà accepté n'est jamais renvoyé. Seul un échec **explicite** autorise une nouvelle tentative.
4. **Un état inconnu n'est jamais un échec.** Une exception réseau non plus — le prestataire a pu recevoir l'ordre avant que la connexion ne tombe. Seule la commande de réconciliation peut transformer un état inconnu en échec, et c'est le seul endroit du code qui en a le droit.

### Ce qui est figé, et pourquoi

Les montants viennent **exclusivement** des lignes de commission déjà figées — jamais d'un recalcul à partir des prix. La commission porte un instantané de son taux : recalculer six mois plus tard donnerait un chiffre différent de celui annoncé.

Le bénéficiaire est un **instantané**, jamais une jointure vers l'organisation. Si le numéro change le lendemain, ce reversement doit continuer à dire vers où l'argent est parti — c'est précisément la trace qu'on cherche quand on soupçonne un détournement.

Un déclencheur PostgreSQL refuse toute modification des montants, du bénéficiaire, de l'événement et de la clé d'idempotence. Le statut, lui, évolue — c'est la différence avec la table des commissions, immuable de bout en bout : ici le cycle de vie est réel, seule la partie financière ne doit jamais bouger. La suppression est interdite, et les clés étrangères sont en `restrictOnDelete` : supprimer une organisation effacerait la trace d'un virement réellement parti.

**Balayage planifié plutôt que job différé** posé à la création de l'événement : une date d'événement se modifie, un worker redémarre, une file se vide. Un job programmé des semaines à l'avance survit mal à tout cela, et un reversement oublié ne se remarque que le jour où l'organisateur téléphone.

**Écart assumé par rapport au plan** : pas de classe `SettlementStrategy`. La distinction annoncée entre « instruire tôt » et « payer tard » tient en deux méthodes de `SettlementLedger` pilotées par les capacités. Une hiérarchie de stratégies sans seconde implémentation réelle aurait coûté plus qu'elle n'aurait rapporté.

---

## D-025 — Destination de reversement sans pièces justificatives ni vérification par code
**2026-08-25**

L'organisateur renseigne un numéro mobile money et son opérateur. C'est tout. **Aucune pièce justificative n'est demandée, et aucune vérification par code n'est envoyée.**

**Pourquoi c'est défendable.** Le compte mobile money de destination est déjà vérifié par Airtel ou Moov, qui exigent une pièce d'identité à l'ouverture. Il y a donc bien une identité derrière ce numéro — Pass241 ne la collecte simplement pas lui-même. Combiné au reversement après l'événement (décision à venir, jalon D), le risque principal reste couvert : un événement fictif ne se tient jamais, et les réclamations arrivent avant que les fonds ne partent.

**Conséquence sur `canReceivePayouts()`.** La condition portait sur `kyc_status === VERIFIED`. Elle porte désormais sur : destination renseignée, organisation non suspendue, et compte **non rejeté** par le Super Admin. Le changement n'est pas cosmétique — garder `VERIFIED` aurait bloqué tout le monde, plus personne ne soumettant de dossier. `kyc_status` subsiste comme levier du Super Admin pour couper les fonds d'un compte suspect.

**Le dispositif KYC n'est pas supprimé** (règle 81), il est éteint : `config('payments.payouts.require_kyc_documents')`, à `false`. Le formulaire, l'action `SubmitKyc`, les documents et la revue restent fonctionnels et testés, prêts à être rallumés le jour où le volume, un prestataire ou le régulateur l'exigeront.

**Ce qui remplace la vérification, sans ajouter de friction :**

1. **Le nom du titulaire** renvoyé par le prestataire est conservé et affiché : « vous recevrez vos recettes sur le 074XXXXXX, au nom de … ». L'organisateur ne fait rien, il lit. C'est le seul garde-fou restant contre une faute de frappe — un virement mobile money ne se rappelle pas, et plusieurs centaines de milliers de francs partent définitivement chez un inconnu.
2. **Tout changement est journalisé à part**, avec `PAYOUT_DESTINATION_CHANGED` distinct de `PAYOUT_DESTINATION_SET`. Confondre les deux empêcherait de repérer une redirection de fonds parmi les premières saisies. Le numéro précédent est conservé — sans lui, une trace ne dit pas vers où les fonds ont été détournés — mais **masqué**, le journal étant consulté et exporté : il doit permettre de reconnaître un numéro, pas de le recopier (section 52).
3. **Tout changement est notifié par email** à l'organisation. C'est le dispositif de détection d'une prise de contrôle de compte : la victime reçoit un message qu'elle n'attendait pas. L'email ne contient que les quatre derniers chiffres — il transite en clair et finit archivé.

Une destination identique ne produit ni écriture, ni entrée de journal, ni notification : une alerte qui n'apprend rien banalise les vraies.

**Les colonnes sont hors de `$fillable`** et n'ont qu'un seul chemin d'écriture, `SetPayoutDestination`. Rediriger les fonds vers un autre numéro est le vecteur de fraude central une fois qu'une plateforme sait payer : aucun champ de formulaire ne doit pouvoir les atteindre par affectation en masse.

---

## D-024 — Abstraction du prestataire scindée : encaisser et reverser
**2026-08-25**

`PaymentProviderInterface` est remplacée par deux contrats distincts :

- **`CollectionProvider`** — encaissement. Toujours disponible. Porte aussi le remboursement, qui annule un encaissement et repart vers l'acheteur.
- **`SettlementProvider`** — reversement vers l'organisateur. Peut ne pas exister.

**Pourquoi les séparer.** Un agrégateur peut savoir prendre un paiement mobile money sans exposer la moindre API de virement sortant. Les réunir obligeait chaque driver à mentir sur la moitié de son contrat. Désormais, un driver qui n'implémente pas `SettlementProvider` annonce par sa seule signature qu'aucun reversement automatique n'est possible avec lui — ce qu'il vaut mieux découvrir avant de signer qu'après le premier événement.

`PaymentManager::settlement()` **échoue bruyamment** plutôt que de rendre `null` : un appelant qui demande à reverser doit s'arrêter net. Rendre `null` laisserait passer un appel silencieux, et un reversement qui n'a jamais eu lieu ne se remarque que le jour où l'organisateur téléphone.

**Les capacités sont déclarées par le driver**, jamais lues en configuration : un prestataire ne sait pas éclater un encaissement parce qu'un fichier l'affirme. `PaymentCapabilities` porte `nativeSplit`, `payout`, `scheduledPayout`, `partialRefund`, `recipientNameLookup`, et deux questions dérivées :

- `canSettle()` — l'argent peut-il arriver chez l'organisateur autrement qu'à la main ?
- `holdsFunds()` — **Pass241 détient-il les fonds entre l'encaissement et le reversement ?** Vrai sans éclatement natif ni virement programmé. C'est exactement ce que la section 21.2 veut éviter ; la méthode existe pour que ce soit visible plutôt que subi, et pour servir de critère de choix du prestataire.

**Ce qui distingue le sortant de l'entrant, et qui a guidé le contrat :**

- `PayoutRequest` porte une **clé d'idempotence obligatoire**. Un webhook d'encaissement rejoué crée un doublon rattrapable ; un ordre de virement rejoué envoie l'argent deux fois, et une somme envoyée en double ne se récupère pas.
- `PayoutState::Unknown` ne doit **jamais** être traité comme un échec. Conclure trop vite à l'échec conduit à rejouer l'ordre, donc à payer deux fois.
- `PayoutState::Scheduled` distingue « accepté » de « arrivé ». Les confondre ferait annoncer à l'organisateur un argent qu'il n'a pas encore.
- `lookupRecipientName()` permet d'afficher le titulaire du numéro sans rien demander à l'organisateur — la vérification par code ayant été écartée. Un virement mobile money ne se rappelle pas : une faute de frappe est définitive.

`FakeProvider` implémente les deux contrats, mais **déclare `nativeSplit: false`**. Se donner la capacité la plus confortable en simulation conduirait à construire le reversement du jalon D sur une hypothèse qu'aucun prestataire gabonais n'a confirmée.

---

## D-023 — Frais du prestataire de paiement à la charge de l'acheteur
**2026-08-25**

Les frais du prestataire (PSP) sont **ajoutés au prix des billets et payés par l'acheteur**, affichés sur une ligne distincte avant le paiement.

**Pourquoi ce choix.** Le modèle annoncé — billet à 10 000 FCFA, 500 pour Pass241, 9 500 pour l'organisateur — ne laisse rien au prestataire, qui prélève pourtant de l'ordre de 1,5 à 3,5 % plus souvent une part fixe. Les trois issues possibles étaient : rogner la commission Pass241 (marge réelle ramenée à 1,5 %, voire négative sur les petits montants à cause de la part fixe), rogner le net de l'organisateur (l'exemple affiché devient faux), ou les facturer à l'acheteur. C'est la troisième qui a été retenue, à condition qu'ils soient annoncés — un acheteur qui découvre un écart sur son relevé se sent trompé, même quand la somme est juste.

**Ce que cela implique dans le code :**

- `orders.amount` cesse d'être le prix des billets pour devenir **le montant réellement débité**. Le prix des billets vit dans `orders.subtotal`.
- **La commission Pass241 s'assied sur `subtotal`, jamais sur `amount`.** Prendre le total pour assiette ferait prélever une commission sur l'argent de l'opérateur et gonflerait mécaniquement le net reversé à l'organisateur. C'est le piège principal de cette décision.
- Le barème appliqué (points de base + part fixe) est **figé sur la commande**, comme le taux de commission l'est déjà : une transaction doit rester explicable après un changement de prestataire.
- Une colonne `orders.psp` enregistre l'agrégateur ayant traité la transaction, distincte de `payment_provider` (AIRTEL ou MOOV, choisi par l'acheteur). Sans elle, changer d'agrégateur rendrait l'historique irréconciliable.
- Deux contraintes `CHECK` en base : `amount = subtotal + psp_fee_amount`, et des montants positifs. Un total qui ne se reconcilie pas avec ses parts est une erreur comptable qui passerait inaperçue dans un rapport.

**Arrondi au franc SUPÉRIEUR**, contrairement à la commission qui arrondit au plus proche. L'asymétrie est voulue : un franc de commission en moins est une recette manquée, un franc de frais en moins est un découvert sur le compte marchand que quelqu'un devra combler, transaction après transaction.

**Un prestataire sans barème configuré fait échouer la commande**, volontairement. Sans cette règle, brancher un vrai PSP sans renseigner ses frais les ferait absorber en silence par Pass241.

**Sort des frais en cas de remboursement** : non remboursables quand c'est l'acheteur qui demande (annoncé à l'écran avant le paiement) ; remboursés quand l'organisateur annule l'événement, et c'est alors lui qui les supporte. À implémenter au jalon E.

**Valeurs actuelles fictives** : le barème du driver `fake` (2 %) existe pour que le parcours montre la ligne de frais en développement. Aucun prestataire ne l'a confirmé.

---

## D-021 — Le billet se télécharge en image, le PDF ne part que par email
**2026-08-25**

Le bouton « Télécharger » de la page billet produit une **image PNG**, capturée dans le navigateur sur le nœud DOM du billet affiché. Le PDF n'est plus téléchargeable depuis la plateforme : la route publique est supprimée, et le document part uniquement en pièce jointe de l'email de confirmation (un fichier par billet).

**Raison.** Trois mises en page du même objet — écran, PDF, image — divergent inévitablement, et c'est exactement ce qui s'était produit : le PDF avait pris du retard sur l'écran. Capturer le DOM réel supprime le problème à la racine, l'image *étant* le billet affiché, verre liquide compris. Le PDF garde sa raison d'être propre : une pièce jointe qu'on archive, qu'on transfère et qu'on imprime.

**Coût assumé** : une dépendance front (`html-to-image`), la seule du projet. Elle est chargée par `import()` dynamique au moment du clic — Vite en fait un fragment séparé de 12 ko, jamais demandé par un visiteur qui ne télécharge pas son billet. `app.js` reste à 3,5 ko.

**Deux pièges rencontrés, à ne pas réintroduire :**

1. La capture recopie les styles du nœud capturé dans l'image produite. Un `left: -10000px` posé sur la scène hors écran déplaçait donc son contenu de 10000 pixels *à l'intérieur* de l'image, qui ressortait vide aux bonnes dimensions. D'où deux éléments distincts : un conteneur qui positionne, une scène qui décore et qu'on capture.
2. `backdrop-filter` ne produit rien pendant une capture : la technique repose sur un `<foreignObject>` SVG, qui n'a pas de fond à flouter. Une règle `[data-ticket-capture] .glass` remplace le flou par la teinte de repli déjà prévue pour les appareils modestes.

**Conséquence sur l'email** : la pièce jointe contient le QR, qui circule donc hors de tout contrôle. Sans conséquence — le billet est nominatif (section 16) et un seul scan le consomme (section 19) : une copie transmise ne crée pas une seconde entrée, elle vole la première.

**Conséquence sur la confirmation de paiement** : le rendu des PDF est sorti de la requête vers un job (`SendTicketsEmail`). Le webhook de l'opérateur mobile money abandonne s'il attend, et la confirmation d'un paiement ne peut pas payer le prix d'une mise en page.

---

## D-022 — Contraintes du moteur de rendu PDF, mesurées
**2026-08-25**

Le billet PDF sortait sur deux pages, sans QR visible, blocs mal ajustés. Les causes, vérifiées une à une en rastérisant le document plutôt qu'en supposant :

| Symptôme | Cause | Correction |
|---|---|---|
| QR absent, débordement sur une 2ᵉ page | dompdf n'applique pas les dimensions CSS à un **SVG inline** : le code sortait à sa taille intrinsèque (480px ≈ 127mm) | QR rendu en **PNG** (`QrRenderer::pngDataUri`) |
| Affiche minuscule dans un coin | `background-size: cover` n'existe pas | Image recadrée au rapport du cadre côté serveur (GD), posée en `<img>` |
| Voile sombre à arête franche | Aucun dégradé disponible | Dégradé **gravé dans l'image** ligne par ligne (`TicketPdf::burnScrim`) |
| Affiche à 28mm dans un cadre de 36mm | Hauteur imposée à la fois au cadre et à l'image | Hauteur sur le cadre seul ; l'image n'est dimensionnée que par sa largeur |
| Titre de l'événement disparu | Sans hauteur de référence, `bottom: 0` sort le bloc du cadre, `overflow: hidden` l'efface | Hauteur explicite sur le cadre |
| Liseré sous l'affiche | Espace de ligne réservé sous l'image ; `line-height: 0` et `font-size: 0` font disparaître le titre avec lui | Fond du cadre à la teinte sombre du bas du dégradé |
| Pied de page éclaté sur 4 lignes | URL signée de 150 caractères | Domaine + référence de commande, réellement saisissables |

**Règle qui en découle** : les encoches latérales n'existent que sur le billet affiché. Le moteur les rogne au rayon du bandeau, et sur du papier un demi-cercle peint de la couleur du fond ne se lit comme une déchirure que si la feuille est de cette couleur. La ligne de déchirure, elle, s'imprime.

**Budget de hauteur** : le contenu occupe environ 242mm sur les 297mm d'une A4, le reste centrant le billet sur la feuille. Ajouter un bloc impose d'en retirer un — une seconde page sort de l'imprimante avec la moitié du billet dessus, et personne ne sait laquelle présenter à l'entrée.

**Poids** : l'affiche est réencodée en JPEG à 1000px de large. Une commande de dix billets produisait sinon un email de plusieurs mégaoctets, que les messageries rejettent — or l'email est le canal de secours du billet.

---

## D-020 — `storage/framework` dans un volume Docker
**2026-08-25**

Les vues compilées, le cache et les sessions vivent dans un volume Docker, plus sur le montage Windows.

**Raison** : Blade échoue à horodater ses fichiers compilés sur ce montage (`touch(): Utime failed: Operation not permitted`). Le `chmod` de la décision D-011 accorde l'écriture, mais `utime` exige la **propriété** du fichier, que le montage ne peut pas accorder. Toute page dont la vue n'était pas déjà compilée renvoyait une erreur 500.

Même famille que la décision D-013 : ce qui est généré par la machine n'a rien à faire sur le montage. `storage/logs` y reste — celui-là, on veut le lire depuis Windows.

---

## D-017 — Réservation à chaque commande, stock déduit de la base
**2026-08-25** — amende la section 17.1

La section 17.1 limitait la réservation au **dernier billet restant**, pour ne pas bloquer du stock abondant. Elle a été réécrite : **toute commande réserve ses billets**, et la confirmation du paiement passe par une vérification atomique du stock.

**Raison.** L'ancienne règle laissait plusieurs commandes s'ouvrir simultanément sur le même stock dès qu'il restait plus d'un billet. Avec 10 places et 15 acheteurs simultanés, 15 paiements pouvaient aboutir. Les deux inconvénients en balance ne se valent pas : afficher « complet » quelques minutes fait perdre une vente possible, tandis qu'une survente oblige à annoncer à quelqu'un qui a déjà payé qu'il n'a pas de place.

La crainte d'origine résiste d'ailleurs mal au calcul : pour qu'une réservation fasse paraître un tarif complet, il faut autant de commandes en attente simultanées qu'il reste de places. Sur 300 billets, cela signifie 300 personnes en train de payer dans la même fenêtre — l'événement se remplit réellement, et la disponibilité réduite est alors exacte.

**Conséquence technique : Redis sort du chemin critique.** La réservation est déjà modélisée en base — une commande `PENDING` dont l'expiration n'est pas atteinte. Le stock se calcule donc directement :

```
disponible = quantité − vendus − réservés
```

Aucun état séparé à synchroniser, aucune expiration à orchestrer : la condition sur la date d'expiration fait expirer les réservations d'elle-même. **Si Redis tombe ou si la file d'attente s'arrête, le stock reste juste** — ce qui n'était pas le cas avec les verrous Redis. Cela supprime la catégorie de bugs rencontrée deux fois au jalon 3, où des verrous n'étaient pas libérés faute de contexte d'organisation dans les workers.

`ExpireOrderIfUnpaid` reste nécessaire pour marquer la commande en échec et l'auditer, mais n'est plus porteur de l'exactitude du stock.

**Conséquence sur le paiement tardif.** Un paiement confirmé après l'expiration est accepté si le stock le permet encore, sinon remboursé. C'est ce que la vérification atomique rend possible, et c'est le meilleur résultat pour l'acheteur dans la grande majorité des cas.

**Supprimé** : `StockHoldService` et les verrous Redis associés.

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
