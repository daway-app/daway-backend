# -*- coding: utf-8 -*-
"""Dark-mode pass 2 fixer — interactive borders + white-on-fill.

Discovered by `outputs/interactive-audit.py`:
  A. dark rules kept `var(--line)` (#3A6383 = 1.80:1) as an interactive border
     -> must be `var(--line-strong)` (#5A8FB5 = 3.30:1 on the gradient card)
  B. dark focus/active borders kept `var(--teal-primary)` (#1C72A6 = 2.19:1)
     -> must be `var(--focus-ring)` (#38BDF8)
  C. a handful of dark rules used saturated 500-level fills with white text
     (2.28 / 2.77 / 3.68) -> the constant *-fill tokens
"""
import io, re, os, glob, collections

INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)

BORDER_TOKEN_MAP = {
    'var(--line)': 'var(--line-strong)',
    'var(--ph-line)': 'var(--line-strong)',
    'var(--border-color)': 'var(--line-strong)',
    'var(--teal-primary)': 'var(--focus-ring)',
    '#1C72A6': 'var(--focus-ring)',
    '#1c72a6': 'var(--focus-ring)',
}

# exact-string fixes inside dark rules
EXACT = [
    ('resources/css/pages/dashboard.css', [
        ('border-color: rgba(74, 222, 128, 0.2);', 'border-color: var(--success-text);'),
        ('border-color: rgba(59, 130, 246, 0.2);', 'border-color: var(--info-text);'),
    ]),
    ('resources/css/pages/pharmacies.css', [
        ('border-color: rgba(74, 222, 128, 0.25);', 'border-color: var(--success-text);'),
    ]),
    ('resources/css/pages/pharmacy_hub.css', [
        ('border-color: rgba(248,113,113,.65);', 'border-color: var(--danger-text);'),
    ]),
    ('public/css/responsive.css', [
        ('border-color: var(--teal-deep);', 'border-color: transparent;'),
    ]),
]

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
BORDER = re.compile(r'((?:^|;)\s*border(?:-color|-top|-right|-bottom|-left'
                    r'|-inline-start|-inline-end)?\s*:\s*)([^;]+)')
BG = re.compile(r'((?:^|;)\s*background(?:-color)?\s*:\s*)([^;]+)')


def fix(path):
    src = io.open(path, encoding='utf-8').read()
    hits = collections.Counter()
    out, pos = [], 0
    for m in RULE.finditer(src):
        sel, body = m.group(1), m.group(2)
        out.append(src[pos:m.start(1)])
        if 'dark-mode' not in sel:
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue
        plain = re.sub(r'^(?:html|body)\.dark-mode\s+', '', sel.strip())
        if not INTERACTIVE.search(plain):
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue

        def sub_border(bm):
            v = bm.group(2)
            for a, b in BORDER_TOKEN_MAP.items():
                if a in v:
                    v = v.replace(a, b)
                    hits[a] += 1
            return bm.group(1) + v

        def sub_bg(bm):
            v = bm.group(2)
            for a, b in (('#22c55e', 'var(--success-fill)'),
                         ('#3b82f6', 'var(--info-fill)'),
                         ('#f87171', 'var(--danger-fill)'),
                         ('var(--icon-edit)', 'var(--success-fill)')):
                pat = re.compile(re.escape(a) + r'(?=\s*[;}]|$)', re.I)
                v, k = pat.subn(b, v)
                hits[a] += k
            return bm.group(1) + v

        body = BORDER.sub(sub_border, body)
        body = BG.sub(sub_bg, body)
        out.append(sel + '{' + body + '}')
        pos = m.end()
    out.append(src[pos:])
    new = ''.join(out)

    # exact-string fixes (colour: #f87171 -> token, etc.)
    for p2, pairs in EXACT:
        if p2.replace('/', os.sep) != path.replace('/', os.sep):
            continue
        for a, b in pairs:
            if a in new:
                new = new.replace(a, b)
                hits[a[:24]] += 1
    if 'color: #f87171;' in new and path.endswith('dashboard.css'):
        new = new.replace('color: #f87171;', 'color: var(--danger-text);')
        hits['color #f87171'] += 1
    if 'border-color: #f87171;' in new and path.endswith('dashboard.css'):
        new = new.replace('border-color: #f87171;', 'border-color: var(--danger-text);')
        hits['border #f87171'] += 1

    if sum(hits.values()):
        assert len(new) > len(src) * 0.9, path
        io.open(path, 'w', encoding='utf-8', newline='').write(new)
    return hits


total = collections.Counter()
changed = {}
for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path or not path.endswith('.css'):
        continue
    if os.path.basename(path) == 'tokens.css':
        continue
    h = fix(path.replace('/', os.sep))
    if sum(h.values()):
        changed[path.replace(os.sep, '/')] = sum(h.values())
        total.update(h)

print('files changed: %d' % len(changed))
for p, n in sorted(changed.items(), key=lambda kv: -kv[1]):
    print('  %-48s %d' % (p, n))
print('\nper replacement:')
for k, v in total.most_common():
    print('  %-24s %d' % (k, v))
print('total: %d' % sum(total.values()))
