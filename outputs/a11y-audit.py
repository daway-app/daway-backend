# -*- coding: utf-8 -*-
"""Daway — full site-wide accessibility audit (both modes).

Checks, for LIGHT and DARK:
  1. text contrast          WCAG 1.4.3   (4.5:1, or 3:1 for large text)
  2. white-on-fill text     WCAG 1.4.3   (4.5:1)
  3. interactive borders    WCAG 1.4.11  (3:1)

Dark-mode resolution
--------------------
For every (file, selector, property) the effective dark value is the most
specific `body.dark-mode` / `html.dark-mode` rule; where no dark rule
overrides the property the light rule is used with DARK token values
(custom properties cascade because `html.dark-mode` IS `:root`).

Surfaces
--------
* light: `--paper` #FFFFFF, sidebar #155E85, confirm-modal cards #FFFFFF
* dark : card-gradient top #1E3A5F (the hardest case), sidebar #061019
* gradient-text (`background-clip: text`) is not a surface
* a border equal to its own fill is not "identifying" (skipped)

Usage:  python outputs/a11y-audit.py
"""
import io, re, os, glob, collections, sys

CSS = sorted(glob.glob('resources/css/**/*.css', recursive=True)
             + glob.glob('public/css/**/*.css', recursive=True))
DEAD = {'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'}

# ------------------------------------------------------------------ tokens
def collect_tokens():
    light, dark = {}, {}
    for path in CSS:
        if '.bak' in path or os.path.basename(path) in DEAD:
            continue
        src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
        for m in re.finditer(r':root\s*\{([^}]*)\}', src):
            for d in re.finditer(r'(--[a-z0-9-]+)\s*:\s*([^;]+);', m.group(1)):
                light.setdefault(d.group(1), d.group(2).strip())
        for m in re.finditer(r'(?:html|body)\.dark-mode[^{]*\{([^}]*)\}', src):
            for d in re.finditer(r'(--[a-z0-9-]+)\s*:\s*([^;]+);', m.group(1)):
                dark.setdefault(d.group(1), d.group(2).strip())
    darkfull = dict(light)
    darkfull.update(dark)

    def resolve(d, name, depth=0):
        v = d.get(name)
        if v is None:
            return None
        mm = re.fullmatch(r'var\((--[a-z0-9-]+)\)', v)
        if mm and depth < 8:
            return resolve(d, mm.group(1), depth + 1)
        return v

    return light, darkfull, resolve


LIGHT, DARK, RESOLVE = collect_tokens()

# ------------------------------------------------------------------ colour
def srgb(c):
    c = c / 255.0
    return c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4


def lum(h):
    h = h.lstrip('#')
    return (0.2126 * srgb(int(h[0:2], 16)) + 0.7152 * srgb(int(h[2:4], 16))
            + 0.0722 * srgb(int(h[4:6], 16)))


def ratio(a, b):
    L1, L2 = lum(a), lum(b)
    if L1 < L2:
        L1, L2 = L2, L1
    return (L1 + 0.05) / (L2 + 0.05)


def blend(c, base):
    n = re.findall(r'[\d.]+', c)
    a = float(n[3]) if len(n) > 3 else 1.0
    o = base.lstrip('#')
    return '#%02X%02X%02X' % tuple(
        int(round(float(n[i]) * a + int(o[i * 2:i * 2 + 2], 16) * (1 - a)))
        for i in range(3))


def hexes(value, d, over):
    out = []
    for m in re.finditer(r'(#[0-9A-Fa-f]{3,6}\b|var\(--[a-z0-9-]+\)|rgba?\([^)]*\))', value):
        t = m.group(1)
        if t.startswith('rgb'):
            out.append(blend(t, over))
            continue
        mm = re.fullmatch(r'var\((--[a-z0-9-]+)\)', t)
        v = RESOLVE(d, mm.group(1)) if mm else t
        if not v:
            continue
        v = v.strip()
        if v.startswith('rgb'):
            out.append(blend(v, over))
        elif v.startswith('#'):
            h = v[1:]
            if len(h) == 3:
                h = ''.join(c * 2 for c in h)
            if len(h) == 6:
                out.append('#' + h.upper())
    return out


