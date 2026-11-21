<div align="center">

# FAMO Unified API (`famo-api`)

**High-performance modular REST API powering the FAMO Academy ecosystem — Admin Panel, Public Site, Student Dashboard, Nobat Booking, Planner, and Telegram Bot Gateway.**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2%20%7C%208.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![Slim Framework](https://img.shields.io/badge/Slim-4.x-719E40?style=flat-square)](https://www.slimframework.com/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0%2B%20%7C%208.4-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Redis](https://img.shields.io/badge/Redis-Cache%20%26%20RateLimiter-DC382D?style=flat-square&logo=redis&logoColor=white)](https://redis.io/)
[![OpenAPI](https://img.shields.io/badge/OpenAPI-3.1.0-6BA539?style=flat-square&logo=openapiinitiative&logoColor=white)](./openapi.yaml)
[![Docker](https://img.shields.io/badge/Docker-api.Dockerfile%20%7C%20PHP%208.4-2496ED?style=flat-square&logo=docker&logoColor=white)](./api.Dockerfile)

[Features](#-key-features) •
[Architecture](#-architecture--project-structure) •
[Quick Start](#-getting-started) •
[Configuration](#-environment-variables) •
[Caching & Rate Limiting](#-redis-caching--rate-limiting) •
[Health Monitoring](#-infrastructure-health-monitoring) •
[API Documentation](#-api-documentation-openapi-31) •
[Testing](#-testing)

</div>

---

## Overview

**FAMO Unified API** is a lightweight, zero-bloat PHP 8.2+ / 8.4 RESTful backend built on **Slim 4**, **MySQL 8.0+ / 8.4+**, and **PSR-7 / PSR-15** standards. It serves as the single source of truth for all FAMO Academy client applications, enforcing strict role-based access control (`admin`, `supporter`, `teacher`, `student`), supporter student-scoping, two-factor authentication (2FA), Redis-backed public caching and rate limiting, and bidirectional Telegram Bot integration.

---

## ✨ Key Features

- **Modular Domain Architecture**: Clean separation into `Controller → Service → Model` modules under `app/Modules/*`.
- **MySQL 8.0+ / 8.4+ Schema & Migrations**:
  - 13 ordered `InnoDB` / `utf8mb4` SQL migrations in `database/migrations/` (`001` through `013`) plus a legacy-dump upgrade script (`database/mysql_upgrade_from_dump.sql`).
  - MySQL generated columns for computed metrics and active/open uniqueness constraints (`exam_results.percentage` `STORED`, `student_supporter_assignments.active_student_id` `STORED`, `tutoring_sessions.open_student_key` `VIRTUAL`).
  - Named-lock protected (`GET_LOCK('famo_schema_migrations', 30)`) SQL migration runner (`App\Core\Migrator`) intended for manual / CLI execution (not executed automatically on HTTP boot).
- **Multi-Role Authentication & Security**:
  - JWT authentication (`HS256`) supporting both `Authorization: Bearer <token>` headers and cross-subdomain `HttpOnly` cookies, with Redis-backed token revocation (`JwtRevocation`).
  - SMS OTP via Melipayamak (`OtpService`, `SmsService`, `SmsPatternSettings`) for staff 2FA (`admin`, `supporter`, `teacher`), optional student 2FA (`STUDENT_2FA_MODE=off|new_device|always` with `TrustedDevice`), and password reset flows.
  - Telegram Login Widget cryptographic verification (`HMAC-SHA256`) and account linking handoff.
  - Fine-grained authorization (`Authorization`) and student-scope isolation (`StudentScope`) for supporters.
- **Redis Caching & Atomic View Counters**:
  - Dual-layer caching (`PublicCacheMiddleware` for HTTP responses + `Cache` for service queries) backed by `RedisClient` (`ext-redis` with pure-PHP RESP2 socket and file-cache fallback).
  - Group-based cache invalidation (`Cache::flushGroup('public')`) triggered automatically on admin mutations.
  - Atomic Redis blog view counters (`INCR`) combined with cached post payloads.
- **Redis-Backed Rate Limiting**:
  - Distributed fixed-window and lockout-capable rate limiting (`RateLimiter` & `RateLimitMiddleware`) protecting public endpoints, login/2FA flows, SMS OTP dispatches, and Telegram handoff routes, with MySQL (`auth_throttle`) fallback for lockout helpers when Redis is unavailable.
  - Emits standard `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and `Retry-After` HTTP headers.
- **Strict Input, Phone Number & Environment Validation**:
  - Request-level environment validation (`EnvValidator`) and JSON payload verification (`RequestValidationMiddleware`).
  - Chainable field validator (`App\Core\Validator`) returning localized, structured `422 VALIDATION_ERROR` responses.
  - Canonical Iranian mobile phone number validator & normalizer (`App\Core\PhoneNumber`) converting Persian/Arabic numerals (`۰-۹`, `٠-٩`) and international prefixes (`+98`, `0098`, `98`, `9...`) to `09XXXXXXXXX` before database persistence, with E.164 (`+989...`) and masked (`0912***89`) helpers.
- **Course Subscriptions Management (`App\Modules\Subscription`)**:
  - Full lifecycle management for student course enrollments (`subscriptions` table) with `pending`, `active`, `expired`, `cancelled`, and `rejected` statuses plus monthly tuition tracking.
  - Student self-enrollment (`POST /api/v1/subscriptions` or `POST /api/v1/courses/{id}/enroll`), self-scoped listing (`GET /api/v1/subscriptions/me`), pending cancellation (`DELETE /api/v1/subscriptions/{id}`), and supporter/admin status & tuition management (`PUT`/`PATCH`).
  - Automatic duplicate prevention (`409 ALREADY_SUBSCRIBED`) when an active or pending subscription already exists for the same student and course.
- **Infrastructure Health Probe**:
  - Lightweight `GET /health` and `GET /api/v1/health` endpoints reporting real-time MySQL (`SELECT 1`) and Redis (`PING`) connectivity and latency in milliseconds.
- **Complete OpenAPI 3.1.0 Specification**:
  - Full API specification in [`openapi.yaml`](./openapi.yaml), mirrored byte-for-byte in [`public/openapi.yaml`](./public/openapi.yaml) for static serving.

---

## 🏗 Architecture & Project Structure

```text
famo-api/
├── app/
│   ├── Core/                        # Framework core & PSR-15 middlewares
│   │   ├── Auth.php                 # JWT generation & verification
│   │   ├── AuthCookie.php           # Cross-subdomain HttpOnly auth cookie helpers
│   │   ├── Authorization.php        # Role-based access guards
│   │   ├── Cache.php                # Redis + file fallback cache manager with group invalidation
│   │   ├── CorsMiddleware.php       # Dynamic CORS origin & credentials middleware
│   │   ├── Database.php             # PDO MySQL singleton & health check (ping / checkHealth)
│   │   ├── EnvValidator.php         # Mandatory & format environment variable validator
│   │   ├── JwtRevocation.php        # Redis-backed JWT revocation & user-wide logout
│   │   ├── Migrator.php             # MySQL GET_LOCK-protected SQL migration runner (manual/CLI)
│   │   ├── OtpService.php           # Purpose-bound hashed SMS OTP service in Redis
│   │   ├── PhoneNumber.php          # Iranian mobile number validator, normalizer (09XXXXXXXXX), & masker
│   │   ├── PublicCacheMiddleware.php# HTTP response cache for /api/v1/public/*
│   │   ├── RateLimiter.php          # Redis Lua fixed-window + MySQL auth_throttle lockout fallback
│   │   ├── RateLimitMiddleware.php  # Per-IP / per-user rate limiting middleware
│   │   ├── RedisClient.php          # Native ext-redis, RESP2 socket, & in-memory test driver
│   │   ├── RequestValidationMiddleware.php # JSON body & env guard middleware
│   │   ├── SmsPatternSettings.php   # Per-purpose Melipayamak pattern bodyId management
│   │   ├── SmsService.php           # Melipayamak SMS pattern client
│   │   ├── Sorting.php              # Whitelist-based server-side ORDER BY builder (Persian collation)
│   │   ├── TrustedDevice.php        # 30-day HttpOnly trusted device cookie for student 2FA
│   │   └── Validator.php            # Server-side input validation rules
│   ├── Helpers/
│   │   └── JalaliHelper.php         # Gregorian ↔ Jalali calendar conversion utilities
│   └── Modules/                     # Domain modules (Controller, Service, Model)
│       ├── ActivityLogs/            ├── Courses/           ├── Public/
│       ├── Admins/                  ├── Dashboard/         ├── Reports/
│       ├── Admissions/              ├── Exams/             ├── Settings/
│       ├── Appointments/            ├── Files/             ├── Stats/
│       ├── Assignments/             ├── Instructors/       ├── Students/
│       ├── Attendance/              ├── Linking/           ├── Subscription/
│       ├── Auth/                    ├── Outbox/            ├── Supporters/
│       ├── Blog/                    ├── ParentContacts/    ├── Testimonials/
│       ├── Bot/                     ├── Threads/           ├── Topics/
│       ├── Broadcasts/              ├── Tutoring/          └── WeeklyPlans/
├── bootstrap/
│   └── app.php                      # Slim 4 bootstrap, middleware pipeline, error handler
├── config/
│   ├── database.php                 # PDO MySQL configuration (UTF-8 / utf8mb4, port 3306)
│   ├── rate_limits.php              # Auth, SMS, public, and API rate-limit policies
│   └── redis.php                    # Redis connection, prefix, & timeout settings
├── database/
│   ├── migrations/                  # Ordered MySQL 8.0+ / 8.4+ migrations (001..013)
│   └── mysql_upgrade_from_dump.sql  # Upgrade script for legacy MySQL production dumps
├── docker/                          # Production PHP/Apache & RemoteIP tuning configs
│   ├── apache-tune.conf             # Apache MPM prefork, KeepAlive & security headers tuning
│   ├── custom.ini                   # PHP production ini (Asia/Tehran, OPcache, memory & upload limits)
│   └── remoteip.conf                # Trusted reverse-proxy X-Forwarded-For configuration
├── docs/
│   └── frontend/                    # Frontend integration TypeScript types & guides
├── public/
│   ├── index.php                    # HTTP front controller
│   └── openapi.yaml                 # Publicly served mirror of root openapi.yaml
├── routes/
│   └── api.php                      # Complete route definitions (/health, /api/v1/*)
├── tests/                           # PHPUnit test suite (Core & Modules)
├── api.Dockerfile                   # Multi-stage Composer 2 + PHP 8.4 Apache production image
├── Dockerfile                       # Default Docker build entrypoint (uses install-php-extensions)
├── openapi.yaml                     # Full OpenAPI 3.1.0 specification
└── composer.json                    # PHP dependencies & PSR-4 autoloading
```

### Middleware Execution Pipeline

In Slim 4, middleware executes in **LIFO** (Last-In, First-Out) order. Requests flow through the stack as follows:

```text
Incoming HTTP Request
  │
  ├─► 1. CorsMiddleware              (Handles preflight OPTIONS & sets CORS headers)
  ├─► 2. ErrorMiddleware             (Catches exceptions & formats standard JSON envelope)
  ├─► 3. RoutingMiddleware           (Resolves route & path parameters)
  ├─► 4. BodyParsingMiddleware       (Parses application/json & form bodies)
  ├─► 5. RequestValidationMiddleware (Validates env vars & rejects malformed JSON bodies)
  ├─► 6. Route-Group Middlewares     (PublicCacheMiddleware / RateLimitMiddleware / AuthMiddleware)
  │
  ▼
Controller Action ──► Standard JSON Response Envelope
```

---

## 🚀 Getting Started

### Prerequisites

- **PHP** `>= 8.2` (PHP `8.4` recommended for production)
  - Extensions: `pdo_mysql`, `mysqli`, `mbstring`, `gd`, `intl`, `zip`, `bcmath`, `redis` (optional — falls back to pure-PHP RESP2 socket if `ext-redis` is not installed)
- **Composer** `2.x`
- **MySQL** `8.0+` (`8.4` recommended, `utf8mb4`)
- **Redis** `6.2+` / `7.x` (recommended for public endpoint caching, OTP storage, JWT revocation, and distributed rate limiting)

### 1. Local Installation

```bash
# 1. Clone the repository
git clone https://github.com/nadcorp-group/famo-api.git
cd famo-api

# 2. Install Composer dependencies
composer install

# 3. Copy the environment template and configure credentials
cp .env.example .env

# 4. Create writable runtime directories
mkdir -p storage/cache storage/logs uploads
```

### 2. Running Database Migrations

> **Manual Execution**: `App\Core\Migrator` is **not** called automatically in `bootstrap/app.php` on HTTP boot. Run migrations explicitly during deployment or local setup.

**Option A — Run via `App\Core\Migrator` (recommended for clean databases or incremental updates):**

`Migrator::run()` acquires MySQL lock `GET_LOCK('famo_schema_migrations', 30)`, ensures the `migrations` tracking table exists, and executes every unapplied `database/migrations/*.sql` file in lexicographical order (`001`..`013`):

```bash
php -r '
require "vendor/autoload.php";
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
$ran = (new App\Core\Migrator())->run();
echo empty($ran) ? "No pending migrations.\n" : "Applied:\n- " . implode("\n- ", $ran) . "\n";
'
```

**Option B — Apply SQL files directly with the `mysql` CLI:**

```bash
for f in database/migrations/*.sql; do
  echo "Applying $f ..."
  mysql -h "$DB_HOST" -P "${DB_PORT:-3306}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$f"
done
```

**Option C — Upgrading from a legacy production MySQL dump:**

If you are restoring an older MySQL dump instead of starting from an empty database, apply the consolidated upgrade script after importing the dump:

```bash
mysql -u root -p famo < database/mysql_upgrade_from_dump.sql
```

### 3. Running Locally

With PHP's built-in server:

```bash
php -S 0.0.0.0:8000 -t public
```

Or using the included Node.js verification & OpenAPI preview server on port `3000`:

```bash
npm run dev
```

### 4. Running with Docker (`Dockerfile` / `api.Dockerfile`)

The repository ships with [`Dockerfile`](./Dockerfile) (using `install-php-extensions`) and [`api.Dockerfile`](./api.Dockerfile) (compiling extensions via `docker-php-ext-install` + `pecl`), both multi-stage production images built on `php:8.4-apache`:

- **Stage 1 (`vendor`)**: Runs `composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader` using Composer 2 on `php:8.4-apache`.
- **Stage 2 (`runtime`)**:
  - Installs & enables PHP extensions: `gd` (with WebP, JPEG, and FreeType), `pdo_mysql`, `mysqli`, `zip`, `intl`, `opcache`, `bcmath`, and `redis`.
  - Applies production PHP settings from [`docker/custom.ini`](./docker/custom.ini) (`date.timezone = Asia/Tehran`, `memory_limit = 192M`, `upload_max_filesize = 20M`, and `opcache` tuning).
  - Enables Apache `rewrite`, `headers`, and `remoteip` modules with [`docker/remoteip.conf`](./docker/remoteip.conf) and [`docker/apache-tune.conf`](./docker/apache-tune.conf).
  - Sets `DocumentRoot` to `/var/www/html/public` and configures a built-in container `HEALTHCHECK` against `http://127.0.0.1/health`.

```bash
# Build production image
docker build -f api.Dockerfile -t famo-api:latest .

# Run container with environment variables and persistent volumes
docker run -d \
  --name famo-api \
  -p 8080:80 \
  --env-file .env \
  -v "$(pwd)/uploads:/var/www/html/uploads" \
  -v "$(pwd)/storage/logs:/var/www/html/storage/logs" \
  famo-api:latest
```

#### Example `docker-compose.yml` Integration

No `docker-compose.yml` is committed to the repository; if you run the stack locally with Docker Compose, use a layout matching `.env.example` (`DB_DRIVER=mysql`, `DB_HOST=mysql`, `DB_PORT=3306`, `DB_NAME=famo`, `REDIS_HOST=redis`, `REDIS_PORT=6379`):

```yaml
services:
  api:
    build:
      context: .
      dockerfile: api.Dockerfile
    container_name: famo-api
    restart: unless-stopped
    ports:
      - "8080:80"
    env_file:
      - .env
    volumes:
      - ./uploads:/var/www/html/uploads
      - ./storage/logs:/var/www/html/storage/logs
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_started

  mysql:
    image: mysql:8.4
    container_name: famo-mysql
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_NAME:-famo}
      MYSQL_USER: ${DB_USER:-famo}
      MYSQL_PASSWORD: ${DB_PASS:?Set DB_PASS in .env}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?Set MYSQL_ROOT_PASSWORD in .env}
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "127.0.0.1", "-u", "root", "-p${MYSQL_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: famo-redis
    restart: unless-stopped

volumes:
  mysql_data:
```

---

## ⚙️ Environment Variables

Copy `.env.example` to `.env` and configure the variables for your environment. `App\Core\EnvValidator` validates mandatory variables (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `JWT_SECRET`) and formats automatically.

| Category | Variable | Required | Default (`.env.example` / Code) | Description |
| :--- | :--- | :---: | :--- | :--- |
| **App** | `APP_ENV` | No | `production` | Environment (`local`, `development`, `staging`, `production`, `testing`) |
| | `APP_DEBUG` | No | `false` | Enables detailed error traces when `true` |
| | `API_URL` | No | — | Base URL of the API (e.g., `https://api.famoacademy.ir`) |
| | `ASSET_URL` | No | — | Public CDN/base URL for uploaded assets |
| | `PUBLIC_SITE_URL` | No | — | Base URL of the public frontend site (used in exam share links) |
| | `CORS_ORIGINS` | No | — | Comma-separated list of allowed CORS origins |
| **Database** | `DB_DRIVER` | No | `mysql` | PDO database driver (`mysql`) |
| | `DB_HOST` | **Yes** | — | MySQL hostname or IP address |
| | `DB_PORT` | **Yes** | `3306` | MySQL port (`1`–`65535`) |
| | `DB_NAME` | **Yes** | `famo` | MySQL database name |
| | `DB_USER` | **Yes** | — | MySQL username |
| | `DB_PASS` | **Yes** | `""` | MySQL password (key must be defined) |
| **JWT & Auth** | `JWT_SECRET` | **Yes** | — | Secret key used to sign HS256 JWT tokens |
| | `JWT_TTL` | No | `86400` | Token expiration time in seconds |
| | `JWT_COOKIE_NAME` | No | `famo_token` | Shared auth cookie name |
| | `JWT_COOKIE_DOMAIN` | No | — | Cookie domain (e.g., `.famoacademy.ir`) |
| | `JWT_COOKIE_SECURE` | No | `true` | Restrict cookie to HTTPS |
| | `JWT_COOKIE_SAMESITE` | No | `Lax` | Cookie SameSite policy (`Lax`, `Strict`, `None`) |
| | `AUTH_REVOCATION_FAIL_MODE` | No | `open` | Behavior for non-admin JWT revocation check if Redis is down (`open` / `closed`) |
| | `AUTH_REVOCATION_ADMIN_FAIL_MODE` | No | `closed` | Behavior for admin JWT revocation check if Redis is down (`closed` / `open`) |
| | `STUDENT_2FA_MODE` | No | `off` | Student SMS 2FA mode (`off`, `new_device`, `always`); `new_device` uses 30-day `HttpOnly` cookie `famo_td` (stored HMAC-hashed per user in Redis) |
| **Redis & Cache** | `REDIS_ENABLED` | No | `true` (if host/url set) | Enable Redis for caching, OTP, revocation, and rate limiting |
| | `REDIS_URL` | No | — | Full Redis URI (`redis://[:password@]host:port[/db]`) |
| | `REDIS_HOST` | No | `127.0.0.1` | Redis server hostname |
| | `REDIS_PORT` | No | `6379` | Redis server port |
| | `REDIS_PASSWORD` | No | `null` | Redis authentication password |
| | `REDIS_DB` | No | `0` | Redis logical database index |
| | `REDIS_PREFIX` | No | `famo:` | Keyspace prefix for all Redis keys |
| | `CACHE_ENABLED` | No | `true` | Master toggle for application caching |
| **Rate Limiting** | `RATE_LIMIT_PUBLIC_IP_MAX` / `_WINDOW` | No | `120` / `60` | Max requests per IP per window (seconds) on `/api/v1/public/*` |
| | `RATE_LIMIT_PUBLIC_EXAM_IP_MAX` / `_WINDOW` | No | `20` / `60` | Stricter per-IP limit on `/api/v1/public/exam-results/{token}` |
| | `RATE_LIMIT_PUBLIC_TESTIMONIAL_IP_MAX` / `_WINDOW` | No | `5` / `3600` | Per-IP submission limit on `POST /api/v1/public/testimonials` |
| | `RATE_LIMIT_API_STUDENT_MAX` / `STAFF_MAX` | No | `120` / `300` | Per-user authenticated API limit per `RATE_LIMIT_API_AUTH_WINDOW` (`60`s) |
| | `RATE_LIMIT_AUTH_USER_IP_MAX` / `_WINDOW` | No | `5` / `60` | Auth attempt limit per user+IP |
| | `RATE_LIMIT_AUTH_IP_MAX` / `_WINDOW` | No | `100` / `900` | Auth attempt limit per IP |
| | `RATE_LIMIT_SMS_PHONE_MAX` / `_WINDOW` | No | `3` / `600` | SMS OTP limit per phone |
| | `RATE_LIMIT_SMS_IP_MAX` / `_WINDOW` | No | `10` / `3600` | SMS OTP limit per IP |
| **SMS (Melipayamak)** | `MELIPAYAMAK_USERNAME` / `_PASSWORD` | SMS Routes | — | Melipayamak API credentials |
| | `MELIPAYAMAK_PATTERN_BODY_ID` | 2FA / Reset | — | Pattern body ID for 2FA login & password reset OTPs |
| | `MELIPAYAMAK_APPOINTMENT_BODY_ID` | Appt SMS | — | Env fallback pattern body ID for weekly appointment reminders |
| | `MELIPAYAMAK_EXAM_BODY_ID` | Exam SMS | — | Env fallback pattern body ID for exam result share links |
| **Telegram & Bot** | `BOT_SERVICE_KEY` | Bot Routes | — | Shared secret for `/api/v1/bot/*` (`X-Bot-Service-Key`) |
| | `TELEGRAM_LOGIN_SECRET_KEY` | TG Auth | — | 64-character hex SHA256 digest of the Telegram Bot token |
| | `TELEGRAM_BOT_URL` | No | — | Telegram Bot deep-link base URL |

See [`.env.example`](./.env.example) for the complete list of optional tuning knobs (SMTP, upload paths, trusted proxies, and per-route SMS quotas).

---

## ⚡ Redis Caching & Rate Limiting

### Public Endpoints Caching

Public site endpoints under `/api/v1/public/*` (excluding `/api/v1/public/exam-results/{token}`) use a two-tier Redis caching strategy:

1. **HTTP Response Cache (`PublicCacheMiddleware`)**:
   - Caches `200 OK` JSON payloads in Redis for **300 seconds** under group `public`.
   - Emits `Cache-Control: public, max-age=300`, `Vary: Origin`, and `X-Cache: HIT | MISS`.
2. **Service-Layer Cache (`App\Core\Cache`)**:
   - Caches underlying database query results in `PublicService` and `BlogService` for **600 seconds**.
   - `GET /api/v1/public/blog/posts/{slug}` caches the article body (`blog_post:{slug}`) while atomically incrementing live article views in Redis (`blog_post_views:{id}`).
3. **Automatic Cache Invalidation**:
   - Creating, updating, or deleting courses, instructors, supporters, blog posts, admissions, or testimonials automatically invokes `Cache::flushGroup('public')`.

### Rate Limiting

`RateLimitMiddleware` and `RateLimiter` enforce fixed-window limits in Redis using an atomic Lua script (`INCR` + `EXPIRE` + `TTL`), with an optional MySQL (`auth_throttle`) fallback for lockout helpers (`RateLimiter::attempt` / `status`):

- Responses include `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers.
- When a client exceeds the threshold, the API responds with `429 Too Many Requests` (`RATE_LIMITED`) and a `Retry-After` header.

---

## 🩺 Infrastructure Health Monitoring

A lightweight, unauthenticated health check endpoint is available at both `/health` and `/api/v1/health` for Kubernetes liveness/readiness probes, Docker healthchecks, and uptime monitors.

### Request

```http
GET /health HTTP/1.1
Host: api.famoacademy.ir
```

### Response (`200 OK` when healthy / `503 Service Unavailable` when DB is down)

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "database": {
      "status": "connected",
      "connected": true,
      "latency_ms": 0.42
    },
    "redis": {
      "status": "connected",
      "connected": true,
      "latency_ms": 0.18
    },
    "version": "1.0.0",
    "time": "2026-10-31 12:00:00"
  },
  "pagination": null,
  "error": null
}
```

| Overall `status` | HTTP Code | Condition |
| :--- | :---: | :--- |
| `healthy` | `200 OK` | Database is `connected` and Redis is either `connected` or intentionally `disabled`. |
| `degraded` | `200 OK` | Database is `connected`, but Redis is enabled and currently `disconnected` (file fallback active). |
| `unhealthy` | `503 Service Unavailable` | Database connection failed (`SELECT 1` probe failed). |

---

## 📖 API Documentation (OpenAPI 3.1)

The repository maintains a complete OpenAPI 3.1.0 specification in [`openapi.yaml`](./openapi.yaml), mirrored byte-for-byte in [`public/openapi.yaml`](./public/openapi.yaml) so it can be served directly from the public web root.

### Summary of Public & Auth Endpoints

| Method | Endpoint | Description | Cache / Rate Limit |
| :---: | :--- | :--- | :--- |
| `GET` | `/health` | Infrastructure health check (MySQL & Redis status) | `no-store` |
| `GET` | `/api/v1/health` | Versioned health check (MySQL & Redis status) | `no-store` |
| `GET` | `/api/v1/public/courses` | List published courses with features (`display_order ASC`) | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/instructors` | List published instructors | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/supporters` | List active supporters | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/blog/posts` | Paginated published blog posts (`page`, `per_page`/`limit`) | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/blog/posts/{slug}` | Single blog post by slug + atomic Redis view increment | Service Redis Cache + Rate Limit |
| `GET` | `/api/v1/public/blog/categories` | Blog categories with published post counts | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/blog/categories/{category}/posts` | Paginated posts filtered by category | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/admissions` | Published & consent-confirmed admissions (`year`, `exam_type`, `field`, `featured`, `group_by=year`) | Redis Cache (300s) + Rate Limit |
| `GET` | `/api/v1/public/testimonials` | Approved testimonials with `summary` (`total_approved`, `average_rating`) | Redis Cache (300s) + Rate Limit |
| `POST` | `/api/v1/public/testimonials` | Public testimonial submission (starts `pending`, honeypot + HMAC-SHA256 Redis dedup) | 5/hour IP Rate Limit |
| `POST` | `/api/v1/auth/login` | User login (returns JWT or `202` 2FA challenge; supports `STUDENT_2FA_MODE=off|new_device|always`) | Auth Rate Limit |
| `POST` | `/api/v1/auth/register` | Student self-registration | Auth Rate Limit |
| `POST` | `/api/v1/auth/verify-2fa` | Verify 6-digit OTP (`code`, `user_id`; sets 30-day `HttpOnly` `famo_td` cookie for students in `new_device` mode) | 2FA Rate Limit (fail-closed Redis for student OTP) |
| `POST` | `/api/v1/auth/password/forgot` | Request purpose-bound `password_reset` SMS OTP (uniform response, equalized timing) | Auth Attempt + SMS Rate Limit |
| `POST` | `/api/v1/auth/password/verify` | Verify `password_reset` OTP and return 10-minute single-use signed reset token | Auth Attempt Rate Limit (fail-closed Redis) |
| `POST` | `/api/v1/auth/password/reset` | Consume single-use reset token, update password, clear `must_change_password` if present, revoke all JWTs | Auth Attempt Rate Limit (fail-closed Redis) |
| `GET` | `/api/v1/auth/me` | Get authenticated user's profile from JWT | Authenticated |
| `POST` | `/api/v1/auth/change-password` | Change password (`current_password`, `new_password` >= 4 chars) | Authenticated |
| `PUT` | `/api/v1/auth/profile` | Update `full_name`/`name` & `phone` with transactional role sync & 2FA password guard | Authenticated |
| `POST` | `/api/v1/auth/logout` | Clear authentication cookie & revoke current JWT | Public / Authenticated |
| `POST` | `/api/v1/auth/telegram/*` | Telegram Login Widget verification, registration, & linking | Telegram Rate Limit |

### Course Subscription Endpoints (`Subscriptions`)

| Method | Endpoint | Access / Role | Description |
| :---: | :--- | :--- | :--- |
| `GET` | `/api/v1/subscriptions` | Authenticated (`StudentScope`) | Paginated & filterable list (`student_id`, `course_id`, `status`, `tuition_status`, `is_active`, `search`) |
| `GET` | `/api/v1/subscriptions/me` | Student | List subscriptions for the currently authenticated student (includes `monthly_fee`, `tuition_status`, `tuition_due_date`, `last_payment_date`) |
| `GET` | `/api/v1/students/{id}/subscriptions` | Authenticated (`StudentScope`) | List all subscriptions for a specific student (`only_active` filter) |
| `GET` | `/api/v1/subscriptions/{id}` | Authenticated (`StudentScope`) | Get single subscription with nested `course`, `student`, and tuition tracking details |
| `POST` | `/api/v1/courses/{id}/enroll` | Authenticated (`StudentScope`) | Direct course enrollment (student self-enrolls into course `{id}`; defaults monthly fee from course and sets tuition status to `unpaid`) |
| `POST` | `/api/v1/subscriptions` | Authenticated | Create subscription (student self-enrolls as `pending`; staff can specify `monthly_fee`, `tuition_status`, and dates) |
| `PUT` | `/api/v1/subscriptions/{id}` | Supporter / Admin | Update subscription fields (`course_id`, `student_id`, `status`, `is_active`, `monthly_fee`, `tuition_status`, `note`, dates) |
| `PATCH` / `PUT` | `/api/v1/subscriptions/{id}/tuition` | Supporter / Admin | Update monthly tuition & payment status (`paid`, `unpaid`, `pending`, `exempt`, `partial`), due date, and payment notes |
| `PATCH` / `PUT` | `/api/v1/subscriptions/{id}/status` | Supporter / Admin | Update subscription status (`pending`, `active`, `expired`, `cancelled`, `rejected`) & `note` |
| `DELETE` | `/api/v1/subscriptions/{id}` | Authenticated (`StudentScope`) | Delete subscription (students can cancel/delete their own `pending` subscriptions) |

### Exam Results, Teacher Comments & Parent Share Links (`Exams`)

| Method | Endpoint | Access / Role | Description |
| :---: | :--- | :--- | :--- |
| `GET` | `/api/v1/exams/details` | Authenticated (`StudentScope`) | Per-subject exam breakdown + `avg_percentage` + teacher/staff `comments` array |
| `PUT` / `PATCH` | `/api/v1/exams/{id}` | Teacher / Admin | Update an existing exam result (`exam_date`, `subject`, `chapter`, `total_q`, `correct`, `wrong`, `skipped`) with merged metric validation & server-recomputed `percentage` |
| `PUT` | `/api/v1/exams/comments` | Teacher / Supporter / Admin | Upsert comment (`student_id`, `exam_date`, `body`) on `(student_id, exam_date, teacher_user_id)` |
| `GET` | `/api/v1/exams/share-links` | Supporter / Admin | List generated share links (`student_id`, `exam_date`) with `status`, `sms_sent_at`, and `last_viewed_at` |
| `POST` | `/api/v1/exams/share-links` | Supporter / Admin | Generate 14-day base64url share link (`random_bytes(32)`, SHA-256 hash stored in MySQL) with optional `send_sms` |
| `POST` | `/api/v1/exams/share-links/send-sms` | Supporter / Admin | Generate 14-day share link and dispatch to parent via Melipayamak SMS (`exam_sms` rate limits & `sms_sent_at` tracking) |
| `DELETE` / `POST` | `/api/v1/exams/share-links/{id}` (`/revoke`) | Supporter / Admin | Revoke an active share link (`revoked_at = CURRENT_TIMESTAMP`) |
| `GET` | `/api/v1/public/exam-results/{token}` | Public (20 req/min IP limit) | Parent view of exam result & comments (no `national_id`/`phone`, `Cache-Control: no-store`, `X-Robots-Tag: noindex`, uniform `404`) |

### Weekly Appointment SMS Reminders (`Appointments`)

| Method | Endpoint | Access / Role | Description |
| :---: | :--- | :--- | :--- |
| `GET` | `/api/v1/admin/sms/patterns` | Admin | View active Melipayamak `bodyId` values, templates, and sources (`database`, `env`, `unset`) for `auth_2fa`, `appointment_reminder`, and `exam_result` |
| `PUT` / `PATCH` | `/api/v1/admin/sms/patterns` | Admin | Configure/persist separate Melipayamak `bodyId` (`appointment_body_id`, `exam_body_id`, `auth_body_id`) and pattern template text in `sms_pattern_settings` |
| `GET` | `/api/v1/appointments/sms/preview` | Admin | Preview weekly appointment reminder SMS status per student (`will_send`, `already_sent`, `changed_since_sent`, `failed`, `no_phone`, `no_appointment`, `multiple_appointments`), active `pattern` metadata, and rendered `sms_preview_text` |
| `POST` | `/api/v1/appointments/sms/send` | Admin | Bulk-send weekly appointment reminder SMS to primary parents via Melipayamak (`appointment_reminder` pattern: DB `sms_pattern_settings` → `MELIPAYAMAK_APPOINTMENT_BODY_ID` → optional request `body_id`, never falling back to 2FA login `MELIPAYAMAK_PATTERN_BODY_ID`; `422 APPOINTMENT_PATTERN_NOT_CONFIGURED` if unset) |
| `GET` | `/api/v1/appointments/sms/log` | Admin | Paginated `appointment_sms_log` audit history filterable by `week_date`, `student_id`, and `status` |

### Admissions & Testimonials (`Admissions`, `Testimonials`)

| Method | Endpoint | Access / Role | Description |
| :---: | :--- | :--- | :--- |
| `GET` / `POST` | `/api/v1/admissions` | Admin | List (`year`, `status`, `exam_type`, `consent_confirmed`, `q`, sorting) or create admission record (optional portrait upload; requires `consent_confirmed=true` to publish) |
| `GET` / `PUT` / `PATCH` / `POST` / `DELETE` | `/api/v1/admissions/{id}` | Admin | Fetch, update (including multipart photo replacement/removal), or delete admission record |
| `PATCH` / `PUT` | `/api/v1/admissions/{id}/status` | Admin | Publish or revert admission to `draft` (enforces `consent_confirmed=true` on publish) |
| `GET` / `POST` | `/api/v1/testimonials` | Admin | List moderation queue or create testimonial directly (`status` defaults to `approved`, `source='admin'`) |
| `POST` | `/api/v1/testimonials/bulk-reject` | Admin | Bulk-reject up to 100 testimonial IDs with optional shared `rejection_reason` |
| `GET` / `PUT` / `PATCH` / `DELETE` | `/api/v1/testimonials/{id}` | Admin | Fetch, edit (including linking optional `course_id`/`instructor_id`), or delete testimonial |
| `PATCH` / `PUT` | `/api/v1/testimonials/{id}/moderate` | Admin | Approve, reject, or revert testimonial to `pending` |

### Standard JSON Response Envelope

Every endpoint returns a consistent JSON structure:

```json
{
  "success": true,
  "data": { ... },
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 42,
    "total_pages": 3
  },
  "error": null
}
```

On validation or domain errors:

```json
{
  "success": false,
  "data": null,
  "pagination": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "شماره صفحه (page) باید یک عدد صحیح مثبت باشد",
    "details": {
      "page": "شماره صفحه (page) باید یک عدد صحیح مثبت باشد"
    }
  }
}
```

---

## 🧪 Testing

The PHP test suite uses **PHPUnit** and `RedisClient::useInMemoryDriver(true)` so core tests run deterministically.

```bash
# Run the PHPUnit test suite
./vendor/bin/phpunit

# Run specific core tests (Health, Input Validation, Redis Cache & RateLimiter, Sorting)
./vendor/bin/phpunit tests/Core/HealthTest.php
./vendor/bin/phpunit tests/Core/InputValidationTest.php
./vendor/bin/phpunit tests/Core/RedisCacheRateLimiterTest.php
./vendor/bin/phpunit tests/Core/SortingTest.php

# Run the Node.js verification & OpenAPI contract tests
npm test
```

---

## 🗺 Technical Notes

- User accounts (`users`) authenticate by mobile phone number (`phone`), normalized to canonical Iranian mobile format (`09XXXXXXXXX`) via `App\Core\PhoneNumber`.
- Course subscriptions (`App\Modules\Subscription`), staff activity logs (`App\Modules\ActivityLogs`), SMS pattern settings (`App\Modules\Settings`), admissions (`App\Modules\Admissions`), testimonials (`App\Modules\Testimonials`), and server-side list sorting (`App\Core\Sorting`) are all backed by MySQL migrations `001`–`013`.
- Protected `GET /api/v1/blog/categories`, `excused` attendance status in `PUT /api/v1/attendance/entries`, and `PATCH` support in the catch-all 404 route are wired in `routes/api.php`.

---

## 📄 License

Proprietary — © [FAMO Academy](https://famoacademy.ir). All rights reserved.
