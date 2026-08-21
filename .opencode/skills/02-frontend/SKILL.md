---
name: 02-frontend
description: Use for all frontend work in Daway: Blade templates, vanilla JavaScript, custom CSS, RTL Arabic UI, Vite assets, responsive design. Trigger on Blade, view, CSS, JS, frontend, UI, RTL, responsive.
---

# Daway — Frontend Engineering

## Role

Act as a Senior Frontend Engineer for the Daway web dashboard.

## Stack (verified in this repo)

- Blade server-rendered views only — NO SPA framework, NO Alpine/Vue/React
- Vanilla JS: mostly inline `<script>` blocks inside Blade files; `resources/js/pharmacy_dashboard.js` (Chart.js chart + theme toggle)
- Custom hand-written CSS in `resources/css/` (subfolders: `auth/`, `layout/`, `pages/`) + `resources/css/app.css` (tokens, dark mode)
- Tailwind is installed (`@tailwindcss/vite`) but NOT actually used — no CSS file imports it, no tailwind.config. Match the existing custom-CSS style; do not start using Tailwind without asking
- Vite builds ~22 CSS files + 2 JS entries (see `vite.config.js`); pages load assets via `@vite([...])` or legacy `asset()` calls
- RTL-first Arabic UI, Cairo font, `dir` switches with locale (`layouts/app.blade.php`)
- CDN libs: Leaflet 1.9.4 (pharmacies maps), Tom Select 2.2.2 (medicine create/edit), Chart.js (pharmacy dashboard), Google Fonts Cairo. NOTE: `fas fa-*` icons used in pharmacy dashboard but no FontAwesome CDN exists — flag before relying on them

## First rule

Before changing frontend code: inspect existing HTML/Blade/CSS/JS, identify shared components (`resources/views/components/`), search for classes/functions before modifying, check responsive behavior. Do not replace working components unnecessarily.

## HTML

Semantic HTML (`header`, `nav`, `main`, `section`, `article`, `footer`, `button`, `form`, `label`); avoid unnecessary `div` nesting; forms need accessible labels.

## CSS

Maintainable CSS: CSS variables, reusable classes, logical properties. Avoid excessive `!important`, duplicated styles, magic numbers. Dark mode exists via `body.dark-mode` overrides — respect it.

## RTL (critical for Daway)

The app is Arabic-first. Always consider `direction: rtl`. Prefer logical properties (`margin-inline`, `padding-inline`, `inset-inline`, `border-inline`) over hardcoded left/right. `config/app.php` locale is `ar` but `.env` sets `APP_LOCALE=en` — never assume a fixed direction; respect the locale switch and the `settings` table default.

## Design system

Canonical tokens live in `resources/css/app.css` `:root`:
- `--teal-deep: #00657A`, `--teal-primary: #0B8FAC`, `--teal-light: #7BC1B7`, `--teal-deeper: #004452`, `--teal-mist: #EAF5F4`
- Neutrals `--ink`, `--line`, `--paper`, `--canvas`; status `--success #16A34A`, `--warning #CA8A04`, `--danger #DC2626`, `--info #0369A1`
Do not introduce random colors. Some page CSS hardcodes the same hexes (e.g. `sidebar.css` `--sidebar-bg: #00657A`) — prefer the tokens.

## JavaScript & API calls

Use modern JS (`const`/`let`, `async/await`, reusable functions). All AJAX is vanilla `fetch()` with CSRF via `<meta name="csrf-token">`. API calls must handle: loading states, errors, empty responses, response structure validation, duplicate requests. Never expose secrets in frontend JS. Every async action needs feedback (loading/success/error/empty).

## Accessibility

Check keyboard navigation, labels, focus states, color contrast, alt text, button semantics. ARIA only when necessary.

## Performance

Avoid duplicated API requests, unnecessary DOM operations, huge images, blocking scripts, unnecessary animations.

## Responsive

Test mobile/tablet/desktop/large screens: navigation, cards, tables, forms, modals, maps, dashboards must remain usable on mobile.

## Known quirks

- Root `resources/views/auth.blade.php` references stale asset paths (built entry moved to `resources/css/auth/auth.css`) — appears unused; verify before touching
- Stale root-level CSS duplicates exist (`resources/css/dashboard.css`, `app_layout.css`, etc.) NOT in the Vite input — flag in audits, do not edit blindly
- `public/css/app.css` + `public/css/responsive.css` are legacy copies loaded via `asset()` — keep them working or migrate carefully
- Dashboard pre-fetches many endpoints on load (`/users`, `/medicines`, `/pharmacies`, `/patients`, `/inventory`, `/logs`, notifications) — avoid adding more, prefer lazy loading

## Before finishing

Verify: desktop, mobile, RTL, loading/error/empty states, API failures, accessibility.
