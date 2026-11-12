# Authentication migration (draft)

This work adds new endpoints while the existing mobile app continues to use the legacy OTP and registration endpoints. No client should be cut over until its supported version and an independent delivery mechanism are verified. In particular, the current mobile client reads OTPs returned by the server; that response exposes the code to anyone who can request it and must not be treated as a secure production authentication channel. Disabling or hiding it before a replacement delivery path and client cutover can prevent login. The old pharmacy registration and admin-assisted recovery paths also remain present; Google linking is mandatory only on the new registration endpoint.

## New API endpoints

All paths are prefixed with `/api`. Responses for patient and Google sign-in include a Sanctum token; password reset and pharmacy registration do not sign the user in.

| Method and path | Purpose |
| --- | --- |
| POST `/auth/patient/register` | Name, email, phone, age, password, password_confirmation, terms_accepted. Creates a patient with a password. |
| POST `/auth/patient/login` | `identifier` (email or phone), `password`. |
| GET `/auth/patient/me` | Bearer token required; returns active patient identity only. |
| POST `/auth/password/email` | Generic response; accepts `email` for eligible patient/admin/rider accounts. |
| POST `/auth/password/reset` | `email`, `token`, `password`, `password_confirmation`. Successful reset revokes sessions. |
| POST `/auth/google/challenge` | `purpose`: `login`, `register_patient`, `register_pharmacy`, or `recover_pharmacy`. The nonce is short-lived and single-use. |
| POST `/auth/google/login` | `id_token`, `nonce`, and `role` (`patient` or `pharmacy`). Linked subject required. |
| POST `/auth/google/register-patient` | Verified Google identity, nonce, name, email, phone, age, terms_accepted. Email must match verified Google email. |
| POST `/auth/google/register-pharmacy` | Verified Google identity, nonce, pharmacy_name, phone, region, password, password_confirmation. Creates a pending application. |
| POST `/auth/google/recover-pharmacy` | Previously linked Google identity, nonce, password, password_confirmation. Active approved pharmacies only; revokes sessions. |
| POST `/pharmacy/google/challenge` | Active approved pharmacy bearer token required; issue a challenge tied to that token. |
| POST `/pharmacy/google/link` | Same bearer token, nonce, Google ID token and `current_password` required. Six attempts per minute. |

Never put an ID token, nonce, reset link, password, or session token into logs or support tickets. Google identities are associated by verified `sub`, never by email alone. Existing accounts require an explicit authenticated link.

## Configuration before a test deployment

1. Create or select a Google Cloud project, configure its consent screen and test users as required, and create a **Web application** OAuth client. Add the exact JavaScript origins of the test frontend and `https://daway-frontend.vercel.app` when appropriate. The current Google Identity Services button uses a JavaScript callback; no server OAuth redirect endpoint is used by this flow. A preview has its own distinct origin. Confirm the consent screen's publishing state and any authorized-domain requirements with Google before production.
2. Set backend `GOOGLE_CLIENT_IDS` to the authorized public Web client ID(s), comma-separated if there is more than one. Set frontend `VITE_GOOGLE_CLIENT_ID` to the corresponding public client ID, then rebuild the frontend. Do not put a client secret in Vite variables or source control. Configure different environments separately.
3. Configure a real email transport and verified sender in the backend environment. Set `FRONTEND_PASSWORD_RESET_URL=https://daway-frontend.vercel.app/reset-password` for production, or the exact HTTPS test frontend URL for a test environment. Production `log` and `array` transports do not issue reset links. Ensure any failover transport also cannot write reset URLs to logs. Reset tokens are carried in the URL fragment and are cleared from the browser address bar after the reset page loads.
4. Use dedicated test accounts and isolated test data. Verify password and Google registration/login, actual admin approval, account linking, recovery, session revocation, rate limits, closed accounts and all role-specific dashboards. Test email delivery and one-use/expired links. Review MySQL concurrency behavior separately; SQLite tests do not establish production locking behavior.
5. Do not deploy until external configuration, live provider verification, security review and the supported mobile cutover are agreed. Do not delete or migrate production business data as part of these tests. Retain legacy endpoints until the mobile cutover is approved.
