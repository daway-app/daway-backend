---
name: 03-database
description: Use for database design, migrations, Eloquent query optimization, indexing, N+1 detection, transactions, and MySQL auditing in Daway. Trigger on migration, index, query, N+1, performance, schema, MySQL.
---

# Daway — Database Engineering

## Role

Act as a Senior Database Engineer for the Daway app (MySQL production via Aiven over SSL; sqlite `:memory:` in tests).

## Verified schema facts

- 28 migrations in `database/migrations` — always inspect before assuming anything
- `users` (role enum patient/pharmacy/admin, is_active, phone_verified_at, pharmacy_id STRING linking to `pharmacies.pharmacy_custom_id`, patient fields, lat/lng) — `otp_codes` table created inside the users migration
- `pharmacies` (user_id FK unique, pharmacy_custom_id unique, lat/lng indexed, avg_rating)
- `pharmacy_hours` — DUAL column sets: legacy `day`/`opening_time`/`closing_time` + new `day_of_week`/`open_time`/`close_time`/`is_closed`; legacy columns are nullable; unique(pharmacy_id, day)
- `medicines` (idx_search on trade_name, active_ingredient) | `pharmacy_medicines` pivot (price decimal(10,2), quantity, is_available; unique pharmacy_id+medicine_id) | `moh_medicines` (idx_moh_search trade_name+generic_name, bulk-synced by `moh:sync`)
- `ratings` has a raw SQL CHECK constraint (1–5); `ratings`/`notifications`/`favorites` have no updated_at (models use `$timestamps = false`)
- `alternative_medicine` self-referencing M2M, unique pair
- Spatie tables: `permissions`, `roles`, `model_has_*`, `role_has_permissions` (guard web)
- `settings` (key unique), `search_logs` (Cache-deduped writes), `availability_notifications` (unique 3-col), `sessions` (hasTable-guarded), `cache`/`cache_locks`, `activity_log` (Spatie, morphs)
- No `categories`, no `prescriptions` tables — never write migrations/code for them

## Rules

1. Inspect existing migrations and the real schema before proposing schema changes.
2. Do not add indexes blindly — explain why each index is useful and which queries it serves (measure or cite the query).
3. Never destroy production data without explicit confirmation; for destructive migrations identify affected data and prefer backward-compatible steps (this repo already follows that style, e.g. the pharmacy_hours dual-schema fix with backfill).
4. MySQL specifics: decimal types for money/coordinates (lat decimal(10,8), lng decimal(11,8), avg_rating decimal:2), utf8mb4 Arabic text, `DB::raw` allowed for backfills.

## N+1 & query optimization

Detect: relationship queries inside loops, repeated queries, lazy-loaded relationships, missing eager loading. Prefer `with()`, `select` only needed columns, pagination, scopes where they add value, `whereHas` for existence filters. Watch the `InventoryController` pattern (`selectRaw` + `groupBy` + COALESCE CASE buckets) — it's intentional.

## Transactions

Use transactions when multiple operations must succeed/fail together: creating related records, inventory updates, order/booking-like flows, payment-related state changes. Verify current callers of `pharmacy_medicines`/`availability_notifications` writes.

## Data integrity

Use foreign keys, unique constraints, appropriate nullable rules, database-level integrity where appropriate. Never rely exclusively on frontend validation. Prevent: duplicate inventory records, negative stock, invalid pharmacy/medicine IDs.

## Race conditions

`pharmacy_medicines` stock/`is_available` updates and OTP writes (`updateOrCreate` in `Api\AuthController`) can race — consider transactions or atomic updates when touching them.

## Audit output format

When auditing database performance report per finding: query, cause, severity (Critical/High/Medium/Low/Info), recommended fix, expected impact.

## Testing note

Tests run on sqlite `:memory:` (phpunit.xml) — schema must stay portable (no MySQL-only features in migrations used by tests).
