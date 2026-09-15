# -*- coding: utf-8 -*-
"""Light-mode literal -> token fixer.

Rules
-----
* Only touches rules whose selector does NOT contain `dark-mode`
  (dark blocks are already tokenised).
* Only rewrites `color:` (COLOR_MAP) and `background`/`background-color:`
  (FILL_MAP).  Borders / shadows are left alone — the audit does not
  measure them and changing them adds risk without benefit.
* Skips any `background` value containing `gradient(` (a gradient is a
  decoration, not a semantic fill).
"""
import io, re, os, collections

COLOR_MAP = {
    '#94A3B8': 'var(--ink-faint)',
    '#A1ADBA': 'var(--ink-faint)',
    '#64748B': 'var(--ink-soft)',
    '#718096': 'var(--ink-soft)',
    '#CBD5E1': 'var(--line-strong)',
    '#16A34A': 'var(--success-text)',
    '#10B981': 'var(--success-text)',
    '#059669': 'var(--success-text)',
    '#CA8A04': 'var(--warning-text)',
    '#D97706': 'var(--warning-text)',
    '#F97316': 'var(--warning-text)',
    '#EA580C': 'var(--warning-text)',
    '#F59E0B': 'var(--warning-text)',
    '#EF4444': 'var(--danger-text)',
    '#DC2626': 'var(--danger-text)',
    '#0284C7': 'var(--info-text)',
    '#3B82F6': 'var(--info-text)',
    '#14B8A6': 'var(--teal-text)',
    '#DB2777': 'var(--pink-text)',
    '#5BB3B3': 'var(--text-muted)',
    '#7BC1B7': 'var(--text-muted)',
    '#C5C3C3': 'var(--text-muted)',
}

FILL_MAP = {
    '#16A34A': 'var(--success-fill)',
    '#10B981': 'var(--success-fill)',
    '#059669': 'var(--success-fill)',
    '#EF4444': 'var(--danger-fill)',
    '#DC2626': 'var(--danger-fill)',
    '#0284C7': 'var(--info-fill)',
    '#3B82F6': 'var(--info-fill)',
    '#14B8A6': 'var(--teal-fill)',
    '#CA8A04': 'var(--warning-fill)',
    '#D97706': 'var(--warning-fill)',
    '#D1FAE5': 'var(--success-bg)',
}

# files that legitimately own their own light tokens (handled manually)
SKIP_FILES = {'tokens.css', 'sidebar.css'}

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)


def map_value(value, table, allow_gradient=True):
    if not allow_gradient and 'gradient(' in value:
        return value, 0
    n = 0

    def sub(m):
        nonlocal n
        tok = m.group(0)
        key = '#' + tok[1:].upper()
        if len(tok) == 4:                       # #abc
            key = '#' + ''.join(c * 2 for c in tok[1:]).upper()
        if key in table:
            n += 1
            return table[key]
        return tok

    return re.sub(r'#[0-9A-Fa-f]{3,6}\b', sub, value), n


def fix(text):
    hits = collections.Counter()
    out, pos = [], 0
    for m in RULE.finditer(text):
        sel, body = m.group(1), m.group(2)
        out.append(text[pos:m.start(1)])
        if 'dark-mode' in sel:
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue
        new_body = body

        # --- color: ---
        def sub_color(cm):
            v, n = map_value(cm.group(2), COLOR_MAP)
            hits['color'] += n
            return cm.group(1) + v
        new_body = re.sub(r'((?:^|;)\s*color\s*:\s*)([^;]+)', sub_color, new_body)

        # --- background / background-color ---
        def sub_bg(cm):
            v, n = map_value(cm.group(2), FILL_MAP, allow_gradient=False)
            hits['background'] += n
            return cm.group(1) + v
        new_body = re.sub(r'((?:^|;)\s*background(?:-color)?\s*:\s*)([^;]+)', sub_bg, new_body)

        out.append(sel + '{' + new_body + '}')
        pos = m.end()
    out.append(text[pos:])
    return ''.join(out), hits


total = collections.Counter()
per_file = {}
for root, _, files in os.walk('resources/css'):
    for fn in files:
        if not fn.endswith('.css') or fn in SKIP_FILES:
            continue
        p = os.path.join(root, fn).replace(os.sep, '/')
        src = io.open(p, encoding='utf-8').read()
        new, hits = fix(src)
        if sum(hits.values()):
            assert len(new) > len(src) * 0.9, p
            io.open(p, 'w', encoding='utf-8', newline='').write(new)
            per_file[p] = sum(hits.values())
            total.update(hits)

print('files changed: %d' % len(per_file))
for p, n in sorted(per_file.items(), key=lambda kv: -kv[1]):
    print('  %-46s %d' % (p, n))
print('total replacements: color=%d background=%d  (%d)'
      % (total['color'], total['background'], sum(total.values())))
