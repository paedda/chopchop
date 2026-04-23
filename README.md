# ChopChop

A URL shortener implemented in multiple backend languages against an identical API contract and shared PostgreSQL database. Built to demonstrate idiomatic backend development across different ecosystems.

## Implementations

| Language | Framework | Port | Status |
|---|---|---|---|
| PHP | Symfony 7 | 8001 | ✅ done |
| Python | FastAPI | 8002 | ✅ done |
| TypeScript | Express | 8003 | ✅ done |
| Elixir | Phoenix | 8004 | ✅ done |
| Java | Spring Boot | 8005 | ✅ done |
| Go | net/http | 8006 | ✅ done |
| Ruby | Sinatra | 8007 | ✅ done |
| C# | ASP.NET Core | 8008 | ✅ done |

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
    code       VARCHAR(20) UNIQUE NOT NULL,
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

A shared `curl`-based test suite covers all endpoints. To run against all backends at once:

```bash
./tests/run-all.sh
```

Or target a specific backend by passing its port:

```bash
./tests/api-tests.sh 8001   # PHP/Symfony
./tests/api-tests.sh 8002   # Python/FastAPI
./tests/api-tests.sh 8003   # TypeScript/Express
./tests/api-tests.sh 8004   # Elixir/Phoenix
./tests/api-tests.sh 8005   # Java/Spring Boot
./tests/api-tests.sh 8006   # Go/net/http
./tests/api-tests.sh 8007   # Ruby/Sinatra
./tests/api-tests.sh 8008   # C#/ASP.NET Core
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

| | PHP/Symfony | Python/FastAPI | TS/Express | Elixir/Phoenix | Java/Spring Boot | Go/net/http | Ruby/Sinatra | C#/ASP.NET |
|---|---|---|---|---|---|---|---|---|
| Lines of code | 553 | 353 | 209 | 360 | 409 | 374 | 167 | 233 |
| Source files | 7 | 7 | 4 | 11 | 9 | 4 | 1 | 1 |
| Dependencies | 11 | 4 | 2 | 5 | 6 | 1 | 4 | 1 |
| Docker image size | 196 MB | 371 MB | 443 MB | 2.37 GB | 489 MB | 26.3 MB | 765 MB | 360 MB |
| Memory (idle) | 14 MB | 53 MB | 13 MB | 105 MB | 273 MB | 5 MB | 26 MB | 24 MB |
| Cold start time | 0.1s | 0.3s | 0.1s | 0.4s | 1.7s | 0.0s | 0.1s | 0.2s |
| Avg response time | 3.6ms | 2.0ms | 2.3ms | 2.3ms | 3.8ms | 1.9ms | 3.1ms | 2.1ms |

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
├── python-fastapi/
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app/
│       ├── main.py
│       ├── router.py
│       ├── models.py
│       ├── schemas.py
│       ├── database.py
│       └── codegen.py
├── ts-express/
│   ├── Dockerfile
│   ├── package.json
│   ├── tsconfig.json
│   └── src/
│       ├── index.ts
│       ├── router.ts
│       ├── db.ts
│       └── codegen.ts
├── elixir-phoenix/
│   ├── Dockerfile
│   ├── mix.exs
│   ├── config/
│   │   ├── config.exs
│   │   └── runtime.exs
│   └── lib/
│       ├── chopchop/
│       │   ├── application.ex
│       │   ├── repo.ex
│       │   ├── codegen.ex
│       │   ├── links.ex
│       │   └── links/{link,click}.ex
│       └── chopchop_web/
│           ├── endpoint.ex
│           ├── router.ex
│           └── controllers/link_controller.ex
├── java-springboot/
│   ├── Dockerfile
│   ├── pom.xml
│   └── src/main/java/com/chopchop/
│       ├── ChopchopApplication.java
│       ├── DataSourceConfig.java
│       ├── controller/{LinkController,GlobalExceptionHandler}.java
│       ├── model/{Link,Click}.java
│       ├── repository/{Link,Click}Repository.java
│       └── service/CodeGenerator.java
├── go-nethttp/
│   ├── Dockerfile
│   ├── go.mod
│   ├── main.go
│   ├── handler.go
│   ├── codegen.go
│   └── ordered_map.go
├── ruby-sinatra/
│   ├── Dockerfile
│   ├── Gemfile
│   ├── config.ru
│   └── app.rb
└── csharp-aspnet/
    ├── Dockerfile
    ├── chopchop.csproj
    └── Program.cs
```
