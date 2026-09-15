# -*- coding: utf-8 -*-
"""Light-mode contrast audit (v3).
   - resolves var() from EVERY file's :root (not just tokens.css)
   - handles gradients (worst stop wins)
   - applies the WCAG large-text threshold (3:1) when font-size/weight qualify
"""
import io, re, os, glob, collections

# ---------------------------------------------------------------- tokens
TOK = {}
for path in glob.glob('resources/css/**/*.css', recursive=True) + glob.glob('public/css/**/*.css', recursive=True):
    if path.endswith('.bak-tokens'):
        continue
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)
    # only the :root (light) values, and NOT the dark ones
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
            h = ''.join(c*2 for c in h)
        return ('#' + h.upper()) if len(h) == 6 else None
    mm = re.fullmatch(r'var\((--[a-z0-9-]+)', v)
    if mm and TOK.get(mm.group(1)):
        return hexof(TOK[mm.group(1)])
    return None

def colours_in(value, over=None):
    """Return solid hex colours found in a value. rgba() is composited over `over`."""
    out = []
    for m in re.finditer(r'(#[0-9A-Fa-f]{3,6}\b|var\(--[a-z0-9-]+\)|rgba?\([^)]*\))', value):
        tok = m.group(1)
        if tok.startswith('rgba') or tok.startswith('rgb('):
            nums = re.findall(r'[\d.]+', tok)
            if len(nums) >= 3 and over:
                a = float(nums[3]) if len(nums) > 3 else 1.0
                o = over.lstrip('#')
                ch = []
                for i in range(3):
                    src = float(nums[i])
                    dst = int(o[i*2:i*2+2], 16)
                    ch.append(int(round(src*a + dst*(1-a))))
                out.append('#%02X%02X%02X' % tuple(ch))
            continue
        h = hexof(tok)
        if h:
            out.append(h)
    return out

# ---------------------------------------------------------------- maths
def srgb(c):
    c = c/255.0
    return c/12.92 if c <= 0.03928 else ((c+0.055)/1.055)**2.4

def lum(h):
    h = h.lstrip('#')
    return (0.2126*srgb(int(h[0:2],16)) + 0.7152*srgb(int(h[2:4],16))
            + 0.0722*srgb(int(h[4:6],16)))

def ratio(a, b):
    L1, L2 = lum(a), lum(b)
    if L1 < L2: L1, L2 = L2, L1
    return (L1+0.05)/(L2+0.05)

def is_large(body):
    m = re.search(r'(?:^|;)\s*font-size\s*:\s*([\d.]+)(px|rem|em)', body)
    if not m:
        return False
    v = float(m.group(1))
    u = m.group(2)
    if u == 'rem' or u == 'em':
        v *= 16
    w = re.search(r'(?:^|;)\s*font-weight\s*:\s*(\d{3}|bold)', body)
    bold = bool(w) and (w.group(1) == 'bold' or int(w.group(1)) >= 700)
    if v >= 24:
        return True
    if v >= 18.66 and bold:
        return True
    return False

# ---------------------------------------------------------------- parse
RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
FILE_SURFACE = {'sidebar.css': '#155E85'}
DEFAULT_SURFACE = '#FFFFFF'
DEAD = {'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'}

# Selectors that sit on a DELIBERATELY dark surface even in light mode
# (dark page headers / coloured avatars / active pills). Resolving these
# statically needs the DOM, so they are pinned here after visual inspection.
SURFACE_OVERRIDE = {
    '.header-title-area h2': '#155E85',
    '.header-title-area p': '#155E85',
    '.header-icon': '#155E85',
    '.card-header-modern': '#155E85',
    '.user-avatar': '#1C72A6',
    '.avatar-box': '#1C72A6',
    '.auth-hero': '#155E85',
    '.chart-bar-pro': '#0F172A',
}

findings = []
stats = collections.Counter()

