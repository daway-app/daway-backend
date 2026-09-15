# -*- coding: utf-8 -*-
"""Final pass: dark-rule colour tokens, info-text brightness, extra pastels."""
import io, re, os, glob, collections

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
DECL_C = re.compile(r'((?:^|;)\s*color\s*:\s*)([^;]+)')
DECL_B = re.compile(r'((?:^|;)\s*background(?:-color)?\s*:\s*)([^;]+)')

# semantic tokens used as TEXT inside dark rules -> their *-text twin
DARK_TOKEN_COLOR = {
    'var(--danger)': 'var(--danger-text)',
    'var(--success)': 'var(--success-text)',
    'var(--warning)': 'var(--warning-text)',
    'var(--info)': 'var(--info-text)',
    'var(--ph-red)': 'var(--ph-red-text)',
    'var(--ph-green)': 'var(--ph-green-text)',
    'var(--ph-orange)': 'var(--ph-orange-text)',
    'var(--ph-blue)': 'var(--ph-blue-text)',
    'var(--icon-edit)': 'var(--success-text)',
    '#4A9BD4': 'var(--info-text)',
    '#4a9bd4': 'var(--info-text)',
}

EXTRA_BG = {
    '#FFF7F7': 'var(--danger-bg)',
    '#ECFDF5': 'var(--success-bg)',
    '#EDEFF2': 'var(--line-soft)',
    '#EDF2F7': 'var(--line-soft)',
    '#E2E8F0': 'var(--line)',
}

total = collections.Counter()
changed = {}
SKIP = {'tokens.css', 'auth.css', 'auth_login.css', 'pharmacy_dashboard.css'}

for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path or not path.endswith('.css') or os.path.basename(path) in SKIP:
        continue
    src = io.open(path, encoding='utf-8').read()
    hits = collections.Counter()
    out, pos = [], 0
    for m in RULE.finditer(src):
        sel, body = m.group(1), m.group(2)
        out.append(src[pos:m.start(1)])

        def sc(dm):
            v = dm.group(2)
            for a, b in DARK_TOKEN_COLOR.items():
                if a in v:
                    v = v.replace(a, b)
                    hits['color'] += 1
            return dm.group(1) + v

        def sb(dm):
            v, n = dm.group(2), 0
            if 'gradient(' not in v:
                for a, b in EXTRA_BG.items():
                    if a.lower() in v.lower():
                        v = re.sub(re.escape(a), b, v, flags=re.I)
                        n += 1
            hits['background'] += n
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
print(dict(total), 'total', sum(total.values()))
