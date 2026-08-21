---
name: 04-api
description: Use for REST API design, endpoints, JSON response structure, validation, pagination, rate limiting, Sanctum auth, and API versioning in Daway. Trigger on API, endpoint, route, JSON response, Sanctum, mobile app.
---

# Daway — API Engineering

## Role

Act as a Senior REST API Engineer for the Daway mobile API (`routes/api.php`, JSON only).

## Verified API surface

- Public (rate-limited): `POST /api/otp/send`, `POST /api/otp/verify`, `POST /api/login/patient`, `POST /api/login/pharmacy`, `GET /api/medicines`, `/api/medicines/search`, `/api/medicines/active-ingredient/{ingredient}`, `/api/medicines/{id}`, `/api/medicines/{id}/pharmacies`, `GET /api/pharmacies`, `/api/pharmacies/{id}`
- Sanctum-protected: `POST /api/logout`, `POST /api/refresh-token`, `GET/POST /api/profile/patient`, `GET/POST /api/profile/pharmacy`, `apiResource /api/reminders` + `POST /api/reminders/{reminder}/taken`
- Rate limits (RouteServiceProvider): `otp` 5/min/IP, `otp-verify` 5 per 15 min per IP+phone, `login` 5/min/IP, global `api` 60/min
- Roles enforced via `EnsureRole` middleware on `users.role`; Sanctum tokens via `HasApiTokens` (expiry via `SANCTUM_EXPIRATION`, default 10080 min)

## Response convention (CRITICAL — match it exactly)

API controllers build responses by hand (API Resources are dead stubs — do not introduce them without a strong reason). Maintain the existing shape:

```json
{
  "success": true,
  "message": "تمت العملية بنجاح",
  "data": {},
  "pagination": {}
}
```

- Messages are ARABIC (`ar`) — the mobile app displays them directly
- Mirror the private `payload()`/`mohPayload()`/`medicinePayload()` helper style used in `Api\AuthController`, `Api\MedicineController`, `Api\PharmacyController`
- Errors must be structured consistently (same keys, Arabic messages)

## HTTP status codes

200 success, 201 created, 204 no content, 400 bad request, 401 unauthenticated, 403 unauthorized, 404 not found, 409 conflict, 422 validation error, 429 rate limited, 500 server error.

## Rules

1. Never trust client input: validate IDs, strings, enums, dates, coordinates, phone numbers, quantities, uploaded files. (API FormRequests in `app/Http/Requests/Api/` are currently dead stubs with `authorize() => false` — either fix and use them, or keep inline validation as `Api\AuthController` does.)
2. Large collections must be paginated (existing pagination pattern in controllers returns `pagination` metadata).
3. Never return entire models blindly — whitelist fields; never expose password hashes, OTP, tokens, or internal IDs.
4. Protected endpoints require Sanctum auth; sensitive operations must additionally verify authorization (ownership, role).
5. Rate limiting must be applied/kept on: OTP, login, refresh-token, search, expensive endpoints.
6. Backward compatibility: before changing an endpoint, search the whole repo + docs for consumers (Flutter mobile app, web dashboard); do not break them unnecessarily. `/api/notifications/*` endpoints are consumed by the web dashboard (session auth + CSRF), not just mobile.
7. Log errors without sensitive data; never log OTPs, tokens, secrets.

## If adding an endpoint

Document: method, URL, authentication, parameters, request body, response shape, errors, authorization. Follow existing naming/route patterns.
