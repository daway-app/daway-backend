---
name: 10-code-audit
description: Use for full-project audits of Daway: dead code, unused routes/controllers/models/migrations, duplicate code, broken links, architecture review, and structured audit reports. Trigger on audit, code review, dead code, unused, duplicate, full check, inspect project.
---

# Daway — Code Audit

## Role

Act as a Senior Code Auditor for the entire Daway project. Audits are READ-ONLY: do not modify the repository during an audit unless explicitly requested.

## Audit protocol (use this ordering)

1. **Repository discovery** — structure, composer.json/package.json, routes, config, .env.example, README.
2. **Architecture audit** — controllers/services/models layering, separation of concerns, duplication, coupling, maintainability, scalability.
3. **Database audit** — migrations, indexes, FKs, duplicate tables, unused columns, relationships, N+1, transactions.
4. **API audit** — endpoint consistency, auth on every protected route, authorization, validation, response shape (success/message/data/pagination), pagination, error handling, rate limits.
5. **Backend audit** — controllers/models/requests/middleware quality, dual-role sync (users.role vs Spatie), dead code.
6. **Frontend audit** — Blade/CSS/JS: unused CSS (e.g., stale root-level `resources/css/*.css` not in Vite input), inline scripts, broken assets, RTL, accessibility, duplicate components.
7. **Security audit** — see 08-security checklist (OTP exposure, secrets, IDOR, XSS, CSRF, mass assignment, rate limits, logging).
8. **Performance audit** — see 09-performance checklist (N+1, cache usage, pre-fetches, cold starts).
9. **Dead code detection** — unused files/routes/controllers/models/migrations/components/JS/CSS/dependencies; broken links; broken endpoints; duplicated functionality.
10. **Testing audit** — coverage gaps (currently only skeleton tests exist), missing tests for critical flows.
11. **Deployment audit** — Dockerfile, render.yaml (KNOWN ISSUE: `healthCheckPath: /up` vs actual route `/healthz`), env vars, queue/storage/logging config.
12. **Final report** — structured deliverable.

## Critical rule: do not delete anything merely because it appears unused

Before marking something unused, search ALL of: PHP references, dynamic references (variable callables, `__invoke`, route params), routes, Blade includes/`@extends`, JavaScript strings, config files, tests, seeders, and API consumers. Only report as "unused" when the search proves it. If unsure, mark as "possibly unused — verify manually".

## Known audit targets (verified leads)

- `render.yaml` healthCheckPath `/up` ≠ implemented route `/healthz` (routes/web.php) — broken Render health checks
- Root `resources/views/auth.blade.php` references stale Vite asset paths (built entry moved to `resources/css/auth/auth.css`)
- Stale root-level CSS duplicates not in Vite input list (`app_layout.css`, `auth.css`, `dashboard.css`, etc. at `resources/css/` root)
- Dead stub files: `app/Http/Requests/Api/{SendOTP,VerifyOTP,PharmacyLogin}Request.php` (authorize() => false), `app/Http/Resources/*` (all stubs), `app/Http/Middleware/SetLocale.php` (unused variant), `resources/js/app.js` (empty)
- FontAwesome `fas` classes used in pharmacy dashboard without a FontAwesome CDN include
- `routes/api.php` notification endpoints consumed by web dashboard — verify auth model
- Junk file named `langPath())` at repo root (PsySH stray output, not gitignored)
- Deleted `postman/` collection (uncommitted deletion in git)
- API OTP code returned in response (product decision — flag, do not silently "fix")

## Severity

Critical / High / Medium / Low / Info.

## Deliverable — structured report

1. Executive Summary
2. Critical Issues
3. Security
4. Performance
5. Backend
6. Frontend
7. Database
8. API
9. Dead Code
10. Architecture
11. Testing
12. Deployment
13. Recommended Fix Order (by severity × effort, smallest safe wins first)

## After the audit

Do not start fixing anything automatically — present the report and wait for explicit instruction on which issues to fix.
