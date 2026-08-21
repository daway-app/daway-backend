---
name: 05-auth-otp
description: Use for authentication, phone OTP flows, Sanctum tokens, login, registration, password handling, session security, and auth abuse prevention in Daway. Trigger on OTP, login, logout, token, Sanctum, authentication, phone verification.
---

# Daway — Authentication & OTP Security

## Role

Act as a Senior Security Engineer specialized in phone-OTP authentication for Daway.

## Verified OTP flow (read before modifying)

- `POST /api/otp/send` (rate limit 5/min/IP) → generates 6-digit code, stored in `otp_codes` (phone, otp, expires_at, 10-minute expiry) via `updateOrCreate` per phone
- `POST /api/otp/verify` (rate limit 5 per 15 min per IP+phone) → validates code + expiry, auto-creates a `patient`-role user if the phone is new, sets `phone_verified_at`, deletes the used code, returns a Sanctum plain-text token
- **IMPORTANT VERIFIED BEHAVIOR: the OTP code is currently returned inside the JSON response** (no SMS gateway connected). This is a known security trade-off. Do NOT silently log/duplicate codes, and when working on this flow, flag to the user if the code should remain in responses (dev/testing convenience) or be removed.
- Pharmacy login: `pharmacy_id` (custom ID) + password via `Hash::check`, requires active + `email_verified_at`
- Web login: `web/Auth/LoginController` (session + CSRF), guests redirected, roles redirect to their dashboards

## Non-negotiables

1. OTP codes must: expire, be single-use (delete after verify — already done), be rate-limited, never be logged, never appear in audit logs (`activity_log`), never be stored insecurely.
2. Account enumeration: avoid responses revealing whether an account exists, unless product behavior requires it — keep messages consistent.
3. Normalize phone numbers consistently (E.164 or local format defined by the project) — never create duplicate users due to formatting differences.
4. Never weaken security to make a feature work. If the OTP-in-response behavior must stay, say so explicitly in PRs and keep it rate-limited.
5. Sanctum tokens: honor `SANCTUM_EXPIRATION`; `logout` and `refresh-token` endpoints must invalidate/rotate properly.
6. Sessions (web): keep CSRF, `SESSION_SECURE_COOKIE` in production, correct `SESSION_DRIVER` (database).

## Authorization vs authentication

Authentication = "who are you?"; Authorization = "what may you do?". Always enforce authorization server-side: `EnsureRole` middleware (users.role), ownership checks on pharmacy resources, Spatie permissions where used. Prevent IDOR on profile/reminder/favorite/availability endpoints.

## When modifying auth, inspect

middleware, routes, policies, `bootstrap/app.php` throttling, session config, token config, rate limiting, CSRF, CORS, `users.role` vs Spatie role sync (`RolePermissionSeeder` keeps both in sync — new users must get both).

## Abuse prevention checklist

OTP spam (send limit), brute force (verify attempt limits + cooldowns), repeated verification, account enumeration, automated requests (throttle + cooldown between resend). Remember: `otp-verify` throttles by IP AND phone.

## Testing requirements

Test: new user, existing user, wrong OTP, expired OTP, repeated OTP, too many attempts, unauthorized access, role restrictions, phone normalization.
