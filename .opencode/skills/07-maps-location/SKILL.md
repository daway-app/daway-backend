---
name: 07-maps-location
description: Use for maps, geolocation, latitude/longitude, distance calculation, nearby pharmacy search, and map providers in Daway. Trigger on map, location, latitude, longitude, distance, nearby, Leaflet, coordinates, geolocation.
---

# Daway — Maps & Location

## Role

Act as a Senior Location and Maps Engineer for Daway.

## Verified current state

- **Frontend provider: Leaflet 1.9.4 + OpenStreetMap tiles** (CDN `unpkg.com`, `{s}.tile.openstreetmap.org`), used only in pharmacies create/edit/index Blade views (lat/lng pickers + modal map). No Google Maps, no Mapbox — do not assume or introduce a provider without asking.
- `pharmacies` table: latitude decimal(10,8), longitude decimal(11,8), both indexed.
- `users` table has latitude/longitude columns (added `2026_08_16_000001`).

## Architecture rule

Keep business logic independent from the map rendering provider:

```
Daway API → pharmacy locations (lat/lng + distance) → Web (Leaflet) / Flutter renders it
```

The API should return data (lat/lng, distance where applicable); the client decides how to render. Never couple backend queries to Leaflet/OSM specifics.

## Rules

1. Validate coordinates: latitude ∈ [-90, 90], longitude ∈ [-180, 180]; reject invalid before queries.
2. Distance: use an appropriate geographic method — for nearby search prefer bounding-box pre-filter on indexed lat/lng BEFORE computing Haversine on the remaining rows. Do not calculate distance for the whole table.
3. Nearby pharmacy search should filter in a cheap order: location box → availability (`whereHas` inventory) → status/operating hours (new `pharmacy_hours` set) → distance → sort.
4. Map failures must not break the app: always provide pharmacy list, address, and distance (where available) even if the tile provider is unreachable.
5. Privacy: do not store precise user location unless required by the feature; ask/flag before persisting new location columns.
6. Cost awareness: no external paid map APIs are in use; if one is proposed, check request volume, caching, limits, pricing before committing.

## Verified helpers to know

- `pharmacy_hours` uses new columns (`day_of_week`, `open_time`, `close_time`, `is_closed`) for open/closed logic; legacy columns are nullable leftovers — read the new set.
- Pharmacy responses already include lat/lng (Pharmacy model casts decimal) — keep them in API payloads as `latitude`/`longitude` strings/numbers consistent with existing `payload()` output.
- Leaflet assets load from CDN only — if the dashboard needs maps offline, flag it as a task instead of bundling a provider silently.

## Testing

Test: invalid coordinates rejected (422), nearby search returns correct ordering, pharmacies without coordinates excluded or handled gracefully, map-provider failure fallback, RTL positioning of Leaflet controls.
