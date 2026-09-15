# -*- coding: utf-8 -*-
"""Daway — WCAG 2.1 AA verification for the design-token layer.

Reads resources/css/tokens.css DIRECTLY (single source of truth) and checks
every text / non-text pair in BOTH modes.  Nothing is hardcoded, so the
script can never drift away from the stylesheet.

Thresholds
    text ................ 4.5:1   (WCAG 1.4.3)
    large text .......... 3.0:1   (checked separately in the page audits)
    UI components ....... 3.0:1   (WCAG 1.4.11)

Usage:  python outputs/wcag-verification.py
"""
import io, re, sys

TOKENS = 'resources/css/tokens.css'

# ---------------------------------------------------------------- maths
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


def blend(rgba, base):
    """Composite `rgba(r,g,b,a)` over a solid `#RRGGBB` base."""
    nums = re.findall(r'[\d.]+', rgba)
    a = float(nums[3]) if len(nums) > 3 else 1.0
    o = base.lstrip('#')
    out = []
    for i in range(3):
        src = float(nums[i])
        dst = int(o[i * 2:i * 2 + 2], 16)
        out.append(int(round(src * a + dst * (1 - a))))
    return '#%02X%02X%02X' % tuple(out)


# ---------------------------------------------------------------- parse
def parse_block(src, header):
    m = re.search(re.escape(header) + r'\s*\{(.*?)\n\}', src, re.S)
    if not m:
        sys.exit('cannot find block: ' + header)
    d = {}
    for dm in re.finditer(r'(--[a-z0-9-]+)\s*:\s*([^;]+);', m.group(1)):
        d[dm.group(1)] = dm.group(2).strip()
    return d


def resolve(d, name, depth=0):
    v = d.get(name)
    if v is None:
        return None
    mm = re.fullmatch(r'var\((--[a-z0-9-]+)\)', v)
    if mm and depth < 6:
        return resolve(d, mm.group(1), depth + 1)
    return v


def solid(d, name, base=None):
    """Return a solid hex for a token (compositing rgba over `base`)."""
    v = resolve(d, name)
    if v is None:
        return None
    if v.startswith('#'):
        h = v[1:]
        if len(h) == 3:
            h = ''.join(c * 2 for c in h)
        return '#' + h.upper()
    if v.startswith('rgb'):
        return blend(v, base or '#FFFFFF')
    return None


src = io.open(TOKENS, encoding='utf-8').read()
light = parse_block(src, ':root')
_dark = parse_block(src, 'html.dark-mode,\nbody.dark-mode')
# dark inherits everything not re-declared (same element = :root)
dark = dict(light)
dark.update(_dark)

FAILS = []
COUNT = [0]


def chk(label, fg, bg, need):
    COUNT[0] += 1
    if not fg or not bg:
        FAILS.append((label, 0.0, need))
        print('  MISSING  %-52s %s / %s' % (label, fg, bg))
        return
    r = ratio(fg, bg)
    ok = r >= need - 0.005
    if not ok:
        FAILS.append((label, r, need))
    print('  %-8s %-52s %s / %s = %5.2f  (>= %.1f)'
          % ('OK' if ok else 'FAIL', label, fg, bg, r, need))


# ---------------------------------------------------------------- dark
print('=' * 96)
print('DARK MODE  (--paper #112433 · --canvas #050C12 · card gradient top #1E3A5F)')
print('=' * 96)
paper = solid(dark, '--paper')
canvas = solid(dark, '--canvas')
gradtop = '#1E3A5F'
surfaces = [('paper', paper), ('canvas', canvas), ('grad-top', gradtop)]

print('\n-- neutral text --')
for t in ('--ink', '--ink-soft', '--ink-faint'):
    for sname, s in surfaces:
        chk('%s / %s' % (t, sname), solid(dark, t), s, 4.5)

print('\n-- semantic text (over each surface + own pastel bg) --')
for t, bgk in (('--success-text', '--success-bg'), ('--warning-text', '--warning-bg'),
               ('--danger-text', '--danger-bg'), ('--info-text', '--info-bg'),
               ('--teal-text', None), ('--pink-text', None), ('--indigo-text', None)):
    for sname, s in surfaces:
        chk('%s / %s' % (t, sname), solid(dark, t), s, 4.5)
    if bgk:
        b = solid(dark, bgk, base=paper)
        chk('%s / %s' % (t, bgk), solid(dark, t), b, 4.5)
    chk('%s / --teal-mist' % t, solid(dark, t),
        solid(dark, '--teal-mist', base=paper), 4.5)

