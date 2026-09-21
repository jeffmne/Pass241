# Securite, performances et resilience — septembre 2026

## Changements

- Achats et invitations verrouillent les tarifs avant de verifier et reserver
  le stock. Les paniers et confirmations prennent les verrous dans le meme
  ordre. Le stock financier reste lu dans PostgreSQL ; aucun cache Redis ne
  decide de la vente.
- L'affichage public regroupe les compteurs de tous les tarifs en un agregat.
  Le panier reutilise ses modeles pendant la requete. Ces instantanes ne sont
  ni envoyes par le client, ni utilises pour autoriser une commande.
- Les callbacks utilisent la file `payments`, les verifications de secours
  `payment-checks`, les PDF/emails `default`. Horizon doit consommer les trois.
  Le suivi consulte PostgreSQL toutes les dix secondes quand il est visible,
  et n'attend plus le PSP dans la requete HTTP. Les controles de secours sont
  uniques en file et espaces d'au moins vingt secondes par commande.
- Une ancienne reponse de paiement en echec ne peut plus ecraser une
  confirmation plus recente. Une annulation est reverifiee sous verrou.
- Les donnees JSON-LD echappent les balises HTML ; les tarifs caches ne sont
  plus achetables en injectant leur identifiant.
- Le mobile enregistre verdict et utilisation du billet dans une transaction
  SQLite exclusive. Il reutilise une requete preparee pour chaque page de
  billets et indexe l'historique. Une ouverture simultanee de la base partage
  la meme initialisation.
- Les telechargements interrompus reprennent apres la derniere page stockee.
  La borne incrementale reste celle du debut du telechargement : les
  modifications intervenues sur les premieres pages sont relues ensuite.
- Les evenements prepares s'ouvrent immediatement depuis le cache. Les
  synchronisations automatiques sont suspendues hors reseau et en arriere-plan,
  puis reprennent au retour. Le delai HTTP couvre aussi la lecture du corps.
  La camera est arretee quand le scanner est masque. Les scans remontent
  immediatement, mais le telechargement des billets est espace de trente
  secondes. Une version mobile de production exige une URL HTTPS.
- Nginx limite PHP au controleur frontal, ajoute des en-tetes de protection
  et met les fichiers Vite portant une empreinte en cache navigateur long.

Le hors connexion suppose une connexion et un telechargement prealables. Deux
telephones completement isoles ne peuvent pas connaitre mutuellement leurs
scans ; le serveur arbitre les conflits a la reconnexion. Les nouvelles ventes
et revocations ne sont connues qu'apres synchronisation. Les achats Mobile
Money restent sur le web et necessitent le reseau.

## Verifications

Depuis `mobile` : `npm run typecheck` puis `npm test -- --runInBand` (Node 24
pour les tests SQL via `node:sqlite`). Les tests utilisent le vrai moteur
SQLite, avec un adaptateur du pont Expo ; un essai sur appareil reste necessaire.

Depuis la racine, Docker fonctionnel :

```sh
docker compose exec -T app php artisan test
docker compose exec -T app vendor/bin/pint --test
docker compose exec -T nginx nginx -t
```

Le test de concurrence utilise des processus et connexions PostgreSQL
independants, vingt acheteurs par defaut, deux tarifs dont les paniers sont
inverses, et dix places chacun. Il exige exactement dix commandes, dix refus
de stock et aucune erreur technique. Il conserve les donnees pour diagnostic.
Utiliser la base de tests deja migree par la suite, jamais la base metier :

```sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=pass241_testing -e DB_URL= -e PAYMENT_DEFAULT_DRIVER=fake -e CACHE_STORE=array app php tests/Load/reservations.php 20
```

## Objectif 10 000 acheteurs

**Capacite non certifiee par ces changements.** Le scenario k6 exerce la vraie
page publique, les cookies, CSRF, le panier Livewire, la reservation et le
suivi. Il ne simule ni un paiement confirme ni la livraison des billets : ces
deux etapes doivent etre mesurees separement avec les callbacks signes du
prestataire de test, leur rejeu, les workers et les delais de livraison.

Sur une preproduction dediee avec paiement `fake`, creer un evenement public,
un tarif visible avec au moins 10 000 places, relever son identifiant et
charger une base representative du volume attendu. Avec k6 installe :

```sh
k6 run -e BASE_URL=https://preproduction.example -e EVENT_PATH=/e/evenement-charge -e TICKET_TYPE_ID=123 -e ALLOW_PURCHASE_LOAD_TEST=1 -e VUS=20 backend/tests/Load/checkout.k6.js
```

Rejouer avec `VUS=100`, `1000`, puis `10000` sur une infrastructure et des
generateurs dimensionnes. Chaque utilisateur ne reserve qu'une fois. Attendre
l'expiration des commandes ou preparer un nouvel evenement entre les essais.
Des reponses HTTP 200 contenant une erreur de stock ou de validation sont
comptees comme des echecs, jamais comme des achats reussis.

Seuils initiaux du scenario : moins de 1 % d'erreurs, plus de 99 % de
reservations reussies, p95 achat < 3 s et p99 < 8 s. Enregistrer egalement CPU,
memoire, saturation PHP-FPM, connexions et attentes de verrous PostgreSQL,
delais des trois files Horizon, erreurs PSP et nombre exact de billets emis.
Verifier absence de survente, doublons et commandes payees sans billets.

Le verrou d'un tarif tres populaire serialise les reservations : il protege
la justesse, mais ne constitue pas une preuve de debit. Si cette attente
devient dominante, mesurer puis introduire un compteur transactionnel de
reservations ou une salle d'attente. Ne pas remplacer la verification par un
cache de stock eventuellement obsolete.

## Deploiement

- Plusieurs instances web derriere un repartiteur, PHP-FPM dimensionne selon
  la RAM mesuree, OPcache actif et caches Laravel construits au deploiement.
- Redis partage pour sessions, cache, verrous de jobs et queues. PostgreSQL
  avec connexions bornees ; ajouter un pool si les mesures le justifient.
- Demarrer les nouveaux superviseurs Horizon lors du deploiement. Ajuster
  `HORIZON_PAYMENT_PROCESSES` et `HORIZON_PAYMENT_CHECK_PROCESSES` selon les
  ressources et les quotas du PSP ; ne pas multiplier aveuglement les workers.
- La route webhook a une limite existante de 240 requetes/minute par origine.
  Elle doit etre dimensionnee avec le PSP avant le test des confirmations de
  masse, en preservant verification des signatures et protection en amont.
- HTTPS, `APP_DEBUG=false`, cookies securises ; ne pas exposer PostgreSQL,
  Redis ou les ports du compose de developpement sur Internet.
- Recharger Nginx pour appliquer sa configuration. Ne pas mettre en cache
  public les pages signees de billets, commandes ou donnees personnelles.

## Etat de validation dans cet environnement

Tests mobiles et verification TypeScript executes ; tests backend et charge a executer apres reparation
de Docker Desktop, bloque au demarrage par son service `dockerInference`.
Le PHP Windows 8.2 ne satisfait pas la version du projet. Aucun test a
10 000 utilisateurs et aucun deploiement en production n'ont ete effectues.
