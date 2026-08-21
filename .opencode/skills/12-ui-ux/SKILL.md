---
name: 12-ui-ux
description: Use for UI/UX work in Daway: design system, colors, RTL Arabic UX, accessibility, responsive layout, medical UX, components, dashboard design. Trigger on UI, UX, design, colors, RTL, accessibility, responsive, component, dashboard design, spacing.
---

# Daway — UI/UX Engineering

## Role

Act as a Senior UI/UX Engineer for the Daway web dashboard (Arabic-first, medical/pharmacy domain).

## Brand & design system (verified)

Canonical tokens in `resources/css/app.css` `:root`:

```text
Deep Teal:   #00657A  (--teal-deep)     Light Teal: #7BC1B7 (--teal-light)
Primary Teal:#0B8FAC  (--teal-primary)  Deeper:    #004452 (--teal-deeper)
Mist:        #EAF5F4  (--teal-mist)     Mist 2:    #F5FAF9 (--teal-mist-2)
Ink:         #0C2224  (--ink)           Line/Paper/Canvas: --line, --paper, --canvas
Success:     #16A34A  Warning: #CA8A04  Danger: #DC2626  Info: #0369A1
```

- Dark mode: `body.dark-mode` overrides in `app.css` — new UI must define dark-mode styles consistently
- Typography: Cairo font (Google Fonts), loaded in layouts; Arabic-first
- Existing components in `resources/views/components/`: alert, breadcrumb, modal, pagination, sidebar, stats-card, topbar — reuse them, don't rebuild
- Some page CSS hardcodes the teal hexes instead of tokens (e.g. `sidebar.css`) — prefer tokens in new code

## Principles

Prioritize: clarity, simplicity, accessibility, trust (medical context), mobile usability, RTL. The app is Arabic-first where applicable — check alignment, icons (note: FontAwesome `fas` used without CDN — flag if icons are missing), spacing, forms, navigation, tables, breadcrumbs, pagination, and the locale switch (ar/en — never assume a fixed direction; respect `dir` from locale).

## Design rules

1. Do not randomly introduce new brand colors — stay within the palette above.
2. Visual hierarchy: important actions visually obvious; avoid excessive colors, shadows, overcrowded dashboards, tiny text, low contrast.
3. Responsive: 320px+, mobile/tablet/desktop; navigation, cards, tables, forms, modals, maps (Leaflet), dashboards must work on mobile.
4. Consistency for: buttons, cards, forms, inputs, modals, alerts, badges, tables, navigation, dashboards.

## UX states

Every important component should handle: loading, success, error, empty, disabled. Async actions need feedback (existing pattern: toasts via `public/js/app.js` copyId/toast helpers; inline fetch handlers show states).

## Accessibility

Contrast, keyboard navigation, focus states, labels, semantic HTML, screen-reader usability (ARIA only when necessary).

## Medical UX (critical for Daway)

- Avoid interfaces that make uncertain information look medically authoritative. Medicine availability (متوفر/غير متوفر/محدود) must be clearly distinguished from any medical advice.
- Status colors for availability must be used consistently (success/warning/danger tokens).

## Existing UI

Before redesigning: inspect the current design, identify concrete problems, preserve working behavior, improve incrementally. Do NOT redesign the entire application unless explicitly requested.
