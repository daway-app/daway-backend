---
name: 08-security
description: Use for security audits and fixes in Daway: OWASP review, SQL injection, XSS, CSRF, mass assignment, IDOR, secrets, file uploads, logging, rate limiting. Trigger on security, vulnerability, XSS, injection, CSRF, IDOR, audit, exploit, CORS.
---

# Daway — Security Engineering

## Role

Act as a Senior Application Security Engineer for Daway (healthcare-adjacent data: patients, phones, medical profiles, pharmacy inventory).

## Known context (verified)

- Auth: Sanctum tokens (API), session+CSRF (web), OTP flow where **the code is returned in the API response** (no SMS gateway) — see 05-auth-otp; treat this as a known risk and never add further exposure (e.g., never log it)
- Spatie activity log exists (app may record user actions — ensure it never captures OTPs/tokens/secrets)
- Files: medicine/pharmacy logos & images; `app/Support/Image.php` resolves URLs; uploads must be validated
- Exports: `app/Exports/LogsExport.php` (Excel) — keep its CSV-injection-safe behavior
- Render production: `APP_DEBUG=false`, env vars via Render dashboard; secrets must stay server-side

## Audit checklist

1. **Injection**: all queries via Eloquent/prepared statements; no raw user input concatenation (`DB::raw` only for fixed SQL/backfills, never with user input); LIKE queries escape `%`/`_` where needed.
2. **XSS**: Blade output — prefer `{{ }}` escaping; `{!! !!}` only for trusted content; user-generated content (reviews/comments in ratings, pharmacy names) must be escaped; inline JS interpolating server data must be JSON-escaped safely.
3. **CSRF**: web routes via session auth must keep CSRF tokens (meta tag exists in layouts; fetch calls send `X-CSRF-TOKEN`); API routes are token-based.
4. **Mass assignment**: check `$fillable`/`$guarded` on User, Pharmacy, PharmacyMedicine, Reminder, MedicalProfile, Setting — never allow role/pharmacy_id/is_active injection via create/update.
5. **IDOR**: profile, pharmacy, reminder, favorite, availability-notification endpoints must verify ownership; pharmacy users restricted to their own pharmacy (`pharmacyByCustomId` string link is a common spot for bugs).
6. **Auth**: OTP expiry (10 min), single-use, rate limits (send 5/min, verify 5/15min), token expiry (`SANCTUM_EXPIRATION`), session security (`SESSION_SECURE_COOKIE` in prod), no enumeration leaks.
7. **Rate limiting**: OTP, login, search, expensive endpoints — verify throttles still apply after changes.
8. **Secrets**: search repo for hardcoded keys/passwords (also check `notif-test.js`, `daway_backup.sql`, `oracle_vm_setup.sh` patterns in `.gitignore`); never commit `.env`; check git history for leaked secrets.
9. **File uploads**: validate type/MIME/size/extension server-side; never trust extension alone; prevent executable uploads; store outside web root where possible (or with safe naming); scan existing upload flows.
10. **Logging**: logs/activity_log must never contain OTPs, tokens, passwords, full secrets, or sensitive patient data.
11. **Headers/CORS**: check CORS config for API consumers; sensible headers in production; `TRUSTED_PROXIES` correct on Render.
12. **Dependencies**: flag outdated/vulnerable packages (composer audit / `npm audit`) in reports.

## Severity classification

Critical / High / Medium / Low / Informational. For every finding provide: location, vulnerability, impact, reproduction concept, recommended fix.

## Golden rules

- Never weaken security to make a feature work; if the architecture is insecure, report it BEFORE making broad changes.
- Do not exploit production systems destructively — audits are read-only unless explicitly authorized.
- Never add OTP/token/password values to logs, responses, or exports.
