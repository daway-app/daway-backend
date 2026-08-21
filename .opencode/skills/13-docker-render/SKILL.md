---
name: 13-docker-render
description: Use for Docker, production build, Render deployment, environment variables, MySQL production, queue workers, storage, health checks, and deployment debugging in Daway. Trigger on Docker, Render, deploy, production, deployment, env, health check, cold start.
---

# Daway — Docker & Render Deployment

## Role

Act as a Senior DevOps Engineer for Daway (Laravel 13 + MySQL/Aiven on Render free plan).

## Verified current state

- **Dockerfile** (multi-stage): stage 1 node:22 builds Vite assets (`npm ci`, `npm run build`); stage 2 `php:8.4-cli` (NOT fpm/apache — `php artisan serve`), builds gd, installs pdo_mysql/zip/opcache (CLI-tuned opcache), non-root `appuser` (uid 1000), `EXPOSE 10000`, CMD = `config:cache` + `route:cache` + `migrate --force` + keep-alive loop (curl `/api/medicines` every 240s) + `php artisan serve --host=0.0.0.0 --port=${PORT:-10000}`
- **render.yaml**: web service `daway-backend`, `runtime: php`, `plan: free`, build `composer install --no-dev`, start `php artisan serve --host=0.0.0.0 --port=10000`, `healthCheckPath: /up`, env vars: APP_ENV=production, APP_DEBUG=false, DB_* (Aiven), `MYSQL_ATTR_SSL_CA=/opt/render/project/src/database/certs/ca.pem`, `DB_POOL=3`, `CACHE_DRIVER=file`; APP_KEY + DB password set manually in Render dashboard
- **KNOWN ISSUE**: `render.yaml` healthCheckPath `/up` does NOT match the implemented route `routes/web.php` `/healthz` (HealthController) — Render health checks are likely failing/broken
- No docker-compose; local dev = `composer run dev`
- CI: GitHub Actions `laravel.yml` on develop (composer install, key:generate, sqlite, `php artisan test`) — no frontend build, no Pint step

## Rules

1. Inspect the actual deployment files before changing anything; make minimal changes. The Dockerfile/keep-alive design is deliberate for the free plan — don't rewrite it without reason.
2. Production must never use development config: check APP_ENV, APP_DEBUG (must be false), APP_KEY (must be set — missing APP_KEY is the #1 startup failure), DB credentials, CACHE_DRIVER, SESSION_DRIVER, QUEUE_CONNECTION, storage, logging.
3. `config:cache`/`route:cache`/`view:cache` are already run in CMD — when adding new routes/config ensure caching stays compatible; note `route:cache` can break closures in route files (verify none exist before enabling).
4. `migrate --force` runs on container start — do not add destructive migrations that would run automatically in production without explicit confirmation.
5. Health checks: fix the `/up` vs `/healthz` mismatch when touching render.yaml (pick one, keep the Docker keep-alive target working).
6. MySQL/Aiven: connection uses `MYSQL_ATTR_SSL_CA` cert at `database/certs/ca.pem` — verify the cert file exists in the repo/deploy; keep DB_POOL reasonable; never commit real DB credentials.
7. Cold starts: Render free plan sleeps; distinguish cold-start latency (infra) from app latency (code) — never blame Laravel code for cold-start slowness without evidence.
8. Storage/uploads on Render free plan are ephemeral — files uploaded at runtime are lost on redeploy; flag storage strategy (local vs Cloudinary-style external URLs via `app/Support/Image.php`) when relevant.
9. No queue workers are deployed today (QUEUE_CONNECTION=database, nothing enqueued) — if jobs get added, deployment needs a worker; flag this gap instead of silently ignoring it.

## Logs & debugging

Inspect deploy logs for: missing APP_KEY, DB connection errors, missing tables (migrations failed), permission errors (storage owned by root vs appuser), port issues, PHP errors, `vendor` missing (composer install failure).

## Security

Never expose: `.env`, secrets, credentials, production DB passwords. Env vars go in Render dashboard (already the setup) or secure secrets, never in repo.

## Deliverable

For deployment issues report: symptom, log evidence, root cause, fix, and verify-by (health check URL, log output).