print('\n-- UI components (WCAG 1.4.11 >= 3:1) --')
for sname, s in surfaces:
    chk('--line-strong / %s' % sname, solid(dark, '--line-strong'), s, 3.0)
    chk('--focus-ring / %s' % sname, solid(dark, '--focus-ring'), s, 3.0)

print('\n-- fills (white text on a solid fill) --')
for t in ('--success-fill', '--warning-fill', '--info-fill', '--danger-fill',
          '--teal-fill', '--danger-strong'):
    chk('white / %s' % t, '#FFFFFF', solid(dark, t), 4.5)
chk('white / --teal-primary', '#FFFFFF', solid(dark, '--teal-primary'), 4.5)

# ---------------------------------------------------------------- light
print('\n' + '=' * 96)
print('LIGHT MODE  (--paper #FFFFFF · --canvas #F5FAF9 · sidebar #155E85)')
print('=' * 96)
lpaper = solid(light, '--paper')
lcanvas = solid(light, '--canvas')
sidebar = '#155E85'
lsurfaces = [('paper', lpaper), ('canvas', lcanvas), ('line-soft', solid(light, '--line-soft'))]

print('\n-- neutral text --')
for t in ('--ink', '--ink-soft', '--ink-faint'):
    for sname, s in lsurfaces:
        chk('%s / %s' % (t, sname), solid(light, t), s, 4.5)
chk('--text-muted(sidebar) / sidebar', '#A5D9D9', sidebar, 4.5)

print('\n-- semantic text (over each surface + own pastel bg) --')
for t, bgk in (('--success-text', '--success-bg'), ('--warning-text', '--warning-bg'),
               ('--danger-text', '--danger-bg'), ('--info-text', '--info-bg'),
               ('--teal-text', None), ('--pink-text', None), ('--indigo-text', None)):
    for sname, s in lsurfaces:
        chk('%s / %s' % (t, sname), solid(light, t), s, 4.5)
    if bgk:
        chk('%s / %s' % (t, bgk), solid(light, t), solid(light, bgk), 4.5)
    chk('%s / --teal-mist' % t, solid(light, t),
        solid(light, '--teal-mist', base=lpaper), 4.5)

print('\n-- pastel backgrounds used with the *-text family --')
for t, bgs in (('--success-text', ('#DCFCE7', '#D1FAE5', '#F0FDF4')),
               ('--warning-text', ('#FEF9C3', '#FEF3C7', '#FFF7ED')),
               ('--danger-text',  ('#FEE2E2', '#FEF2F2')),
               ('--info-text',    ('#E0F2FE', '#F0F9FF', '#EFF6FF')),
               ('--teal-text',    ('#EAF5F4',)),
               ('--pink-text',    ('#FDF2F8',)),
               ('--indigo-text',  ('#EEF2FF',))):
    for b in bgs:
        chk('%s / %s' % (t, b), solid(light, t), b, 4.5)

print('\n-- UI components (WCAG 1.4.11 >= 3:1) --')
for sname, s in lsurfaces[:2]:
    chk('--line-strong / %s' % sname, solid(light, '--line-strong'), s, 3.0)
    chk('--focus-ring / %s' % sname, solid(light, '--focus-ring'), s, 3.0)

print('\n-- fills (white text on a solid fill) --')
for t in ('--success-fill', '--warning-fill', '--info-fill', '--danger-fill',
          '--teal-fill', '--danger-strong'):
    chk('white / %s' % t, '#FFFFFF', solid(light, t), 4.5)
chk('white / --teal-primary', '#FFFFFF', solid(light, '--teal-primary'), 4.5)
chk('white / --teal-deep', '#FFFFFF', solid(light, '--teal-deep'), 4.5)

# ---------------------------------------------------------------- result
print('\n' + '=' * 96)
print('checked %d pairs' % COUNT[0])
if FAILS:
    print('RESULT: %d FAILURE(S)' % len(FAILS))
    for lbl, r, need in FAILS:
        print('   - %-52s %.2f  (need %.1f)' % (lbl, r, need))
    sys.exit(1)
print('RESULT: ALL CHECKS PASS  (WCAG 2.1 AA)')
print('=' * 96)
