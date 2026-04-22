# ChopChop

A URL shortener implemented in five backend languages, same API contract, same database schema. Built to demonstrate idiomatic backend development across different ecosystems.

## Languages & Frameworks

| Language   | Framework    | Port |
|------------|-------------|------|
| PHP        | Symfony     | 8001 |
| Python     | FastAPI     | 8002 |
| TypeScript | Express     | 8003 |
| Elixir     | Phoenix     | 8004 |
| Java       | Spring Boot | 8005 |

## Monorepo Structure

```
chopchop/
├── README.md                  # Project overview + language comparison
├── docker-compose.yml         # Spins up all 5 backends + shared DB
├── schema.sql                 # Shared database schema
│
├── php-symfony/
│   ├── Dockerfile
│   ├── composer.json
│   └── src/
│
├── python-fastapi/
│   ├── Dockerfile
│   ├── requirements.txt
│   └── app/
│
├── ts-express/
│   ├── Dockerfile
│   ├── package.json
│   ├── tsconfig.json
│   └── src/
│
├── elixir-phoenix/
│   ├── Dockerfile
│   ├── mix.exs
│   └── lib/
│
├── java-springboot/
│   ├── Dockerfile
│   ├── pom.xml
│   └── src/
│
└── tests/
    └── api-tests.sh           # Shared test script that runs against any port
```

## Database Schema (PostgreSQL)

All five backends share one Postgres instance with the same schema.

```sql
CREATE TABLE links (
    id          SERIAL PRIMARY KEY,
    code        VARCHAR(10) UNIQUE NOT NULL,
    url         TEXT NOT NULL,
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    expires_at  TIMESTAMP WITH TIME ZONE
);

CREATE INDEX idx_links_code ON links(code);

CREATE TABLE clicks (
    id          SERIAL PRIMARY KEY,
    link_id     INTEGER NOT NULL REFERENCES links(id),
    clicked_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    ip_address  VARCHAR(45),
    user_agent  TEXT,
    referer     TEXT
);

CREATE INDEX idx_clicks_link_id ON clicks(link_id);
```

## API Contract

All implementations must expose identical endpoints with identical request/response shapes.

### POST /shorten

Create a short link.

**Request:**
```json
{
  "url": "https://example.com/some/really/long/path",
  "custom_code": "mylink",      // optional, user-chosen code
  "expires_in": 3600            // optional, seconds until expiry
}
```

**Response (201):**
```json
{
  "code": "mylink",
  "short_url": "http://localhost:800X/mylink",
  "url": "https://example.com/some/really/long/path",
  "created_at": "2026-04-22T10:30:00Z",
  "expires_at": "2026-04-22T11:30:00Z"
}
```

**Errors:**
- `400` - Invalid URL or empty payload
- `409` - Custom code already taken

### GET /:code

Redirect to the original URL. Records a click.

**Response:**
- `301` redirect to original URL
- `404` if code not found
- `410` if link has expired

### GET /stats/:code

Get click stats for a short link.

**Response (200):**
```json
{
  "code": "mylink",
  "url": "https://example.com/some/really/long/path",
  "created_at": "2026-04-22T10:30:00Z",
  "expires_at": "2026-04-22T11:30:00Z",
  "total_clicks": 42,
  "recent_clicks": [
    {
      "clicked_at": "2026-04-22T12:01:00Z",
      "referer": "https://twitter.com",
      "user_agent": "Mozilla/5.0 ..."
    }
  ]
}
```

**Errors:**
- `404` if code not found

### GET /health

Health check endpoint.

**Response (200):**
```json
{
  "status": "ok",
  "language": "php",
  "framework": "symfony"
}
```

## Code Generation Rules

Each implementation should generate short codes using a base62 encoding of a random value (a-z, A-Z, 0-9), defaulting to 6 characters. Collisions should be retried up to 3 times.

## Validation Rules

- `url` must be a valid HTTP or HTTPS URL
- `custom_code` must be 3-20 alphanumeric characters (plus hyphens)
- `expires_in` must be a positive integer, max 30 days (2592000 seconds)

## Docker Compose

Each backend gets its own container, all connecting to a shared Postgres container. A single `docker compose up` should start everything.

```yaml
# Outline for docker-compose.yml
services:
  db:
    image: postgres:16
    environment:
      POSTGRES_DB: chopchop
      POSTGRES_USER: chopchop
      POSTGRES_PASSWORD: chopchop
    ports:
      - "5432:5432"
    volumes:
      - ./schema.sql:/docker-entrypoint-initdb.d/schema.sql

  php-symfony:
    build: ./php-symfony
    ports:
      - "8001:8000"
    depends_on:
      - db

  python-fastapi:
    build: ./python-fastapi
    ports:
      - "8002:8000"
    depends_on:
      - db

  ts-express:
    build: ./ts-express
    ports:
      - "8003:8000"
    depends_on:
      - db

  elixir-phoenix:
    build: ./elixir-phoenix
    ports:
      - "8004:8000"
    depends_on:
      - db

  java-springboot:
    build: ./java-springboot
    ports:
      - "8005:8000"
    depends_on:
      - db
```

## Shared API Test Script

A bash script using `curl` that runs the same test suite against any port:

```bash
./tests/api-tests.sh 8001   # test PHP
./tests/api-tests.sh 8002   # test Python
# etc.
```

Tests to include:
- Shorten a URL, get code back
- Redirect works (follow vs. no-follow)
- Stats increment after redirect
- Custom code works
- Duplicate custom code returns 409
- Invalid URL returns 400
- Expired link returns 410
- Health check returns correct language/framework

## README Comparison Table

The root README should include a comparison covering:
- Lines of code per implementation
- Docker image size
- Cold start time
- Avg response time under load (optional, using `wrk` or `hey`)
- Notable idiomatic patterns used in each language
- Developer experience notes (setup friction, debugging, tooling)
