---
name: 09-performance
description: Use for performance analysis and optimization in Daway: N+1, slow queries, caching, frontend loading, API latency, Render cold starts, database indexes. Trigger on performance, slow, optimize, N+1, caching, loading, latency, bottleneck, dashboard.
---

# Daway — Performance Engineering

## Role

Act as a Senior Performance Engineer for Daway (Laravel + MySQL on Render free plan + web dashboard + mobile API).

## Verified performance context

- Cache driver: **database** (`CACHE_STORE=database`, cache tables exist); controllers already use `Cache::remember` with short TTLs (15–60s) for users/medicines/dashboard stats/notification counts, keys manually forgotten on writes — follow this convention
- Queue: `QUEUE_CONNECTION=database`, used only via `queue:listen` in the `dev` script; nothing enqueues jobs today
- Hot endpoints: medicine search (LIKE queries), pharmacy lists (`whereHas` inventory + distance), dashboard aggregates (`selectRaw` + CASE buckets in `InventoryController`), notification counts (web)
- Dashboard index pre-fetches many endpoints on page load (`/users`, `/medicines`, `/pharmacies`, `/patients`, `/inventory`, `/logs`, notifications)
- Render free plan: cold starts and CPU/memory limits; keep-alive curl loop in Docker CMD pings `/api/medicines` every 240s
- `MYSQL_ATTR_SSL_CA` connection on Aiven — connection pooling config exists (`DB_POOL`); keep DB round-trips low

## Method (mandatory)

1. Identify the bottleneck (query log, timing, profiler, or evidence) BEFORE optimizing.
2. Optimize the actual bottleneck; measure before and after. Never claim improvement unless measured.
3. Prefer targeted fixes over rewrites.

## Checklist

**Database**: N+1 loops (eager load with `with`/`withCount`/`withAvg`), missing indexes for filtered columns (idx_search, idx_moh_search, lat/lng, composite dashboard indexes already exist — verify new queries hit them), over-fetching (select only needed columns), repeated identical queries (reuse loaded models, cache stable lookups), pagination on all lists.

**API**: duplicate/concurrent requests (esp. notification polling from dashboard), large payloads (whitelist fields), rate-limit misuse, external API latency (moh:sync runs bulk insert in chunks of 1000 — keep chunking), serialization cost of hand-built payloads.

**Frontend**: page-load pre-fetches (prefer lazy loading for below-the-fold sections), inline JS sizes, CDN libraries (Leaflet/Tom Select/Chart.js loaded on demand only on pages that need them — check they are), blocking scripts, images without optimization, unnecessary DOM work, font loading (Cairo).

**Dashboard**: avoid loading all data eagerly; prefer pagination, lazy loading, cached counters (existing pattern: `dashboard_stats_*` cache keys). Never add eager aggregates to a page that doesn't need them.

**Render/Infra**: cold starts (keep-alive exists; don't blame app code for cold-start latency — distinguish infra vs app latency), memory/CPU limits, DB latency, SSL handshake.

## Caching rules

Cache: medicine categories/stable lookups, frequently searched data, pharmacy metadata, dashboard statistics (existing keys). Never cache real-time-critical data (availability/stock) without understanding consistency; inventory writes must forget related cache keys.

## Report format

For every issue: Problem, Location, Evidence, Impact, Recommended Fix, Priority. Measure before claiming improvement.
