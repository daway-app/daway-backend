---
name: 11-testing
description: Use for writing and running tests in Daway: PHPUnit unit/feature tests, API tests, auth/OTP tests, authorization tests, database tests, regression testing. Trigger on test, phpunit, coverage, feature test, unit test, regression.
---

# Daway — Testing

## Role

Act as a Senior Test Engineer for Daway.

## Verified test setup

- PHPUnit ^12 (phpunit.xml): Unit + Feature suites, sqlite `:memory:`, array cache, sync queue, `APP_ENV=testing`, `SESSION_DRIVER=array`
- Run with: `php artisan test` or `composer test` (runs `config:clear` first)
- **Current state: only the two skeleton tests exist (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`) — the project has NO real test coverage.** Prioritize tests for existing critical flows over new features.
- CI (`.github/workflows/laravel.yml`) runs `php artisan test` with sqlite on the `develop` branch (push + PR)
- Factories: only `UserFactory` exists (with `unverified()` state); no factories for Pharmacy/Medicine/etc. — create them (or use `create()` models directly) as needed
- Seeds available: `RolePermissionSeeder`, `AdminSeeder`, `PharmacySeeder` (PH-1234/PH-5678), `MedicineSeeder` — idempotent `updateOrCreate` style; tests should not depend on seeded state unless seeded explicitly

## Coverage priorities (in order)

1. **Auth/OTP** (most critical): send OTP, verify OTP (new user auto-create, existing user, wrong code, expired code, repeated verification, rate limits), pharmacy login, logout, token expiry, role enforcement.
2. **Authorization**: patient/pharmacy/admin role separation; IDOR — user A cannot access user B's pharmacy/reminder/profile; pharmacy users restricted to their own pharmacy.
3. **API contracts**: status codes, validation (422), response shape `success/message/data/pagination`, Arabic messages, pagination metadata, `medicinePayload`/`payload` stability (mobile clients depend on them).
4. **Medicine/Pharmacy domain**: search (exact, partial, Arabic), medicine→pharmacies availability filter (`whereHas` is_available && quantity > 0), nearby/coordinates validation, inventory updates.
5. **Web dashboard flows**: login redirect by role, admin CRUD (pharmacies, medicines, users, settings), pharmacy medicine CRUD, notifications read/mark-all, profile update, locale switch.
6. **Database**: relationships, unique constraints (pharmacy_id+medicine_id, favorites triple, availability_notifications triple), transactions, inventory consistency, dual pharmacy_hours column sets.
7. **Reminders**: CRUD, taken endpoint, reminder_date behavior, ownership.

## Rules

- Behavior-based tests, not implementation-detail tests.
- Follow Laravel testing conventions: `RefreshDatabase`, `Sanctum::actingAs`, `actingAs` with roles, `assertJsonPath`/`assertJsonStructure` for contract checks, `assertStatus` codes.
- Keep the sqlite compatibility in mind: no MySQL-only features in tests; if a query relies on MySQL functions, adapt the test (or document).
- **Regression rule**: when fixing a bug → 1) reproduce it in a failing test, 2) fix the code, 3) run the test, 4) run related suites.

## Before finishing

Run `composer test` (or `php artisan test`), report: passed / failed / skipped / untested areas. Fix failures before declaring done. Do not delete tests to make suites pass.
