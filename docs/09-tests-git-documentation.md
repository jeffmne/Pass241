# Tests, Git & documentation

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 74. TESTS

**Auth** : connexion, déconnexion, permissions, sessions.

**Multi-tenancy** : isolation organisation A/B, permissions, accès interdit.

**Événements** : création, modification, publication, annulation, page publique.

**Billetterie** : billet gratuit, billet payant, stock, hold, commande, QR Code.

**Paiement** : succès, échec, timeout, webhook, double webhook, remboursement.

**Contrôle** : valide, invalide, déjà utilisé, remboursé, offline, synchronisation, conflit de double scan.

**Collectes** : don anonyme, don nominatif, montant correct, pourcentage correct, historique, don confirmé, don échoué, remboursement partiel/total, impossibilité de suppression, impossibilité de modification, impossibilité de falsifier le montant/pourcentage.

**Commission** : calcul correct par transaction, snapshot du taux, recalcul au prorata en cas de remboursement partiel.

**Délégation** : CO_ORGANIZER limité aux droits accordés, accès finances refusé par défaut.

---

## 75. TESTS ANTI-FRAUDE DES COLLECTES (obligatoires)

**Test 1** — L'organisateur tente de supprimer un don confirmé → `403 Forbidden`

**Test 2** — L'organisateur tente de modifier le montant d'un don → `403 Forbidden`

**Test 3** — L'organisateur envoie `{"collectedAmount": 10000000}` → valeur ignorée ou requête rejetée

**Test 4** — L'organisateur tente de supprimer un donateur → `403 Forbidden`

**Test 5** — Un utilisateur modifie le frontend pour afficher 100 % → le backend continue à retourner la vraie valeur

**Test 6** — Un webhook de paiement est envoyé deux fois → un seul don confirmé

**Test 7** — Un don échoué est envoyé comme confirmé par le frontend → refusé

---

## 76. GIT

Utiliser des commits explicites, par exemple :

```
feat: add event creation
feat: add public event page
feat: implement ticket checkout
feat: generate secure ticket QR
feat: implement airtel money
feat: implement moov money
feat: add access scanner
feat: add donation campaigns
feat: add anonymous donations
feat: add donation transparency
feat: protect donation records
feat: add donation audit logs
feat: add commission calculation
feat: add event delegation (co-organizer)
fix: prevent duplicate ticket validation
fix: prevent duplicate payment webhook
fix: prevent donation manipulation
```

---

## 77. DOCUMENTATION

Documenter : architecture, API, authentification, multi-tenancy, permissions, paiements, webhooks, QR Codes, contrôle offline, synchronisation, billetterie, collectes, dons, anonymat, commission, délégation, audit, sécurité.

---

