# -*- coding: utf-8 -*-
"""Light-mode pass 2 — white-on-fill text and interactive component borders.

Pass 1 (`_light_audit.py`) covers `color:` declarations only.  This pass adds:
  A. rules that set BOTH a solid background and a near-white `color`
     -> the pair must reach 4.5:1 (WCAG 1.4.3)
  B. `border` / `border-color` on interactive selectors (inputs, buttons,
     selects, textareas, links, focus rings)
     -> must reach 3:1 (WCAG 1.4.11)
"""
import io, re, os, glob, collections

TOK = {}
for path in glob.glob('resources/css/**/*.css', recursive=True) + glob.glob('public/css/**/*.css', recursive=True):
    if '.bak' in path:
        continue
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
    for m in re.finditer(r':root\s*\{([^}]*)\}', src):
        for d in re.finditer(r'(--[a-z0-9-]+)\s*:\s*([^;]+);', m.group(1)):
            TOK.setdefault(d.group(1), d.group(2).strip())
for _ in range(4):
    for k, v in list(TOK.items()):
        mm = re.fullmatch(r'var\((--[a-z0-9-]+)\)', v.strip())
        if mm and TOK.get(mm.group(1)):
            TOK[k] = TOK[mm.group(1)]


def hexof(v):
    v = v.strip().rstrip(')')
    if v.startswith('#'):
        h = v[1:]
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        return ('#' + h.upper()) if len(h) == 6 else None
    mm = re.fullmatch(r'var\((--[a-z0-9-]+)', v)
    if mm and TOK.get(mm.group(1)):
        return hexof(TOK[mm.group(1)])
    return None


def solid_hexes(value, over='#FFFFFF'):
    """Solid hexes in a value; rgba() composited over `over`."""
    out = []
    for m in re.finditer(r'(#[0-9A-Fa-f]{3,6}\b|var\(--[a-z0-9-]+\)|rgba?\([^)]*\))', value):
        t = m.group(1)
        if t.startswith('rgb'):
            nums = re.findall(r'[\d.]+', t)
            if len(nums) >= 3:
                a = float(nums[3]) if len(nums) > 3 else 1.0
                o = over.lstrip('#')
                ch = [int(round(float(nums[i]) * a + int(o[i * 2:i * 2 + 2], 16) * (1 - a)))
                      for i in range(3)]
                out.append('#%02X%02X%02X' % tuple(ch))
            continue
        h = hexof(t)
        if h:
            out.append(h)
    return out


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


RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
FILE_SURFACE = {'sidebar.css': '#155E85'}
INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)
NEAR_WHITE = {'#FFFFFF', '#FEFEFE', '#F8FAFC', '#F9FAFB', '#FFF', '#FAFAFA'}
DEAD = {'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'}

fills, borders = [], []

for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path:
        continue
    base = os.path.basename(path)
    if base in DEAD:
        continue
    file_surface = FILE_SURFACE.get(base, '#FFFFFF')
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
    for m in RULE.finditer(src):
        sel, body = m.group(1).strip(), m.group(2)
        if 'dark-mode' in sel or sel.startswith('@') or '%' in sel:
            continue
        if re.search(r'background-clip\s*:\s*text', body):
            continue

        # ---- A. white text on a solid fill ----
        bm = re.search(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)', body)
        cm = re.search(r'(?:^|;)\s*color\s*:\s*([^;]+)', body)
        if bm and cm and 'gradient(' not in bm.group(1):
            fg = hexof(cm.group(1).split()[0])
            if fg and fg in NEAR_WHITE:
                bgs = solid_hexes(bm.group(1), over=file_surface)
                if bgs:
                    worst = min(ratio(fg, b) for b in bgs)
                    if worst < 4.5:
                        fills.append((worst, base, sel[:58], fg, '/'.join(bgs[:2])))

        # ---- B. interactive borders ----
        if not INTERACTIVE.search(sel):
            continue
        # a modal card is a white surface even when it lives in sidebar.css
        surf = '#FFFFFF' if 'confirm-modal' in sel else file_surface
        bgval = bm.group(1).strip() if bm else None
        for bd in re.finditer(r'(?:^|;)\s*border(?:-color|-top|-right|-bottom|-left|-inline-start|-inline-end)?\s*:\s*([^;]+)', body):
            val = bd.group(1).strip()
            if bgval and val == bgval:
                continue                     # border matches its own fill
            cs = solid_hexes(val, over=surf)
            for c in cs:
                if c in ('#FFFFFF', '#000000'):
                    continue
                if ratio(c, surf) < 3.0:
                    borders.append((ratio(c, surf), base, sel[:58], c, val[:26]))

fills.sort()
borders.sort()

print('=' * 108)
print('LIGHT PASS 2 — white-on-fill FAILING %d · interactive-border FAILING %d'
      % (len(fills), len(borders)))
print('=' * 108)
if fills:
    print('%-6s %-24s %-58s %-10s %s' % ('RATIO', 'FILE', 'SELECTOR', 'FG', 'BG'))
    for r, f, s, fg, b in fills:
        print('%6.2f %-24s %-58s %-10s %s' % (r, f, s, fg, b))
if borders:
    print('\n%-6s %-24s %-58s %-10s %s' % ('RATIO', 'FILE', 'SELECTOR', 'COLOR', 'DECL'))
    for r, f, s, c, d in borders:
        print('%6.2f %-24s %-58s %-10s %s' % (r, f, s, c, d))

print('\n=== recurring ===')
for lbl, lst, idx in (('fills', fills, 3), ('borders', borders, 3)):
    c = collections.Counter(x[idx] for x in lst)
    print(' %s: %s' % (lbl, ', '.join('%s x%d' % kv for kv in c.most_common(12))))
