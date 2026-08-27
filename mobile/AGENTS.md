# Application agent Pass241

Client de controle d'acces, section 27 de [../CLAUDE.md](../CLAUDE.md). Les
regles absolues du projet s'appliquent ici comme ailleurs — en particulier la
regle 80.6 : **aucun emoji nulle part**, y compris dans les indicateurs d'etat
que la spec illustrait avec des pastilles de couleur.

## Expo a change

Lire la documentation versionnee — https://docs.expo.dev/versions/v57.0.0/ —
avant d'ecrire du code. Les API de `expo-camera` et `expo-sqlite` ont ete
refondues ces dernieres versions, et les exemples anciens ne compilent plus.

## Ce que cette application ne fait pas

Section 27.18, sans exception : pas de vente de billets, pas de gestion
d'evenements, pas de creation d'agents, pas de finance, pas de statistiques
avancees. Tout cela reste sur la plateforme web. Une fonctionnalite qui ne sert
pas a faire entrer quelqu'un plus vite n'a rien a faire ici.

## Les trois regles qui tiennent le reste

1. **La decision est locale, le verdict est du serveur.** L'agent voit un
   resultat immediatement, hors connexion comprise (section 27.7) ; le serveur
   arbitre ensuite et peut contredire (section 31.1). L'application remplace son
   verdict sans discuter.

2. **Aucun secret sur l'appareil.** La base locale ne contient que des
   EMPREINTES de jetons, jamais les jetons — un telephone d'agent se perd a
   l'entree d'un concert. Le seul jeton conserve est celui d'un controle pas
   encore remonte, et il est efface des que le serveur repond.

3. **Un controle enregistre ne se perd jamais.** Ecriture locale AVANT toute
   tentative reseau, file rejouable, idempotence garantie par le serveur sur
   (appareil, billet, horodatage local).

## Ou vit quoi

- `src/domain/` — logique pure, sans reseau ni base. C'est la que se joue la
  justesse, et c'est la que portent les tests (`npm test`).
- `src/db/` — SQLite local (billets, file de scans, meta).
- `src/api/` — la surface reseau, et elle seule.
- `src/services/` — le controle d'un billet, et la synchronisation.
- `src/screens/` — cinq ecrans, pas un de plus.

## Verifications

```
npm run typecheck   # tsc --noEmit
npm test            # jest, sur les modules purs
```

## Sur un vrai appareil

Expo Go suffit : caméra, SQLite, stockage sécurisé, empreintes et état du
réseau sont tous inclus dans Expo Go pour le SDK 57.

1. `EXPO_PUBLIC_API_URL` dans `.env` doit être l'adresse de la machine **vue du
   téléphone** — jamais `localhost`, qui y désigne le téléphone lui-même.
2. Téléphone et machine sur le même réseau.
3. Pare-feu Windows : ports 8000 (serveur) et 8081 (bundler) en entrée.
4. `npm start`, puis le QR Code dans Expo Go — ou l'URL `exp://…:8081` saisie à
   la main.

Test le plus rapide en cas de doute : ouvrir `http://<ip>:8000` dans le
navigateur du téléphone. Si la page d'accueil s'affiche, le réseau n'est pas en
cause.
