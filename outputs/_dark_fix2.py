# -*- coding: utf-8 -*-
"""Dark pass-3: focus/active borders in LIGHT rules must use --focus-ring.

`--teal-primary` is a constant #1C72A6 in both modes, so a light rule that
sets `border-color: var(--teal-primary)` keeps 2.19:1 in dark mode.
`--focus-ring` is #1C72A6 in light (identical, zero visual change) and
#38BDF8 in dark -> 5.4:1.  Also: a fully-filled button needs no outline.
"""
import io, re, os, glob, collections

INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)
RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
BORDER = re.compile(r'((?:^|;)\s*border(?:-color|-top|-right|-bottom|-left'
                    r'|-inline-start|-inline-end)?\s*:\s*)([^;]+)')

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
        plain = re.sub(r'^(?:html|body)\.dark-mode\s+', '', sel.strip())
        if 'dark-mode' in sel or not INTERACTIVE.search(plain):
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue

        def sub(bm):
            v = bm.group(2)
            if 'var(--teal-primary)' in v:
                v = v.replace('var(--teal-primary)', 'var(--focus-ring)')
                hits['teal-primary->focus-ring'] += 1
            return bm.group(1) + v

        body = BORDER.sub(sub, body)
        # a button whose border equals its own fill needs no outline at all
        bgm = re.search(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)', body)
        if bgm:
            for bd in BORDER.finditer(body):
                if bd.group(2).strip() == bgm.group(1).strip():
                    body = body.replace(bd.group(0),
                                        bd.group(1) + 'transparent', 1)
                    hits['border==bg->transparent'] += 1
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
    print('  %-48s %d' % (p, n))
print('per replacement:', dict(total))
