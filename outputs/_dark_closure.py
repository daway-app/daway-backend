# -*- coding: utf-8 -*-
"""Final dark-mode closure pass — explicit, reviewable edits."""
import io, re, os, collections

D = 'resources/css'
total = collections.Counter()


def edit(path, pairs, count_label=None):
    path = path.replace('/', os.sep)
    s = io.open(path, encoding='utf-8').read()
    for a, b in pairs:
        n = s.count(a)
        if n == 0:
            print('  !! NOT FOUND in %s: %r' % (path, a[:60]))
            continue
        s = s.replace(a, b)
        total[(count_label or a)[:40]] += n
    io.open(path, 'w', encoding='utf-8', newline='').write(s)


# 1. dark rules that still hold a literal / semantic colour used as TEXT
edit(D + '/pages/statistics.css', [
    ('color: #60A5FA;', 'color: var(--info-text);'),
    ('color: #60a5fa;', 'color: var(--info-text);'),
    ('color: #F87171;', 'color: var(--danger-text);'),
    ('color: #f87171;', 'color: var(--danger-text);'),
])
edit(D + '/pages/dashboard.css', [
    ('color: #60a5fa;', 'color: var(--info-text);'),
    ('color: #60A5FA;', 'color: var(--info-text);'),
])
edit(D + '/layout/topbar.css', [
    ('color: #F87171;', 'color: var(--danger-text);'),
    ('color: #f87171;', 'color: var(--danger-text);'),
])
edit(D + '/layout/app_layout.css', [
    ('color: #155E85;', 'color: var(--teal-text);'),
])

# 2. gradient stops that are too light for white text
edit(D + '/pages/users_create.css', [
    ('linear-gradient(135deg, #155E85 0%, #14b8a6 100%)',
     'linear-gradient(135deg, #155E85 0%, #0F766E 100%)'),
])
edit(D + '/layout/sidebar.css', [
    ('background: linear-gradient(155deg, #4A9BD4, #1C72A6);',
     'background: linear-gradient(155deg, #1C72A6, #155E85);'),
])

# 3. light gradients / pastels that were never re-declared in dark
edit(D + '/pages/pharmacies_create.css', [
    ('.card-head h2 {', '.card-head h2 {'),   # no-op anchor (keeps diff explicit)
])
s = io.open((D + '/pages/pharmacies_create.css').replace('/', os.sep), encoding='utf-8').read()
ADD = """
/* =========================================================
   الوضع الداكن — أسطح فاتحة لم تكن مُعرَّفة (إغلاق تدقيق WCAG)
   ========================================================= */
body.dark-mode .card-head { background: var(--paper); border-bottom-color: var(--line); }
body.dark-mode .map-overlay-hint { background: var(--paper); border-color: var(--line-strong); }
body.dark-mode .alert-ok { background: var(--success-bg); border-color: var(--success-text); }
body.dark-mode .alert-error { background: var(--danger-bg); border-color: var(--danger-text); }
body.dark-mode .page-heading-icon { background: var(--teal-mist); }
body.dark-mode .header-icon-glow { background: var(--info-bg); }
body.dark-mode .section-number { background: var(--line-soft); }
body.dark-mode .fc::placeholder { color: var(--ink-faint); }
body.dark-mode .coordinate-box span,
body.dark-mode .card-head p { color: var(--ink-soft); }
"""
if 'أسطح فاتحة لم تكن مُعرَّفة' not in s:
    s = s.rstrip() + '\n' + ADD
    total['pharmacies_create dark block'] += 1
io.open((D + '/pages/pharmacies_create.css').replace('/', os.sep), 'w',
        encoding='utf-8', newline='').write(s)

s = io.open((D + '/pages/patients.css').replace('/', os.sep), encoding='utf-8').read()
ADD = """
body.dark-mode .header-icon-glow { background: var(--info-bg); }
body.dark-mode .action-btn-group a.modern-action-btn { background: var(--info-bg); }
body.dark-mode .action-btn-group form .modern-action-btn { background: var(--danger-bg); }
body.dark-mode .action-btn-group a.modern-action-btn:hover { background: var(--info-fill); color: #fff; }
"""
if '.action-btn-group a.modern-action-btn:hover { background: var(--info-fill)' not in s:
    s = s.rstrip() + '\n' + ADD
    total['patients dark block'] += 1
io.open((D + '/pages/patients.css').replace('/', os.sep), 'w',
        encoding='utf-8', newline='').write(s)

s = io.open((D + '/pages/medicines_edit.css').replace('/', os.sep), encoding='utf-8').read()
ADD = """
body.dark-mode .page-heading-icon { background: var(--teal-mist); }
"""
if 'body.dark-mode .page-heading-icon' not in s:
    s = s.rstrip() + '\n' + ADD
    total['medicines_edit dark block'] += 1
io.open((D + '/pages/medicines_edit.css').replace('/', os.sep), 'w',
        encoding='utf-8', newline='').write(s)

s = io.open((D + '/app.css').replace('/', os.sep), encoding='utf-8').read()
ADD = """
body.dark-mode .header-icon-glow { background: var(--info-bg); }
body.dark-mode .badge-info { background: var(--info-bg); color: var(--info-text); }
body.dark-mode ::selection { background: var(--teal-mist); color: var(--ink); }
"""
if 'body.dark-mode ::selection' not in s:
    s = s.rstrip() + '\n' + ADD
    total['app.css dark block'] += 1
io.open((D + '/app.css').replace('/', os.sep), 'w',
        encoding='utf-8', newline='').write(s)

for k, v in sorted(total.items()):
    print('  %-42s %d' % (k, v))
print('total edits: %d' % sum(total.values()))
