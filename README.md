# Pass241

Plateforme SaaS de billetterie, contrôle d'accès et collecte de fonds pour le marché gabonais. Paiement par Airtel Money et Moov Money.

- Spécification produit : [CLAUDE.md](CLAUDE.md) et [docs/](docs/)
- Avancement : [ROADMAP.md](ROADMAP.md)
- Décisions techniques : [docs/00-decisions.md](docs/00-decisions.md)

---

## Démarrer l'environnement

Prérequis : Docker Desktop et Node.js.

```bash
docker compose up -d                    # web, base de données, Redis, mail
docker compose --profile workers up -d  # Horizon et Reverb
```

Première installation uniquement :

```bash
cp backend/.env.example backend/.env
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
cd backend && npm install && npm run build
```

Le `TICKET_TOKEN_SIGNING_KEY` doit être généré séparément de `APP_KEY` : une rotation de `APP_KEY` ne doit jamais invalider les billets déjà vendus.

```bash
docker compose run --rm app php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

## Adresses locales

| Service | Adresse |
|---|---|
| Application | http://localhost:8000 |
| Mailpit (emails sortants) | http://localhost:8025 |
| Horizon (files d'attente) | http://localhost:8000/horizon |
| Reverb (WebSocket) | ws://localhost:8080 |
| PostgreSQL | `localhost:5434` — base `pass241`, utilisateur `pass241` |
| Redis | `localhost:6380` |

Les ports 5434 et 6380 sont décalés volontairement : 5432, 5433 et 6379 étaient déjà occupés sur la machine de développement.

## Commandes courantes

```bash
docker compose run --rm app php artisan <commande>   # Artisan
docker compose run --rm app ./vendor/bin/pest        # Tests
docker compose run --rm app ./vendor/bin/pint        # Formatage PHP
cd backend && npm run dev                            # Vite en mode watch
```

## Structure

```
backend/    Laravel 13 — API, dashboard, pages publiques
mobile/     React Native / Expo — application agents (Phase 5)
docs/       Spécification fonctionnelle
docker/     Images et configuration des services
```

## Tests

La suite tourne sur PostgreSQL, pas sur SQLite. La base `pass241_testing` doit exister :

```bash
docker compose exec postgres psql -U pass241 -d postgres -c "CREATE DATABASE pass241_testing OWNER pass241;"
```
