---
name: 01-laravel-backend
description: Use for all Laravel backend engineering, Eloquent, controllers, services, Form Requests, policies, and code review in the Daway project (Laravel 13, PHP 8.4). Trigger on backend, controller, model, service, refactor, Laravel code.
---

# Daway — Laravel Backend Engineering

## Role

Act as a Senior Laravel 13 Backend Engineer working on the Daway pharmacy app.

## Environment (verified in this repo)

- Laravel ^13.8, PHP ^8.3 (Docker/CI run 8.4)
- MySQL (Aiven, production via Render), Sanctum ^4.3, spatie/laravel-permission ^8.3, spatie/laravel-activitylog 5.0.0, maatwebsite/excel 3.1.69
- No repositories, no observers, no scopes, no Jobs/Events/Mail classes exist — do not invent them
- Controllers query Eloquent directly; one service exists: `app/Services/NotificationGenerator.php`
- API payloads are hand-built arrays in controllers (see `04-api` skill), NOT API Resources (Resources are stubs)
- Routes: `routes/api.php` (JSON, mobile) and `routes/web.php` (Blade dashboard, role groups)

## Core Rules

Before modifying anything:

1. Inspect the existing implementation (routes, controller, model, migration, middleware, consumers).
2. Search the repository for usages before deleting or replacing code.
3. Never rewrite working architecture without a clear reason. Prefer small, targeted changes.
4. Do not introduce unnecessary dependencies. Do not duplicate existing functionality.

## Architecture preferences

- Thin controllers; Form Requests for complex validation (web controllers already use them; API Auth ones are dead stubs — validate inline like `Api\AuthController` does, or fix the stub and use it)
- Services only when business logic is complex enough to justify them (existing pattern: `NotificationGenerator` static helpers)
- Policies/Gates for authorization; API Resources only if they improve consistency (currently controllers build arrays)
- Keep the dual-role system in sync: `users.role` enum AND Spatie roles must both be set (see `RolePermissionSeeder`)
- `users.pharmacy_id` is a STRING linking to `pharmacies.pharmacy_custom_id` (relation: `pharmacyByCustomId`), not a numeric FK
- `pharmacy_hours` has BOTH legacy (`day`, `opening_time`, `closing_time`) and new columns (`day_of_week`, `open_time`, `close_time`, `is_closed`); read/write the new set
- Notifications in this app are DB rows (`App\Models\Notification`), not Laravel Notifications

## Eloquent

Always check for: N+1 queries, lazy-loaded relationships in loops, missing eager loading, over-fetching, missing indexes. Use `with()`, `withCount()`, `withAvg()` like `Api\PharmacyController` does. Use `whereHas` for filtered relations. Use `Cache::remember` with short TTLs (15–60s) for hot reads, and forget cache keys on writes (existing convention).

## Validation & Authorization

- Validate all user-controlled input; never trust IDs, role values, prices, availability, uploaded files
- Never rely only on frontend restrictions; every sensitive backend operation enforces authorization server-side
- Prevent IDOR: pharmacy users must only touch their own pharmacy records

## Error handling & security

- Use appropriate HTTP status codes; never expose stack traces, SQL, tokens, OTP codes, secrets, internal infrastructure
- Never hardcode credentials — everything in `.env`, never commit `.env`

## Code quality

Follow SOLID, DRY, KISS, Laravel conventions, PSR. Do not over-engineer. Laravel 13 uses `bootstrap/app.php` (no `Http/Kernel.php`).

## After changes

- Run `php artisan test` (or `composer test`) for relevant suites; run `php -l` syntax checks on changed PHP files
- Check `php artisan route:list` / `migrate:status` when routes/migrations change
- Report: files changed, reason per change, possible side effects, tests performed, remaining concerns

## Domain map (verified)

- Entities: User (patient/pharmacy/admin), Pharmacy, PharmacyHour, Medicine, MohMedicine, PharmacyMedicine (inventory pivot), MedicalProfile, Reminder, Rating, Favorite, FirstAid, Notification, OtpCode, SearchLog, AvailabilityNotification, Setting
- No `categories`, no `prescriptions`, no `prescription` tables exist — never generate code referencing them
- Seeders: `RolePermissionSeeder` (admin/pharmacy/patient), `AdminSeeder`, `PharmacySeeder` (PH-1234, PH-5678), `MedicineSeeder` (5 medicines) — all use `updateOrCreate`, keep that idempotent style
- Command: `php artisan moh:sync` (bulk syncs MoH drug catalog, chunked inserts of 1000)
