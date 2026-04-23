# ChopChop

A URL shortener implemented in six backend languages against an identical API contract and shared PostgreSQL database. Built to demonstrate idiomatic backend development across different ecosystems.

## Implementations

| Language | Framework | Port | Status |
|---|---|---|---|
| PHP | Symfony 7 | 8001 | ✅ done |
| Python | FastAPI | 8002 | ✅ done |
| TypeScript | Express | 8003 | 🔜 planned |
| Elixir | Phoenix | 8004 | 🔜 planned |
| Java | Spring Boot | 8005 | 🔜 planned |
| Go | net/http | 8006 | 🔜 planned |

## Quick start

```bash
# Clone
git clone https://github.com/paedda/chopchop.git
cd chopchop

# Start everything (Postgres + all available backends)
docker compose up --build

# Or start just one backend
docker compose up --build php-symfony db
```

All backends share one Postgres 16 container. The schema is applied automatically on first run via `schema.sql`.

## API reference

All implementations expose the same four endpoints. Examples below use port 8001 (PHP).

### `POST /chop`

Create a short link.

```bash
curl -X POST http://localhost:8001/chop \
  -H "Content-Type: application/json" \
  -d '{
    "url": "https://example.com/a/very/long/path",
    "custom_code": "mylink",
    "expires_in": 3600
  }'
```

**Request fields**

| Field | Type | Required | Description |
|---|---|---|---|
| `url` | string | yes | Valid HTTP or HTTPS URL |
| `custom_code` | string | no | 3–20 alphanumeric characters or hyphens |
| `expires_in` | integer | no | Seconds until expiry; max 2 592 000 (30 days) |

**Response — 201 Created**

```json
{
  "code": "mylink",
  "short_url": "http://localhost:8001/mylink",
  "url": "https://example.com/a/very/long/path",
  "created_at": "2026-04-22T10:30:00+00:00",
  "expires_at": "2026-04-22T11:30:00+00:00"
}
```

`expires_at` is `null` when no expiry was set.

**Errors**

| Status | Reason |
|---|---|
| 400 | Invalid or missing URL, invalid `custom_code` format, invalid `expires_in` |
| 409 | `custom_code` is already taken |

---

### `GET /:code`

Redirect to the original URL. Records a click.

```bash
curl -L http://localhost:8001/mylink
```

| Status | Meaning |
|---|---|
| 301 | Redirect to original URL |
| 404 | Code not found |
| 410 | Link has expired |

---

### `GET /stats/:code`

Get click statistics for a short link.

```bash
curl http://localhost:8001/stats/mylink
```

**Response — 200 OK**

```json
{
  "code": "mylink",
  "url": "https://example.com/a/very/long/path",
  "created_at": "2026-04-22T10:30:00+00:00",
  "expires_at": null,
  "total_clicks": 42,
  "recent_clicks": [
    {
      "clicked_at": "2026-04-22T12:01:00+00:00",
      "referer": "https://twitter.com",
      "user_agent": "Mozilla/5.0 ..."
    }
  ]
}
```

`recent_clicks` contains the 10 most recent clicks, newest first.

**Errors:** 404 if the code does not exist.

---

### `GET /health`

Health check. Returns the language and framework for the backend you're hitting.

```bash
curl http://localhost:8001/health
```

```json
{
  "status": "ok",
  "language": "php",
  "framework": "symfony"
}
```

---

## Database schema

All backends share one Postgres 16 instance with this schema (see `schema.sql`):

```sql
CREATE TABLE links (
    id         SERIAL PRIMARY KEY,
    code       VARCHAR(10) UNIQUE NOT NULL,
    url        TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    expires_at TIMESTAMP WITH TIME ZONE
);

CREATE TABLE clicks (
    id         SERIAL PRIMARY KEY,
    link_id    INTEGER NOT NULL REFERENCES links(id),
    clicked_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    ip_address VARCHAR(45),
    user_agent TEXT,
    referer    TEXT
);
```

## Running the tests

A shared `curl`-based test suite covers all endpoints. Run it against any backend by passing the port:

```bash
./tests/api-tests.sh 8001   # PHP/Symfony
./tests/api-tests.sh 8002   # Python/FastAPI
./tests/api-tests.sh 8003   # TypeScript/Express
./tests/api-tests.sh 8004   # Elixir/Phoenix
./tests/api-tests.sh 8005   # Java/Spring Boot
./tests/api-tests.sh 8006   # Go/net/http
```

Tests covered:

- `GET /health` returns 200 with correct language/framework
- `POST /chop` returns 201 with a generated code
- `GET /:code` issues a 301 redirect
- Following the redirect resolves to the original URL
- `GET /stats/:code` increments `total_clicks` after a redirect
- Custom codes work and return 201
- Duplicate custom code returns 409
- Invalid URL returns 400
- Expired link returns 410
- Unknown code returns 404

## Code generation

Short codes are generated using base62 encoding (`a-z A-Z 0-9`), defaulting to 6 characters. On a collision the generator retries up to 3 times before returning an error.

## Comparison

_This table will be filled in as implementations are completed._

| | PHP/Symfony | Python/FastAPI | TypeScript/Express | Elixir/Phoenix | Java/Spring Boot | Go/net/http |
|---|---|---|---|---|---|---|
| Lines of code | — | — | — | — | — | — |
| Docker image size | — | — | — | — | — | — |
| Cold start time | — | — | — | — | — | — |
| Avg response time | — | — | — | — | — | — |

## Project structure

```
chopchop/
├── README.md
├── CLAUDE.md               # Notes for Claude Code
├── docker-compose.yml
├── schema.sql
├── tests/
│   └── api-tests.sh
├── php-symfony/
│   ├── Dockerfile
│   ├── entrypoint.sh
│   ├── composer.json
│   ├── config/
│   └── src/
│       ├── Controller/LinkController.php
│       ├── Entity/{Link,Click}.php
│       ├── Repository/{Link,Click}Repository.php
│       └── Service/CodeGenerator.php
├── python-fastapi/         # planned
├── ts-express/             # planned
├── elixir-phoenix/         # planned
├── java-springboot/        # planned
└── go-nethttp/             # planned
```
