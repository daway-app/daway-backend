# AGENTS.md — Daway backend

Compact instructions for AI agents working in this repo. The repo itself is the source of truth; this file only highlights what is easy to miss.

## What this is

- **Product**: Daway — Palestinian pharmacy/medicine availability app. Backend = Laravel 13 (PHP 8.4) API + Blade web dashboard. Mobile = Flutter (separate repo, consumes this API).
- **Stack**: Laravel 13, PHP 8.4, MySQL (Aiven on Render, SSL required), Sanctum tokens, Spatie Permission + ActivityLog, Vite + Tailwind v4 (via `@tailwindcss/vite`), vanilla JS, Blade, custom CSS, Cloudinary uploads.
- **Locales**: Arabic-first (`ar`); UI is RTL; English supported via `resources/lang/{ar,en}/`. Default font: Cairo (loaded by Vite plugin, not a CDN).

## Layout (only the non-obvious parts)

- `app/Http/Controllers/Api/` — mobile-facing JSON API. `app/Http/Controllers/web/` — Blade dashboard. Both share `app/Http/Controllers/Controller.php`.
- `app/Models/` — domain models. **There are NO `Category` or `Prescription` models.** Don't invent them.
- `app/Services/Ai/` — `AiAssistantClient`, `OcrClient`, `MedicineResolver`. Singletons registered in `AppServiceProvider`.
- `app/Console/Commands/` — `MohImport`, `MohSync`, `GenerateChatbotMapping`. Run via `php artisan moh:import`, `php artisan moh:sync`, `php artisan chatbot:mapping`.
- `app/Http/Resources/` — exist (`NotificationResource`, `PatientInquiryResource`, `PharmacyMedicineResource`, `RatingResource`) but most controllers return hand-built arrays. Don't add new Resources unless you also rewire the controller.
- `app/Http/Requests/Api/` — mixed. Some are wired (`PatientProfileRequest`, `PharmacyMedicineRequest`, `RatingRequest`, etc.); the auth stubs (`SendOTP`, `VerifyOTP`, `PharmacyLogin`) are dead — validation is inline in `Api/AuthController`.
- `database/data/moh_medicines.json` and `database/data/chatbot_medicines.json` are committed seed catalogs for the MoH importer.
- `database/certs/ca.pem` is committed; the path is wired into `MYSQL_ATTR_SSL_CA` (see `render.yaml` and `.env.example`).
- `postman/` and `langPath())` are intentionally gitignored; do not commit them.

## Skills (`.opencode/skills/`)

When a task matches a skill, load it with the `skill` tool. Available skills:

| Skill | Use when |
|---|---|
| `01-laravel-backend` | Any Laravel/Eloquent/controller/model work |
| `02-frontend` | Blade/CSS/JS/RTL/Vite/frontend work |
| `03-database` | Migrations, queries, indexes, N+1, MySQL |
| `04-api` | REST endpoints, JSON payloads, Sanctum, mobile API |
| `05-auth-otp` | OTP, login, tokens, auth security |
| `06-medicine-pharmacy` | Domain work: medicines, pharmacies, inventory, availability |
| `07-maps-location` | Maps, coordinates, distance, nearby search |
| `08-security` | Security audits/fixes (OWASP, XSS, IDOR, CSRF, secrets) |
| `09-performance` | Slow queries, caching, latency, cold starts |
| `10-code-audit` | Full-project audits (read-only — do not modify files) |
| `11-testing` | PHPUnit tests, coverage, regression |
| `12-ui-ux` | Design system, RTL UX, accessibility, medical UX |
| `13-docker-render` | Docker, Render deploy, env vars, health checks |
| `14-git-github` | Git workflow, commits, PRs, CI |

## Hard rules (never break)

