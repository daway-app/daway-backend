---
name: 06-medicine-pharmacy
description: Use for the Daway domain: medicines, MohMedicine catalog, pharmacies, pharmacy inventory, availability, search, and healthcare workflows. Trigger on medicine, pharmacy, inventory, availability, search, stock, pharmacy medicine.
---

# Daway — Medicine & Pharmacy Domain

## Role

Act as a Senior Backend Engineer specialized in pharmacy and medicine availability systems for Daway (Palestine pharmacy market).

## Verified domain model

- **Medicine** (`medicines`): trade_name, active_ingredient, description, image, is_available, stock; `idx_search(trade_name, active_ingredient)`; self-referencing `alternatives` (M2M `alternative_medicine`, unique pairs)
- **MohMedicine** (`moh_medicines`): official MoH catalog — trade_name, generic_name, manufacturer, dosage_form, product_class, origin, moh_product_id/moh_drug_id, official_price, packaging, company, availability; bulk-synced by `php artisan moh:sync` (chunked inserts of 1000, full delete + insert)
- **Pharmacy** (`pharmacies`): pharmacy_custom_id (unique, e.g. PH-1234), lat/lng indexed, avg_rating, is_active; `pharmacy_hours` (new column set: day_of_week/open_time/close_time/is_closed)
- **PharmacyMedicine** (`pharmacy_medicines`): inventory pivot — price decimal(10,2), quantity, is_available; unique(pharmacy_id, medicine_id)
- Related: AvailabilityNotification (user+medicine+pharmacy, unique triple, is_notified), SearchLog (deduped via Cache), Rating (1–5 CHECK), Favorite (morph: medicine/first_aid)

## Domain rules

1. Distinguish: medicine exists (catalog) vs available at pharmacy (inventory) vs quantity known/unknown. Never conflate them in responses.
2. Availability responses must be explicit and Arabic: متوفر / محدود / غير متوفر / غير معروف — mirror whatever enum/format the existing controllers use.
3. Medicine search: LIKE `%q%` on trade_name/active_ingredient (medicines) and generic_name/manufacturer/company (moh) — match `Api\MedicineController` patterns. Search must be paginated and rate-aware (it is a high-traffic endpoint; SearchLog::track dedupes).
4. Do not create duplicate medicine records unnecessarily — prefer `updateOrCreate` matching on natural keys (existing seeder style) and search before insert in admin flows.
5. Pharmacy search by availability uses `whereHas('pharmacyMedicines', is_available && quantity > 0)` — keep that pattern; optimize filtering order (location/availability/status).
6. Prevent: duplicate inventory rows (unique constraint exists), negative stock, invalid IDs.
7. Images: `app/Support/Image.php` helper resolves asset vs Cloudinary-style URLs — use it; never hardcode image URLs.
8. Healthcare safety: Daway is a search/availability app, NOT a diagnosis tool. Never generate medical advice content; keep search distinct from anything that looks like a diagnosis.

## Data integrity concerns

- Inventory updates and availability notifications can race — use transactions/atomic updates when touching `pharmacy_medicines` + `availability_notifications` together
- `users.pharmacy_id` string-link vs `pharmacies.id` FK — know which one each query needs

## Performance

Medicine search and pharmacy lists are the hot endpoints: indexes exist (idx_search, idx_moh_search, lat/lng) — verify new queries can use them; paginate; consider `Cache::remember` (15–60s) for stable lookups; avoid loading all pharmacies to filter by distance (see 07-maps-location).

## Before changing domain logic

Inspect: migrations, models, `Api\MedicineController`/`Api\PharmacyController` payload helpers, web Admin controllers (Medicine/Inventory), Blade consumers, and any Flutter consumers — keep response shapes stable.
