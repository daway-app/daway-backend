# Daway — Project Brain (AGENTS.md)

This is the central instruction file for AI agents working on the Daway project. It coordinates 14 specialized skills in `.opencode/skills/` and defines what is never to be touched.

## Project at a glance

- **Product**: Daway — Palestinian pharmacy/medicine availability app (Laravel API + Flutter/mobile + Blade web dashboard)
- **Stack**: Laravel 13 (PHP 8.4 in Docker/CI), MySQL (Aiven on Render), Sanctum, Spatie Permission + ActivityLog, Vite, Blade, vanilla JS, custom CSS
- **Locales**: Arabic-first (ar), RTL UI, Cairo font; English supported
- **Deploy**: Render free plan, `php artisan serve` on port 10000, Dockerfile multi-stage

## The 14 skills — when to use which

| Skill | Use when |
|---|---|
| `01-laravel-backend` | Any backend/Laravel/Eloquent/controller/model work or review |
| `02-frontend` | Blade/CSS/JS/RTL/Vite/frontend work |
| `03-database` | Migrations, queries, indexes, N+1, schema, MySQL |
| `04-api` | REST endpoints, JSON payloads, Sanctum, mobile API consumers |
| `05-auth-otp` | OTP, login, tokens, auth security, abuse prevention |
| `06-medicine-pharmacy` | Domain work: medicines, pharmacies, inventory, availability, search |
| `07-maps-location` | Maps, coordinates, distance, nearby search |
| `08-security` | Security audits and fixes (OWASP, XSS, IDOR, CSRF, secrets) |
| `09-performance` | Slow queries, caching, loading, latency, cold starts |
| `10-code-audit` | Full-project audits (read-only), dead code, reports |
| `11-testing` | PHPUnit tests, coverage, regression |
| `12-ui-ux` | Design system, RTL UX, accessibility, medical UX |
| `13-docker-render` | Docker, deployment, env vars, health checks, production issues |
| `14-git-github` | Git workflow, commits, PRs, CI |

## Hard rules (never break)

1. **Project root is the repo itself** — everything here assumes the repo you are working in. Verify paths before acting.
2. **Never rewrite working code** — prefer small, targeted changes; inspect before modifying; search usages before deleting.
3. **Never commit secrets** — `.env`, DB passwords, API keys, OTP materials, `*.sql` backups, `daway_backup.sql`, `notif-test.js`, `oracle_vm_setup.sh` are all forbidden.
4. **Never weaken security** — no logging/returning OTPs or tokens, no removing rate limits, no skipping authorization.
5. **Audits are read-only** — during a `10-code-audit` run, do NOT modify files; produce the structured report and wait for instructions.
6. **Don't invent domain entities** — there are NO categories or prescriptions tables/models; no Jobs/Events/Mail/Notifications classes, no repositories, no observers exist. Build on what exists.
7. **Keep the dual role system in sync** — `users.role` enum AND Spatie roles must both be set (see `RolePermissionSeeder`).
8. **Respect response conventions** — API payloads are hand-built arrays `success/message/data/pagination` with ARABIC messages; match the existing controllers' `payload()` style.
9. **Don't redesign the UI without being asked** — respect the teal design tokens in `resources/css/app.css`; incremental improvements only.
10. **Tests must pass** — run `composer test` after changes affecting behavior; never delete tests to make suites green.

## Known issues (from the latest audit findings — treat as facts until fixed)

- `render.yaml` healthCheckPath `/up` ≠ actual route `/healthz` — Render health checks likely broken
- API OTP codes are returned in JSON responses (no SMS gateway) — known trade-off, flag before changing
- `app/Http/Requests/Api/*` (SendOTP/VerifyOTP/PharmacyLogin) are dead stubs; validation is inline in `Api\AuthController`
- `app/Http/Resources/*` are stubs; controllers build arrays manually
- Stale root-level CSS files at `resources/css/` root not in the Vite input list; root `auth.blade.php` references stale asset paths
- FontAwesome `fas` icons used without a FontAwesome CDN include
- Junk file named `langPath())` at repo root (PsySH stray) — candidate for deletion/gitignore
- Only skeleton tests exist — the project has no real coverage yet

## Full audit sequence (run as one session when asked to audit "everything")

1. Repository discovery → 2. Architecture audit → 3. Database audit → 4. API audit → 5. Backend audit → 6. Frontend audit → 7. Security audit → 8. Performance audit → 9. Dead code audit → 10. Testing audit → 11. Deployment audit → 12. Final structured report (per `10-code-audit` deliverable format).

After the report, STOP and wait for explicit instruction on which issues to fix.

## Repo hygiene reminders

- Feature branches follow `feature/US-XX-slug`; CI (tests on sqlite) runs on push/PR to `develop`
- Keep `.env.example` in sync when env vars are added
- Use `composer test` / `npm run build` / `php artisan route:list` / `php artisan migrate:status` to verify changes
