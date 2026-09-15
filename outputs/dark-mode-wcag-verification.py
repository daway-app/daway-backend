# -*- coding: utf-8 -*-
"""Final WCAG verification — after both passes."""

def srgb(c):
    c = c/255.0
    return c/12.92 if c <= 0.03928 else ((c+0.055)/1.055)**2.4

def lum(h):
    h = h.lstrip('#')
    r, g, b = int(h[0:2],16), int(h[2:4],16), int(h[4:6],16)
    return 0.2126*srgb(r) + 0.7152*srgb(g) + 0.0722*srgb(b)

def ratio(f, b):
    L1, L2 = lum(f), lum(b)
    if L1 < L2: L1, L2 = L2, L1
    return (L1+0.05)/(L2+0.05)

def over(a, bg):
    r, g, b, al = a
    bg = bg.lstrip('#')
    br, bg2, bb = int(bg[0:2],16), int(bg[2:4],16), int(bg[4:6],16)
    return '#%02X%02X%02X' % (round(al*r+(1-al)*br), round(al*g+(1-al)*bg2), round(al*b+(1-al)*bb))

FAILS = []
def chk(label, fg, bg, need):
    r = ratio(fg, bg)
    ok = r >= need
    if not ok: FAILS.append((label, r, need))
    print("  %-4s %-48s %s / %s = %5.2f  (>= %.1f)" % ("OK" if ok else "FAIL", label, fg, bg, r, need))

CARD_D, CANVAS_D = '#112433', '#050C12'
G1, G2 = '#1E3A5F', '#0A1A2E'

print("=" * 82)
print("DARK MODE")
print("=" * 82)
d = dict(
    ink='#EAF4F8', ink_soft='#93C5FD', ink_faint='#9DBACB',
    line='#3A6383', line_soft='#2A4761', line_strong='#5A8FB5', focus='#38BDF8',
    teal='#1C72A6', teal_deep='#155E85', teal_text='#7CC4EC',
    teal_mist=over((59,130,246,.12), CARD_D),
    green='#4ADE80', green_bg=over((74,222,128,.18), CARD_D),
    orange='#FBBF24', orange_bg=over((251,191,36,.18), CARD_D),
    red='#F87171', red_bg=over((248,113,113,.16), CARD_D),
    blue='#60A5FA', blue_bg=over((96,165,250,.18), CARD_D),
)
print("\n-- badges --")
chk('badge.ok',   d['green'], d['green_bg'], 4.5)
chk('badge.low',  d['orange'], d['orange_bg'], 4.5)
chk('badge.out',  d['red'], d['red_bg'], 4.5)
chk('badge.new',  d['teal_text'], d['teal_mist'], 4.5)
chk('badge.ans',  d['blue'], d['blue_bg'], 4.5)
chk('badge.closed', d['ink_faint'], d['line_soft'], 4.5)
chk('badge borders (worst: out)', over((248,113,113,.65), d['red_bg']), CARD_D, 3)

print("\n-- brand accent (text) --")
chk('teal-text / card',   d['teal_text'], CARD_D, 4.5)
chk('teal-text / canvas', d['teal_text'], CANVAS_D, 4.5)
chk('teal-text / mist',   d['teal_text'], d['teal_mist'], 4.5)
chk('teal-text / outline hover bg', d['teal_text'], over((124,196,236,.16), CARD_D), 4.5)
chk('white / teal FILL',  '#FFFFFF', d['teal'], 4.5)
chk('white / teal-deep FILL', '#FFFFFF', d['teal_deep'], 4.5)
chk('white / danger-strong FILL', '#FFFFFF', '#B91C1C', 4.5)

print("\n-- form controls (3:1) --")
chk('stepper border / canvas', d['line_strong'], CANVAS_D, 3)
chk('pill border / card', d['line_strong'], CARD_D, 3)
chk('search border / card', d['line_strong'], CARD_D, 3)
chk('focus ring / card', d['focus'], CARD_D, 3)
chk('focus ring / canvas', d['focus'], CANVAS_D, 3)
chk('danger btn border / card', over((248,113,113,.65), d['red_bg']), CARD_D, 3)

