# -*- coding: utf-8 -*-
"""Light-mode pass 2 fixer — interactive component borders -> --line-strong.

WCAG 1.4.11 (non-text contrast) requires >= 3:1 for the visual information
needed to identify a UI component.  Form controls / buttons were using
`--line` (#DEE8E7 = 1.25:1) which is decorative, not identifying.

Rules
-----
* light rules only (selector must not contain `dark-mode`)
* only selectors that look interactive
* only border-ish declarations
* a border whose colour equals the rule's own background is skipped
  (the control is already identified by its fill)
"""
import io, re, os, glob, collections

INTERACTIVE = re.compile(
    r'(^|[\s,>~+])(input|select|textarea|button|a)\b'
    r'|\[type=|::placeholder|:focus|\.btn|\.fc\b|\.form-control|\.select-fc|\.req\b'
    r'|\.act-|\.modal-btn|\.ph-btn|\.pill-btn|\.tab-btn|\.pi-chip|\.search-input',
    re.I)

# decorative / already-failing border colours that are "not identifying"
BORDER_MAP = {
    '#DEE8E7': 'var(--line-strong)',
    '#E2E8F0': 'var(--line-strong)',
    '#CBD5E1': 'var(--line-strong)',
    '#D8E0E8': 'var(--line-strong)',
    '#FECACA': 'var(--danger-text)',
    '#BBF7D0': 'var(--success-text)',
    '#BAE6FD': 'var(--info-text)',
    '#BFDBFE': 'var(--info-text)',
    '#99F6E4': 'var(--teal-primary)',
    '#5EEAD4': 'var(--teal-primary)',
    '#14B8A6': 'var(--teal-primary)',
    '#CBD5E0': 'var(--line-strong)',
    '#B8C5D2': 'var(--line-strong)',
    '#94A3B8': 'var(--line-strong)',
    '#60A5FA': 'var(--info-text)',
    'var(--line)': 'var(--line-strong)',
    'var(--ph-line)': 'var(--line-strong)',
    'var(--border-color)': 'var(--line-strong)',
}

RULE = re.compile(r'([^{}]+)\{([^{}]*)\}', re.S)
DECL = re.compile(r'((?:^|;)\s*border(?:-color|-top|-right|-bottom|-left'
                  r'|-inline-start|-inline-end)?\s*:\s*)([^;]+)')
BG = re.compile(r'(?:^|;)\s*background(?:-color)?\s*:\s*([^;]+)')


def fix(text, base):
    hits = collections.Counter()
    out, pos = [], 0
    for m in RULE.finditer(text):
        sel, body = m.group(1), m.group(2)
        out.append(text[pos:m.start(1)])
        if 'dark-mode' in sel or not INTERACTIVE.search(sel):
            out.append(sel + '{' + body + '}')
            pos = m.end()
            continue

        bgm = BG.search(body)
        bgval = bgm.group(1).strip() if bgm else None

        def sub(dm):
            v = dm.group(2)
            if bgval and v.strip() == bgval:
                return dm.group(0)          # border matches its own fill — skip
            nv = v
            for a, b in BORDER_MAP.items():
                # exact token, or the hex as a whole word
                if a.startswith('var('):
                    if a in nv:
                        nv = nv.replace(a, b)
                        hits[a] += 1
                else:
                    pat = re.compile(re.escape(a) + r'\b', re.I)
                    nv, k = pat.subn(b, nv)
                    hits[a] += k
            return dm.group(1) + nv

        body = DECL.sub(sub, body)
        out.append(sel + '{' + body + '}')
        pos = m.end()
    out.append(text[pos:])
    return ''.join(out), hits


total = collections.Counter()
changed = {}
for path in sorted(glob.glob('resources/css/**/*.css', recursive=True)
                   + glob.glob('public/css/**/*.css', recursive=True)):
    if '.bak' in path or not path.endswith('.css'):
        continue
    if os.path.basename(path) == 'tokens.css':
        continue
    src = io.open(path, encoding='utf-8').read()
    new, hits = fix(src, path)
    if sum(hits.values()):
        assert len(new) > len(src) * 0.9, path
        io.open(path, 'w', encoding='utf-8', newline='').write(new)
        changed[path.replace(os.sep, '/')] = sum(hits.values())
        total.update(hits)

print('files changed: %d' % len(changed))
for p, n in sorted(changed.items(), key=lambda kv: -kv[1]):
    print('  %-48s %d' % (p, n))
print('\nper colour:')
for k, v in total.most_common():
    print('  %-22s -> %-22s %d' % (k, BORDER_MAP.get(k, '?'), v))
print('total border replacements: %d' % sum(total.values()))
