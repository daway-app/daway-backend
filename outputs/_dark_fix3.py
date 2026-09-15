# -*- coding: utf-8 -*-
import io, os, collections
total = collections.Counter()

EDITS = [
 ('resources/css/pages/medicines.css', [
   ('border: 1.5px solid #1C72A6;', 'border: 1.5px solid var(--focus-ring);'),
   ('.filter-input:focus {\n    border-color: var(--primary);\n}',
    '.filter-input:focus {\n    border-color: var(--focus-ring);\n}'),
 ]),
 ('resources/css/pages/medicines_edit.css', [
   ('border-color: #1C72A6;', 'border-color: var(--focus-ring);'),
 ]),
 ('resources/css/pages/pharmacies_create.css', [
   ('border-color: #1C72A6;', 'border-color: var(--focus-ring);'),
 ]),
 ('resources/css/pages/statistics.css', [
   ('border-color: #1C72A6;', 'border-color: var(--focus-ring);'),
 ]),
 ('resources/css/layout/topbar.css', [
   ('.form-group input:focus {\n    border-color: var(--accent-teal);',
    '.form-group input:focus {\n    border-color: var(--focus-ring);'),
 ]),
]

for p, pairs in EDITS:
    p = p.replace('/', os.sep)
    s = io.open(p, encoding='utf-8').read()
    for a, b in pairs:
        n = s.count(a)
        if n:
            s = s.replace(a, b)
            total[a[:34]] += n
    io.open(p, 'w', encoding='utf-8', newline='').write(s)

for k, v in total.items():
    print('  %-36s %d' % (k, v))
print('total: %d' % sum(total.values()))
