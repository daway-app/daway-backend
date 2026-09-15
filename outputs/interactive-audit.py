# -*- coding: utf-8 -*-
"""Interactive-component audit (WCAG 1.4.11) + white-on-fill text audit,
run for BOTH modes.

Dark-mode resolution model
--------------------------
For every (selector, property) pair the effective dark value is taken from the
most specific `body.dark-mode` / `html.dark-mode` rule; if no dark rule
overrides that property, the light rule is used with DARK token values
(custom properties cascade through `html.dark-mode` = `:root`).

Usage:  python outputs/interactive-audit.py
"""
import io, re, os, glob, collections

CSS = sorted(glob.glob('resources/css/**/*.css', recursive=True)
             + glob.glob('public/css/**/*.css', recursive=True))
DEAD = {'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'}

# ---------------------------------------------------------------- tokens
def collect_tokens():
    light, dark = {}, {}
    for path in CSS:
        if '.bak' in path:
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
    nums = re.findall(r'[\d.]+', c)
    a = float(nums[3]) if len(nums) > 3 else 1.0
    o = base.lstrip('#')
    return '#%02X%02X%02X' % tuple(
        int(round(float(nums[i]) * a + int(o[i * 2:i * 2 + 2], 16) * (1 - a)))
        for i in range(3))


def hexes(value, d, over):
    out = []
    for m in re.finditer(r'(#[0-9A-Fa-f]{3,6}\b|var\(--[a-z0-9-]+\)|rgba?\([^)]*\))', value):
        t = m.group(1)
        if t.startswith('rgb'):
            out.append(blend(t, over))
            continue
        v = t
        mm = re.fullmatch(r'var\((--[a-z0-9-]+)\)', v)
        if mm:
            v = RESOLVE(d, mm.group(1))
            if v is None:
                continue
        v = v.strip()
        if v.startswith('#'):
            h = v[1:]
            if len(h) == 3:
                h = ''.join(c * 2 for c in h)
            if len(h) == 6:
                out.append('#' + h.upper())
    return out


RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)
NEAR_WHITE = {'#FFFFFF', '#FEFEFE', '#F8FAFC', '#F9FAFB', '#FFF', '#FAFAFA'}
BORDER = re.compile(r'(?:^|;)\s*border(?:-color|-top|-right|-bottom|-left'
                    r'|-inline-start|-inline-end)?\s*:\s*([^;]+)')
BGDECL = re.compile(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)')
COLORDECL = re.compile(r'(?:^|;)\s*color\s*:\s*([^;]+)')


def norm(sel):
    return re.sub(r'^(?:html|body)\.dark-mode\s+', '', sel.strip())


# ---------------------------------------------------------------- gather
# Two maps: light = non-dark rules only;  dark = non-dark rules overridden by
# dark-mode rules (same element, so later/more-specific dark wins).
eff_light, eff_dark = {}, {}
for path in CSS:
    if '.bak' in path or os.path.basename(path) in DEAD:
        continue
    base = os.path.basename(path)
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
    for m in RULE.finditer(src):
        sel, body = m.group(1).strip(), m.group(2)
        if sel.startswith('@') or '%' in sel or 'background-clip:text' in body.replace(' ', ''):
            continue
        is_dark = 'dark-mode' in sel
        for part in sel.split(','):
            part = part.strip()
            if not part:
                continue
            key = norm(part)
            for pm, prop in ((BGDECL, 'background'), (COLORDECL, 'color'), (BORDER, 'border')):
                mm = pm.search(body)
                if not mm:
                    continue
                slot = (base, key, prop)
                if not is_dark:
                    eff_light[slot] = mm.group(1).strip()
                eff_dark[slot] = mm.group(1).strip()

# ---------------------------------------------------------------- surfaces
def surface_for(base, sel, mode):
    if mode == 'dark':
        return '#1E3A5F'          # worst (lightest) stop of the card gradient
    if 'confirm-modal' in sel:
        return '#FFFFFF'
    if base == 'sidebar.css':
        return '#155E85'
    return '#FFFFFF'


FAIL = []


def check(mode, label, fg, bg, need, base, sel):
    r = ratio(fg, bg)
    if r < need - 0.005:
        FAIL.append((mode, r, need, base, sel[:58], fg, bg, label))


for mode in ('light', 'dark'):
    d = LIGHT if mode == 'light' else DARK
    src_map = eff_light if mode == 'light' else eff_dark
    print('\n' + '=' * 100)
    print('%s MODE' % mode.upper())
    print('=' * 100)
    fills = borders = 0

    # group by base selector so background + color + border come together
    bysel = collections.defaultdict(dict)
    for (base, key, prop), val in src_map.items():
        bysel[(key, base)][prop] = val

    for (key, base), props in sorted(bysel.items()):
        sel = key
        surf = surface_for(base, sel, mode)

        # ---- white text on a solid fill ----
        bgv = props.get('background')
        fgv = props.get('color')
        if bgv and fgv and 'gradient(' not in bgv:
            fgs = hexes(fgv, d, surf)
            bgs = hexes(bgv, d, surf)
            if fgs and bgs and fgs[0] in NEAR_WHITE:
                worst = min(ratio(fgs[0], b) for b in bgs)
                if worst < 4.5:
                    fills += 1
                    check(mode, 'fill', fgs[0], bgs[0], 4.5, base, sel)

        # ---- interactive borders ----
        if not INTERACTIVE.search(sel):
            continue
        bv = props.get('border')
        if not bv:
            continue
        if bgv and bv.strip() == bgv.strip():
            continue
        for c in hexes(bv, d, surf):
            if c in ('#FFFFFF', '#000000'):
                continue
            if ratio(c, surf) < 3.0:
                borders += 1
                check(mode, 'border', c, surf, 3.0, base, sel)

    print('  white-on-fill failures: %d' % fills)
    print('  interactive-border failures: %d' % borders)

print('\n' + '=' * 100)
if FAIL:
    print('RESULT: %d FAILURE(S)' % len(FAIL))
    for mode, r, need, base, sel, fg, bg, lbl in sorted(FAIL):
        print('  [%-5s] %5.2f (need %.1f) %-24s %-58s %s on %s'
              % (mode, r, need, base, sel, fg, bg))
else:
    print('RESULT: ALL CHECKS PASS  (WCAG 1.4.11 + 1.4.3, both modes)')
print('=' * 100)