1. **Never commit secrets.** Forbidden in git: `.env`, `*.sql`, `daway_backup.sql`, `notif-test.js`, `oracle_vm_setup.sh`, `langPath())`. Listed in `.gitignore`; keep it that way.
2. **Never weaken security.** Do not log/return OTPs or tokens. Do not remove rate limits (`throttle:otp`, `throttle:otp-verify`, `throttle:login`, `throttle:30,1`). Do not skip `auth:sanctum` or `role:` middleware.
3. **Audits are read-only.** During a `10-code-audit` run, produce the report only; do NOT modify files. Stop and wait.
4. **Dual role system.** `users.role` enum AND Spatie role must both be set. Seeder: `database/seeders/RolePermissionSeeder.php` (`admin`, `pharmacy`, `patient`).
5. **API response shape.** Hand-built arrays with `success` / `message` / `data` / `pagination` keys. Messages are **English in API responses** (see `Api/AuthController.php`); web UI messages are Arabic from `resources/lang/ar/`. Some controllers use a private `payload()` helper — copy that style in the same controller.
6. **RTL/UI.** Respect the teal design tokens in `resources/css/app.css`. Do not redesign without being asked.
7. **Tests must pass.** Run `composer test` (or `php artisan test`) after behavior changes. Never delete tests to make the suite green.

## Dev commands

- `composer setup` — one-shot: install PHP deps, copy `.env`, key, migrate, install Node, build assets.
- `composer dev` — runs `php artisan serve` + queue listener + pail + Vite concurrently.
- `composer test` — clears config and runs `php artisan test` (sqlite `:memory:` per `phpunit.xml`).
- `php artisan route:list` / `php artisan migrate:status` — quick sanity checks.
- `php artisan moh:import` — import `database/data/moh_medicines.json` into `moh_medicines` table.
- `php artisan moh:sync` — refresh MoH catalog (uses `MOH_SSL_VERIFY` from `.env`).
- `php artisan chatbot:mapping` — regenerate `database/data/chatbot_medicines.json`.
- `npm run build` / `npm run dev` — Vite (input list is in `vite.config.js`, not the default `resources/css/app.css` + `resources/js/app.js`).

## API conventions (mobile API)

- Public: `POST /api/otp/send`, `POST /api/otp/verify`, `POST /api/login/pharmacy`, `GET /api/medicines*`, `GET /api/pharmacies*`.
- Protected by `auth:sanctum`. Pharmacy-scoped routes under `/api/pharmacy/*` additionally require `role:pharmacy`.
- AI/OCR (`/api/chat`, `/api/ocr/medicine`) require `auth:sanctum` and are throttled `30,1`.
- Token expiry: `SANCTUM_EXPIRATION` (default 10080 min = 7 days). Returned in `data.token`; user payload in `data.user`.
- Known trade-off: OTP codes are returned in the JSON response because there is no SMS gateway wired up. Flag before changing this.
- Custom rate limiters defined in `bootstrap/app.php` (`otp`, `otp-verify`, `login`, `api`).

## Deploy (Render)

- `render.yaml` is authoritative for prod env vars. **Never put real secrets in `render.yaml`** — set `APP_KEY`, DB password, etc. in the Render Dashboard. `MYSQL_ATTR_SSL_CA` already points to `database/certs/ca.pem` in the repo.
- `startCommand`: `php artisan serve --host=0.0.0.0 --port=10000`. `healthCheckPath`: `/healthz` (matches `routes/web.php:40`).
- `Dockerfile` is multi-stage: Node builds Vite assets, then PHP 8.4 CLI runs the app. On boot it runs `config:cache` + `route:cache` + `migrate --force` and starts a keep-alive loop pinging `/api/medicines` every 240s (free-tier sleep prevention).
- Logging goes to `stderr` in prod so it surfaces in the Render dashboard.

## CI

- `.github/workflows/laravel.yml` runs on push/PR to `develop`. Uses PHP 8.4, sqlite in-memory, `php artisan test`. Mirrors `phpunit.xml` settings.

## Known issues / quirks

- OTP codes are returned in API responses (no SMS provider). Don't "fix" without coordinating.
- `bootstrap/app.php` registers Laravel's built-in health route at `/up` **in addition to** the custom `/healthz`; Render uses `/healthz`.
- `composer.json` requires `^8.3` PHP but CI and Dockerfile use 8.4 — bump the `require` line if you intentionally target 8.4 only.
- Vite input list is in `vite.config.js` and is extensive; don't add new CSS/JS files without registering them there.
- Free-tier DB (Aiven MySQL on Render) sleeps after inactivity; the `Dockerfile` keep-alive loop mitigates this.
- No `opencode.json` exists at the repo root; if you add one, keep it minimal.
