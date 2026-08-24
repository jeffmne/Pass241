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

## 87. DASHBOARD ORGANISATEUR — VOTES

Extension future du dashboard organisateur (section 56). Doit afficher, par campagne de vote :

- candidats ;
- nombre de votes ;
- classement ;
- statistiques.

---