def is_large(body):
    m = re.search(r'(?:^|;)\s*font-size\s*:\s*([\d.]+)(px|rem|em)', body)
    if not m:
        return False
    v = float(m.group(1))
    if m.group(2) in ('rem', 'em'):
        v *= 16
    w = re.search(r'(?:^|;)\s*font-weight\s*:\s*(\d{3}|bold)', body)
    bold = bool(w) and (w.group(1) == 'bold' or int(w.group(1)) >= 700)
    return v >= 24 or (v >= 18.66 and bold)


# ------------------------------------------------------------------ parse
RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)
NEAR_WHITE = {'#FFFFFF', '#FEFEFE', '#F8FAFC', '#F9FAFB', '#FFF', '#FAFAFA'}
BGDECL = re.compile(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)')
COLORDECL = re.compile(r'(?:^|;)\s*color\s*:\s*([^;]+)')
BORDER = re.compile(r'(?:^|;)\s*border(?:-color|-top|-right|-bottom|-left'
                    r'|-inline-start|-inline-end)?\s*:\s*([^;]+)')


def norm(sel):
    return re.sub(r'^(?:html|body)\.dark-mode\s+', '', sel.strip())


# Light rules and dark overrides are collected SEPARATELY.  A dark override
# must only be superseded by a LATER dark override — never by a later light
# rule.  (`body.dark-mode .card-head` has specificity 0,1,1 and therefore beats
# a `.card-head` declared further down the file.)
light_slots, dark_slots, light_bgs, dark_bgs = {}, {}, {}, {}
PSEUDO = re.compile(r'::?(?:before|after|placeholder|marker|selection)\b')
for path in CSS:
    if '.bak' in path or os.path.basename(path) in DEAD:
        continue
    base = os.path.basename(path)
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
    for m in RULE.finditer(src):
        sel, body = m.group(1).strip(), m.group(2)
        if sel.startswith('@') or '%' in sel:
            continue
        gradient_text = 'background-clip:text' in body.replace(' ', '')
        is_dark = 'dark-mode' in sel
        slots = dark_slots if is_dark else light_slots
        bgs = dark_bgs if is_dark else light_bgs
        for part in sel.split(','):
            part = part.strip()
            if not part:
                continue
            key = norm(part)
            for pm, prop in ((BGDECL, 'background'), (COLORDECL, 'color'), (BORDER, 'border')):
                mm = pm.search(body)
                if not mm:
                    continue
                slots[(base, key, prop)] = (mm.group(1).strip(), body)
            # pseudo-elements paint themselves: keep their own slot so a
            # `::before` background never becomes its host's surface.
            # `background-clip:text` is a clipped gradient, not a surface.
            mm = BGDECL.search(body)
            if mm and not gradient_text:
                bgs[(base, key)] = mm.group(1).strip()

# effective dark = the light cascade with the dark overrides layered on top
eff_light = dict(light_slots)
eff_dark = dict(light_slots)
eff_dark.update(dark_slots)
bg_light = dict(light_bgs)
bg_dark = dict(light_bgs)
bg_dark.update(dark_bgs)


# Selectors that sit on a DELIBERATELY dark/coloured surface in light mode.
# Resolving these needs the DOM, so they are pinned after visual inspection.
SURFACE_OVERRIDE = {
    '.auth-hero': '#155E85',
    '.card-header-modern': '#155E85',
    '.header-title-area h2': '#155E85',
    '.header-title-area p': '#155E85',
    '.header-icon': '#155E85',
    '.user-avatar': '#1C72A6',
    '.avatar-box': '#1C72A6',
    '.avatar': '#1C72A6',
    '.btn-p': '#1C72A6',
    '.btn-primary': '#1C72A6',
    '.btn-add-pharmacy': '#1C72A6',
    '.btn-view-all-logs': '#1C72A6',
    '.ph-banner h2': '#155E85',
    '.ph-banner p': '#155E85',
    '.ph-pill.active': '#1C72A6',
    '.loader-timeout-btn': '#1C72A6',
    '.hero-desc': '#155E85',
    '.chart-bar-pro': '#0F172A',
}


def surface(base, sel, mode):
    if mode == 'dark':
        if base == 'sidebar.css':
            return '#061019'
        return '#1E3A5F'
    if 'confirm-modal' in sel:
        return '#FFFFFF'
    if base == 'sidebar.css':
        return '#155E85'
    return '#FFFFFF'


