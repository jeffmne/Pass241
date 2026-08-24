# Dashboards & exports

> Module de [CLAUDE.md](../CLAUDE.md) — projet **Pass241**. Les règles absolues (section « RÈGLES ABSOLUES ») et le modèle financier priment toujours sur ce fichier en cas de doute.

---

## 56. DASHBOARD ORGANISATEUR

Le dashboard est l'espace principal du client SaaS.

```
Dashboard
├── Vue générale
├── Événements (Tous les événements, Créer, Archivés)
├── Billetterie (Billets, Commandes, Participants, Invitations, Codes promo)
├── Contrôle d'accès (Agents, Points d'entrée, Scans)
├── Collectes (Campagnes, Dons, Statistiques)
├── Votes (fonctionnalité future — Phase 9, section 86)
├── Finances (Revenus, Transactions, Commissions, Remboursements, Factures)
├── Rapports
└── Paramètres
```

---

## 57. DASHBOARD ORGANISATEUR — COLLECTES

Peut afficher : total collecté, nombre de campagnes, nombre de dons, campagnes actives/terminées, progression moyenne, et par campagne : objectif, collecté, pourcentage, nombre de dons, date de fin, statut.

**IMPORTANT** : l'organisateur peut consulter les données. Il ne peut pas falsifier les chiffres.

---

## 58. DASHBOARD SUPER ADMIN

Le Super Admin peut voir : organisations, utilisateurs, événements, billets, transactions, remboursements, campagnes, dons, activité, logs, statistiques globales.

Les données financières historiques restent protégées contre les modifications arbitraires.

---

## 59. EXPORTS

Permettre l'export : participants, billets, commandes, transactions, scans, dons, campagnes, statistiques. Formats : CSV, XLSX. Les exports doivent respecter les permissions.

Chaque export est journalisé (`EXPORT_GENERATED`, cf. section 68).

---

