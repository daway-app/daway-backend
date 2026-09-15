# -*- coding: utf-8 -*-
import io, re, collections

# --- 1. pharmacy_import.css : semantic token used as TEXT ---
p = 'resources/css/pages/pharmacy_import.css'
s = io.open(p, encoding='utf-8').read()
n = 0
for name in ('green', 'orange', 'red', 'blue', 'teal', 'teal-deep'):
    tgt = 'var(--ph-%s)' % name
    rep = 'var(--ph-%s-text)' % name if name != 'teal-deep' else 'var(--ph-teal-text)'
    pat = re.compile(r'(color\s*:\s*)' + re.escape(tgt) + r'(?=\s*[;}])')
    s, k = pat.subn(lambda m: m.group(1) + rep, s)
    n += k
io.open(p, 'w', encoding='utf-8', newline='').write(s)
print('pharmacy_import.css: %d color -> -text' % n)

# --- 2. pharmacies.css : local icon tokens -> theme-aware ---
p = 'resources/css/pages/pharmacies.css'
s = io.open(p, encoding='utf-8').read()
M = {
    '--icon-edit: #16a34a;':  '--icon-edit: var(--success-text);',
    '--icon-view: #0284c7;':  '--icon-view: var(--info-text);',
    '--icon-pause: #ea580c;': '--icon-pause: var(--warning-text);',
    '--icon-play: #db2777;':  '--icon-play: var(--pink-text);',
    '--icon-export: #4f46e5;': '--icon-export: var(--indigo-text);',
}
k = 0
for a, b in M.items():
    if a in s:
        s = s.replace(a, b); k += 1
io.open(p, 'w', encoding='utf-8', newline='').write(s)
print('pharmacies.css: %d icon tokens converted' % k)

# --- 3. statistics.css : breadcrumb separator glyph ---
p = 'resources/css/pages/statistics.css'
s = io.open(p, encoding='utf-8').read()
pat = re.compile(r'(\.breadcrumb-nav\s+\.sep\s*\{[^}]*?color\s*:\s*)var\(--line-strong\)')
s, k = pat.subn(lambda m: m.group(1) + 'var(--ink-faint)', s)
io.open(p, 'w', encoding='utf-8', newline='').write(s)
print('statistics.css: %d sep colour fixed' % k)
