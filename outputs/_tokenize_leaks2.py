# -*- coding: utf-8 -*-
"""Tokenise-leaks pass 2 — the long tail.

Adds to pass 1:
  * `color:` tokens that are constant across modes but only legible on light
    surfaces (--teal-deep #155E85, --teal-primary #1C72A6, --primary,
    --accent-teal, --success/--warning/--danger/--info) -> their *-text twin.
  * `background:` white literals -> var(--paper) so a white card/input turns
    into the dark paper instead of staying white.
  * the remaining pastel surfaces.
"""
import io, re, os, glob, collections

HEX_COLOR = {
    '#334155': 'var(--ink)',
    '#1E293B': 'var(--ink)',
    '#0F172A': 'var(--ink)',
    '#475569': 'var(--ink-soft)',
    '#64748B': 'var(--ink-soft)',
    '#718096': 'var(--ink-soft)',
    '#155E85': 'var(--teal-text)',
    '#1C72A6': 'var(--teal-text)',
    '#0E7490': 'var(--teal-text)',
    '#065F46': 'var(--success-text)',
    '#047857': 'var(--success-text)',
    '#166534': 'var(--success-text)',
    '#854D0E': 'var(--warning-text)',
    '#92400E': 'var(--warning-text)',
    '#B45309': 'var(--warning-text)',
    '#991B1B': 'var(--danger-text)',
    '#B91C1C': 'var(--danger-text)',
    '#0369A1': 'var(--info-text)',
    '#2563EB': 'var(--info-text)',
    '#4A9BD4': 'var(--info-text)',
    '#E11D48': 'var(--danger-text)',
}

# token -> token, applied ONLY inside `color:`
TOKEN_COLOR = {
    'var(--teal-deep)': 'var(--teal-text)',
    'var(--teal-primary)': 'var(--teal-text)',
    'var(--primary)': 'var(--teal-text)',
    'var(--accent-teal)': 'var(--teal-text)',
    'var(--info)': 'var(--info-text)',
    'var(--success)': 'var(--success-text)',
    'var(--warning)': 'var(--warning-text)',
    'var(--danger)': 'var(--danger-text)',
}

HEX_BG = {
    '#F0FDF4': 'var(--success-bg)',
    '#DCFCE7': 'var(--success-bg)',
    '#D1FAE5': 'var(--success-bg)',
    '#FEF3C7': 'var(--warning-bg)',
    '#FEF9C3': 'var(--warning-bg)',
    '#FFF7ED': 'var(--warning-bg)',
    '#FEE2E2': 'var(--danger-bg)',
    '#FEF2F2': 'var(--danger-bg)',
    '#E0F2FE': 'var(--info-bg)',
    '#F0F9FF': 'var(--info-bg)',
    '#EFF6FF': 'var(--info-bg)',
    '#F1F5F9': 'var(--line-soft)',
    '#F8FAFC': 'var(--canvas)',
    '#EAF5F4': 'var(--teal-mist)',
    '#E6F4F1': 'var(--line-soft)',
    '#EDEFF2': 'var(--line-soft)',
    '#CCFBF1': 'var(--teal-mist)',
    '#F0FDFA': 'var(--teal-mist)',
    '#FFFFFF': 'var(--paper)',
    '#FFF': 'var(--paper)',
}

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
DECL_C = re.compile(r'((?:^|;)\s*color\s*:\s*)([^;]+)')
DECL_B = re.compile(r'((?:^|;)\s*background(?:-color)?\s*:\s*)([^;]+)')


def map_hex(value, table):
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


def map_tok(value, table):
    n = 0
    for a, b in table.items():
        if a in value:
            value = value.replace(a, b)
            n += 1
    return value, n


total = collections.Counter()
changed = {}

for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path or not path.endswith('.css'):
        continue
    base = os.path.basename(path)
    if base in ('tokens.css', 'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'):
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
            v, k = map_hex(dm.group(2), HEX_COLOR)
            hits['color-hex'] += k
            v, k2 = map_tok(v, TOKEN_COLOR)
            hits['color-token'] += k2
            return dm.group(1) + v

        def sb(dm):
            v, k = map_hex(dm.group(2), HEX_BG)
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
print(dict(total))
print('total: %d' % sum(total.values()))