for path in sorted(glob.glob('resources/css/**/*.css', recursive=True) + glob.glob('public/css/**/*.css', recursive=True)):
    if path.endswith('.bak-tokens'):
        continue
    base = os.path.basename(path)
    if base in DEAD:
        continue
    src = re.sub(r'/\*.*?\*/', '', io.open(path, encoding='utf-8').read(), flags=re.S)

    light = []
    for m in RULE.finditer(src):
        sel = m.group(1).strip()
        if 'dark-mode' in sel or sel.startswith('@') or '%' in sel:
            continue
        light.append((sel, m.group(2)))

    bg = {}
    for sel, body in light:
        # pseudo-elements paint themselves, not the element's surface — skip them
        if re.search(r'::?(?:before|after|placeholder|marker|selection)\b', sel):
            continue
        # gradient-text (background-clip:text) is NOT a surface
        if re.search(r'background-clip\s*:\s*text', body):
            continue
        mm = re.search(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)', body)
        if mm:
            cs = colours_in(mm.group(1), over=FILE_SURFACE.get(base, DEFAULT_SURFACE))
            if cs:
                for s in sel.split(','):
                    s = s.strip()
                    if s:
                        bg[s] = cs
        if re.search(r'(?:^|;)\s*background(?:-color)?\s*:\s*[^;]*--sidebar-bg', body):
            for s in sel.split(','):
                bg[s.strip()] = [FILE_SURFACE.get(base, DEFAULT_SURFACE)]

    def toks(sel):
        sel = re.sub(r'::?(?:before|after|placeholder|marker|selection)\b', '', sel)
        return set(re.findall(r'[.#][A-Za-z0-9_-]+', sel))

    def surfaces(sel):
        s = sel.split(',')[0].strip()
        sbase = re.sub(r'::?(?:before|after|placeholder|marker|selection)\b', '', s).strip()
        if s in SURFACE_OVERRIDE:
            return [SURFACE_OVERRIDE[s]], 'pin'
        if sbase in SURFACE_OVERRIDE:
            return [SURFACE_OVERRIDE[sbase]], 'pin'
        if s in bg:
            return bg[s], 'own'
        st = toks(s)
        best, score = None, 0
        for k, v in bg.items():
            kt = toks(k.split(':')[0])
            if kt and kt <= st and len(kt) > score:
                best, score = v, len(kt)
        if best:
            return best, 'ancestor'
        return [FILE_SURFACE.get(base, DEFAULT_SURFACE)], 'default'

    for sel, body in light:
        large = is_large(body)
        need = 3.0 if large else 4.5
        for cm in re.finditer(r'(?:^|;)\s*color\s*:\s*([^;]+)', body):
            raw = cm.group(1).strip()
            if any(k in raw for k in ('currentColor', 'inherit', 'transparent')):
                continue
            fg = hexof(raw.split()[0])
            if not fg:
                continue
            bgs, how = surfaces(sel)
            worst = min(ratio(fg, b) for b in bgs)
            stats['checked'] += 1
            if worst < need:
                stats['fail'] += 1
                findings.append((worst, need, base, sel[:60], fg,
                                 '/'.join(bgs[:2]), how, 'L' if large else '-'))
            elif worst < need + 1.0:
                stats['marginal'] += 1

findings.sort()
print("=" * 108)
print("LIGHT-MODE TEXT CONTRAST — checked %d · FAILING %d · marginal %d"
      % (stats['checked'], stats['fail'], stats['marginal']))
print("=" * 108)
print("%-6s %-4s %-22s %-40s %-10s %-16s %s" % ("RATIO", "NEED", "FILE", "SELECTOR", "FG", "BG", "SRC"))
print("-" * 108)
for r, need, f, sel, fg, b, how, lg in findings:
    print("%6.2f %-4.1f %-22s %-40s %-10s %-16s %s%s" % (r, need, f, sel, fg, b, how, lg))

print("\n=== per file (live files only) ===")
c = collections.Counter(f for _, _, f, *_ in findings if f not in DEAD)
for f, n in c.most_common():
    print("  %-28s %d" % (f, n))
print("  (dead files: %d findings, ignored)"
      % sum(1 for _, _, f, *_ in findings if f in DEAD))

print("\n=== recurring failing colours ===")
c2 = collections.Counter(fg for _, _, _, _, fg, *_ in findings)
for fg, n in c2.most_common(24):
    print("  %-10s x%d" % (fg, n))
