# -*- coding: utf-8 -*-
"""Root-cause pass: light rules that hard-code a colour which cannot work in
dark mode.

Two families
------------
1. `color:` literals that are *only* readable on a light surface
   (#334155 slate-700, #1C72A6 teal, #0F172A near-black, ...).
   -> replaced by the theme-aware text token.  In LIGHT the token value is
      identical or near-identical, so the light design does not move.

2. `background:` pastel literals (green-50, red-100, sky-50, ...) that were
   never re-declared in dark mode, so a dark text token ended up sitting on a
   bright pastel.
   -> replaced by the semantic *-bg token, which is a pastel in light and a
      low-alpha tint in dark.

Gradients are never touched.
"""
import io, re, os, glob, collections

COLOR_MAP = {
    '#334155': 'var(--ink)',
    '#1E293B': 'var(--ink)',
    '#0F172A': 'var(--ink)',
    '#475569': 'var(--ink-soft)',
    '#155E85': 'var(--teal-text)',
    '#1C72A6': 'var(--teal-text)',
    '#065F46': 'var(--success-text)',
    '#047857': 'var(--success-text)',
    '#0369A1': 'var(--info-text)',
    '#E11D48': 'var(--danger-text)',
    '#4A9BD4': 'var(--info-text)',
    '#64748B': 'var(--ink-soft)',
    '#718096': 'var(--ink-soft)',
}

BG_MAP = {
    '#F0FDF4': 'var(--success-bg)',
    '#DCFCE7': 'var(--success-bg)',
    '#D1FAE5': 'var(--success-bg)',
    '#FEF3C7': 'var(--warning-bg)',
    '#FEF9C3': 'var(--warning-bg)',
    '#FFF7ED': 'var(--warning-bg)',
    '#FEE2E2': 'var(--danger-bg)',
    '#FEF2F2': 'var(--danger-bg)',
    '#FECACA': 'var(--danger-bg)',
    '#E0F2FE': 'var(--info-bg)',
    '#F0F9FF': 'var(--info-bg)',
    '#EFF6FF': 'var(--info-bg)',
    '#F1F5F9': 'var(--line-soft)',
    '#F8FAFC': 'var(--canvas)',
    '#EAF5F4': 'var(--teal-mist)',
}

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
DECL_C = re.compile(r'((?:^|;)\s*color\s*:\s*)([^;]+)')
DECL_B = re.compile(r'((?:^|;)\s*background(?:-color)?\s*:\s*)([^;]+)')


def apply_map(value, table):
    n = 0
    if 'gradient(' in value:
        return value, 0

    def sub(m):
        nonlocal n
        t = m.group(0)
        key = '#' + t[1:].upper()
        if len(t) == 4:
            key = '#' + ''.join(c * 2 for c in t[1:]).upper()
        if key in table:
            n += 1
            return table[key]
        return t

    return re.sub(r'#[0-9A-Fa-f]{3,6}\b', sub, value), n


total = collections.Counter()
changed = {}

for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path or not path.endswith('.css'):
        continue
    if os.path.basename(path) == 'tokens.css':
        continue
    src = io.open(path, encoding='utf-8').read()
    hits = collections.Counter()
    out, pos = [], 0
    for m in RULE.finditer(src):
        sel, body = m.group(1), m.group(2)
        out.append(src[pos:m.start(1)])
        if 'dark-mode' in sel:
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue

        def sc(dm):
            v, k = apply_map(dm.group(2), COLOR_MAP)
            hits['color'] += k
            return dm.group(1) + v

        def sb(dm):
            v, k = apply_map(dm.group(2), BG_MAP)
            hits['background'] += k
            return dm.group(1) + v

        body = DECL_C.sub(sc, body)
        body = DECL_B.sub(sb, body)
        out.append(sel + '{' + body + '}')
        pos = m.end()
    out.append(src[pos:])
    new = ''.join(out)
    if sum(hits.values()):
        assert len(new) > len(src) * 0.9, path
        io.open(path, 'w', encoding='utf-8', newline='').write(new)
        changed[path.replace(os.sep, '/')] = sum(hits.values())
        total.update(hits)

print('files changed: %d' % len(changed))
for p, n in sorted(changed.items(), key=lambda kv: -kv[1]):
    print('  %-46s %d' % (p, n))
print('color: %d · background: %d · total %d'
      % (total['color'], total['background'], sum(total.values())))