print("\n-- inputs --")
chk('input text / field bg', d['ink'], '#0C1B28', 4.5)
chk('placeholder / field bg', d['ink_faint'], '#0C1B28', 4.5)

print("\n-- stat card (gradient kept) --")
chk('stat border / gradient G1', d['line_strong'], G1, 3)
chk('stat border / gradient G2', d['line_strong'], G2, 3)
chk('stat text / G1', d['ink'], G1, 4.5)
chk('stat label / G1', d['ink_soft'], G1, 4.5)
chk('stat chip green', d['green'], d['green_bg'], 4.5)
chk('stat chip orange', d['orange'], d['orange_bg'], 4.5)
chk('stat chip red', d['red'], d['red_bg'], 4.5)
chk('stat chip blue', d['blue'], d['blue_bg'], 4.5)

print("\n-- notice / table --")
chk('alt-notice text', d['orange'], d['orange_bg'], 4.5)
chk('table th', d['teal_text'], d['teal_mist'], 4.5)
chk('table td', d['ink'], CARD_D, 4.5)

print("\n" + "=" * 82)
print("LIGHT MODE")
print("=" * 82)
l = dict(paper='#FFFFFF', canvas='#F5FAF9',
         ink='#0C2224', ink_soft='#4C6669', ink_faint='#5C7073',
         line_soft='#EEF4F3', line_strong='#728B89', focus='#1C72A6',
         teal='#1C72A6', teal_text='#1C72A6',
         green_text='#166534', orange_text='#854D0E', red_text='#991B1B',
         blue='#0369A1', blue_bg='#E0F2FE')
print("\n-- text --")
chk('ink / paper', l['ink'], l['paper'], 4.5)
chk('ink-soft / paper', l['ink_soft'], l['paper'], 4.5)
chk('ink-faint / paper', l['ink_faint'], l['paper'], 4.5)
chk('ink-faint / canvas', l['ink_faint'], l['canvas'], 4.5)
chk('ink-faint / line-soft (badge.closed)', l['ink_faint'], l['line_soft'], 4.5)
chk('teal-text / paper', l['teal_text'], l['paper'], 4.5)

print("\n-- badges (gradient light skin) --")
chk('badge.ok  #166534 / #DCFCE7', '#166534', '#DCFCE7', 4.5)
chk('badge.ok  #166534 / #BBF7D0', '#166534', '#BBF7D0', 4.5)
chk('badge.low #854D0E / #FEF9C3', '#854D0E', '#FEF9C3', 4.5)
chk('badge.low #854D0E / #FDE68A', '#854D0E', '#FDE68A', 4.5)
chk('badge.out #991B1B / #FEE2E2', '#991B1B', '#FEE2E2', 4.5)
chk('badge.out #991B1B / #FECACA', '#991B1B', '#FECACA', 4.5)
chk('badge.ans #0369A1 / #E0F2FE', l['blue'], l['blue_bg'], 4.5)

print("\n-- stat chips --")
chk('stat chip green', l['green_text'], '#DCFCE7', 4.5)
chk('stat chip orange', l['orange_text'], '#FEF9C3', 4.5)
chk('stat chip red', l['red_text'], '#FEE2E2', 4.5)
chk('stat chip blue', l['blue'], l['blue_bg'], 4.5)

print("\n-- controls / fills --")
chk('line-strong / paper', l['line_strong'], l['paper'], 3)
chk('focus ring / paper', l['focus'], l['paper'], 3)
chk('white / #DC2626 (danger hover)', '#FFFFFF', '#DC2626', 4.5)
chk('alt-notice #92610a / #FEF9C3', '#92610a', '#FEF9C3', 4.5)

print("\n" + "=" * 82)
if FAILS:
    print("RESULT: %d FAILURE(S)" % len(FAILS))
    for f in FAILS: print("   - %s = %.2f (need %.1f)" % f)
else:
    print("RESULT: ALL CHECKS PASS  (WCAG 2.1 AA)")
print("=" * 82)