def tokset(sel):
    sel = re.sub(r'::?(?:before|after|placeholder|marker|selection)\b', '', sel)
    return set(re.findall(r'[.#][A-Za-z0-9_-]+', sel))


def resolve_surface(base, sel, mode, d):
    sbase = PSEUDO.sub('', sel).strip()
    if mode == 'light':
        if sbase in SURFACE_OVERRIDE:
            return [SURFACE_OVERRIDE[sbase]]
        if sel in SURFACE_OVERRIDE:
            return [SURFACE_OVERRIDE[sel]]
    bgs = bg_light if mode == 'light' else bg_dark
    own = bgs.get((base, sel))
    if own is None:
        own = bgs.get((base, sbase))
    if own is not None:
        hs = hexes(own, d, surface(base, sel, mode))
        if hs:
            return hs
    st = tokset(sel)
    best, score = None, 0
    for (b2, k), v in bgs.items():
        if b2 != base or PSEUDO.search(k):
            continue
        kt = tokset(k)
        if kt and kt <= st and len(kt) > score:
            hs = hexes(v, d, surface(base, sel, mode))
            if hs:
                best, score = hs, len(kt)
    if best:
        return best
    return [surface(base, sel, mode)]


FAILS = []
COUNTS = collections.Counter()

for mode in ('light', 'dark'):
    d = LIGHT if mode == 'light' else DARK
    eff = eff_light if mode == 'light' else eff_dark
    for (base, sel, prop), (val, body) in eff.items():
        if prop == 'color':
            if any(k in val for k in ('currentColor', 'inherit', 'transparent')):
                continue
            fg = hexes(val, d, '#FFFFFF')
            if not fg:
                continue
            need = 3.0 if is_large(body) else 4.5
            for bg in resolve_surface(base, sel, mode, d):
                COUNTS['text'] += 1
                r = ratio(fg[0], bg)
                if r < need - 0.005:
                    FAILS.append((mode, r, need, base, sel, fg[0], bg, 'text'))
        elif prop == 'background':
            if 'gradient(' in val:
                continue
            cm = COLORDECL.search(body)
            if not cm:
                continue
            fg = hexes(cm.group(1).strip().split()[0], d, '#FFFFFF')
            if not fg or fg[0] not in NEAR_WHITE:
                continue
            bgs = hexes(val, d, surface(base, sel, mode))
            if not bgs:
                continue
            COUNTS['fill'] += 1
            r = min(ratio(fg[0], b) for b in bgs)
            if r < 4.5:
                FAILS.append((mode, r, 4.5, base, sel, fg[0], bgs[0], 'fill'))
        elif prop == 'border':
            if not INTERACTIVE.search(sel):
                continue
            bgv = eff.get((base, sel, 'background'))
            if bgv and val.strip() == bgv[0].strip():
                continue
            surf = surface(base, sel, mode)
            for c in hexes(val, d, surf):
                if c in ('#FFFFFF', '#000000'):
                    continue
                COUNTS['border'] += 1
                r = ratio(c, surf)
                if r < 3.0 - 0.005:
                    FAILS.append((mode, r, 3.0, base, sel, c, surf, 'border'))

print('=' * 104)
print('DAWAY A11Y AUDIT — checked %d pairs (text %d · fill %d · border %d)'
      % (sum(COUNTS.values()), COUNTS['text'], COUNTS['fill'], COUNTS['border']))
print('=' * 104)
if FAILS:
    print('FAILING %d\n' % len(FAILS))
    print('%-6s %-5s %-5s %-22s %-44s %-10s %-10s %s'
          % ('RATIO', 'NEED', 'MODE', 'FILE', 'SELECTOR', 'FG', 'BG', 'KIND'))
    print('-' * 104)
    for mode, r, need, base, sel, fg, bg, kind in sorted(FAILS):
        print('%6.2f %-5.1f %-5s %-22s %-44s %-10s %-10s %s'
              % (r, need, mode, base, sel[:44], fg, bg, kind))
    print('\nper file:')
    for f, n in collections.Counter(f[3] for f in FAILS).most_common():
        print('  %-30s %d' % (f, n))
    sys.exit(1)
print('RESULT: ALL CHECKS PASS  (WCAG 2.1 AA — 1.4.3 + 1.4.11, light & dark)')
print('=' * 104)
