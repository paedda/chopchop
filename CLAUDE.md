# ChopChop — Claude Code Guide

## What this repo is

A URL shortener implemented identically in five backend languages (PHP/Symfony, Python/FastAPI, TypeScript/Express, Elixir/Phoenix, Java/Spring Boot). Every implementation exposes the same API contract against a shared PostgreSQL database. The goal is idiomatic, production-quality code in each language — not a minimal toy.

## Monorepo layout

```
chopchop/
├── schema.sql              # Single source of truth for the DB schema
├── docker-compose.yml      # Runs all backends + shared Postgres
├── tests/api-tests.sh      # Shared curl test suite (run against any port)
├── php-symfony/            # Port 8001
├── python-fastapi/         # Port 8002 (not yet implemented)
├── ts-express/             # Port 8003 (not yet implemented)
├── elixir-phoenix/         # Port 8004 (not yet implemented)
├── java-springboot/        # Port 8005 (not yet implemented)
└── go-nethttp/             # Port 8006 (not yet implemented)
```

## Running things

```bash
# Start everything
docker compose up --build

# Start a single backend (e.g. PHP only)
docker compose up --build php-symfony db

# Run the shared test suite against a port
./tests/api-tests.sh 8001   # PHP
./tests/api-tests.sh 8002   # Python
./tests/api-tests.sh 8003   # TypeScript
./tests/api-tests.sh 8004   # Elixir
./tests/api-tests.sh 8005   # Java
./tests/api-tests.sh 8006   # Go
```

## API contract (all implementations must match exactly)

| Endpoint | Method | Success | Notes |
|---|---|---|---|
| `/shorten` | POST | 201 | Body: `url` (required), `custom_code` (optional), `expires_in` (optional, seconds) |
| `/:code` | GET | 301 | Redirects; records click; 404 unknown, 410 expired |
| `/stats/:code` | GET | 200 | Returns click count + last 10 clicks |
| `/health` | GET | 200 | Returns `{status, language, framework}` |

### Validation rules

- `url` — valid HTTP/HTTPS URL
- `custom_code` — 3–20 characters, `[a-zA-Z0-9-]` only
- `expires_in` — positive integer, max 2 592 000 (30 days)

### Error shapes

All errors return JSON: `{"error": "human-readable message"}` with the appropriate HTTP status code.

## Adding a new language implementation

1. Create `<lang>-<framework>/` with a `Dockerfile` that exposes port 8000 internally.
2. Add the service to `docker-compose.yml` (external port from the table above, `depends_on: db`).
3. Implement all four endpoints. Use the PHP/Symfony implementation as the reference — match its request/response shapes exactly.
4. Code generation: base62 (`a-z A-Z 0-9`), 6 characters, retry up to 3 times on collision.
5. Run `./tests/api-tests.sh <port>` — all tests must pass.

## Database

All backends share one Postgres 16 instance. The schema is in `schema.sql` and is mounted as an init script in the `db` service. **Do not add migration tooling to individual backends** — `schema.sql` is the single source of truth.

Tables: `links` (code, url, created_at, expires_at) and `clicks` (link_id FK, clicked_at, ip_address, user_agent, referer).

## Key conventions

- Match the JSON field names in the spec exactly (snake_case).
- `created_at` / `expires_at` / `clicked_at` must be ISO 8601 with timezone (e.g. `2026-04-22T10:30:00+00:00`).
- `expires_at` is `null` in responses when no expiry was set.
- The redirect endpoint records a click **before** redirecting.
- `/health` must return the literal strings for `language` and `framework` as specified per implementation.
- For Go (`go-nethttp`): `language: "go"`, `framework: "net/http"`

## PHP/Symfony specifics

- Symfony 7.2, PHP 8.3, Doctrine ORM 3.x
- Routes declared in `LinkController` top-to-bottom; static routes (`/health`, `/shorten`, `/stats/{code}`) are matched before the catch-all `/{code}`
- Cache is warmed up in `entrypoint.sh` at container start
- `APP_SECRET` is set via Docker environment — do not hardcode it
