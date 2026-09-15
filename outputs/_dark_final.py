# -*- coding: utf-8 -*-
"""Dark-mode closure — the last 19 failures from outputs/a11y-audit.py.

Every edit is anchored on a unique block so it can never hit a light rule
by accident.  Appended `body.dark-mode` blocks are idempotent (marker check).
"""
import io, os, collections

D = 'resources/css'
total = collections.Counter()


def sub(path, pairs):
    """Replace exact unique strings in a file."""
    p = path.replace('/', os.sep)
    s = io.open(p, encoding='utf-8').read()
    for a, b in pairs:
        n = s.count(a)
        if n != 1:
            print('  !! %s -> %d occurrences of %r' % (p, n, a[:70]))
            continue
        s = s.replace(a, b)
        total[os.path.basename(p)] += 1
    io.open(p, 'w', encoding='utf-8', newline='').write(s)


def append(path, marker, block):
    """Append a dark-mode block once."""
    p = path.replace('/', os.sep)
    s = io.open(p, encoding='utf-8').read()
    if marker in s:
        print('  == already present in %s' % p)
        return
    s = s.rstrip() + '\n' + block
    io.open(p, 'w', encoding='utf-8', newline='').write(s)
    total[os.path.basename(p)] += 1


# ---------------------------------------------------------------- literals
# white-on-fill failures (WCAG 1.4.3, 4.5:1) and too-light gradient stops
sub(D + '/pages/statistics.css', [
    # body.dark-mode .t-av.ph  ->  3.79:1
    ('body.dark-mode .t-av.ph {\n    background-color: rgba(59, 130, 246, 0.15);\n    color: #60a5fa;\n}',
     'body.dark-mode .t-av.ph {\n    background-color: rgba(59, 130, 246, 0.15);\n    color: var(--info-text);\n}'),
    # body.dark-mode .bdg-err  ->  3.91:1
    ('body.dark-mode .bdg-err { background: rgba(239, 68, 68, 0.12); color: #f87171; }',
     'body.dark-mode .bdg-err { background: rgba(239, 68, 68, 0.12); color: var(--danger-text); }'),
])

sub(D + '/pages/dashboard.css', [
    # body.dark-mode .btn-view  ->  4.04:1
    ('body.dark-mode .btn-view {\n    background-color: rgba(59, 130, 246, 0.1);\n    color: #60a5fa;\n    border-color: var(--info-text);\n}',
     'body.dark-mode .btn-view {\n    background-color: rgba(59, 130, 246, 0.1);\n    color: var(--info-text);\n    border-color: var(--info-text);\n}'),
])

sub(D + '/layout/topbar.css', [
    # body.dark-mode .logout-btn  ->  3.80:1
    ('body.dark-mode .logout-btn {\n    background-color: rgba(239, 68, 68, 0.15);\n    border-color: rgba(239, 68, 68, 0.3);\n    color: #f87171;\n}',
     'body.dark-mode .logout-btn {\n    background-color: rgba(239, 68, 68, 0.15);\n    border-color: rgba(239, 68, 68, 0.3);\n    color: var(--danger-text);\n}'),
    # body.dark-mode .avatar: white on #4A9BD4 = 3.03:1
    ('body.dark-mode .avatar {\n    background: linear-gradient(155deg, #4A9BD4, #1C72A6);',
     'body.dark-mode .avatar {\n    background: linear-gradient(155deg, #1C72A6, #155E85);'),
])

# sidebar .avatar-box: white on #4A9BD4 = 3.03:1 in BOTH modes
sub(D + '/layout/sidebar.css', [
    ('    background: linear-gradient(155deg, #4A9BD4, #1C72A6);\n    display: flex;\n    align-items: center;\n    justify-content: center;\n    font-weight: 700;\n    font-size: 15px;\n    color: #ffffff;',
     '    background: linear-gradient(155deg, #1C72A6, #155E85);\n    display: flex;\n    align-items: center;\n    justify-content: center;\n    font-weight: 700;\n    font-size: 15px;\n    color: #ffffff;'),
])

# .card-header-modern: white on the #14b8a6 gradient stop = 2.49:1 in BOTH modes
sub(D + '/pages/users_create.css', [
    ('background: linear-gradient(135deg, #155E85 0%, #14b8a6 100%);',
     'background: linear-gradient(135deg, #155E85 0%, #0F766E 100%);'),
])

# ---------------------------------------------------------------- dark blocks
append(D + '/app.css', 'body.dark-mode ::selection', """
/* =========================================================
   الوضع الداكن — إغلاق تدقيق WCAG (تباين النص)
   ========================================================= */
body.dark-mode ::selection { background: var(--teal-primary); color: #ffffff; }
body.dark-mode .header-icon-glow { background: var(--info-bg); }
""")

append(D + '/pages/patients.css', 'body.dark-mode .action-btn-group form .modern-action-btn', """
/* الوضع الداكن — أسطح فاتحة كانت تُرسم بنفس قيم الوضع الفاتح */
body.dark-mode .header-icon-glow { background: var(--info-bg); }
body.dark-mode .action-btn-group a.modern-action-btn { background: var(--info-bg); }
body.dark-mode .action-btn-group form .modern-action-btn { background: var(--danger-bg); }
""")

append(D + '/layout/app_layout.css', 'body.dark-mode .breadcrumb-nav a:hover', """
/* الوضع الداكن — --teal-deep ليس معرَّفًا للداكن فيبقى #155E85 */
body.dark-mode .breadcrumb-nav a:hover { color: var(--teal-text); }
""")

append(D + '/pages/pharmacies_create.css', 'body.dark-mode .map-overlay-hint', """
/* الوضع الداكن — تدرّج/طبقة بيضاء لم يكونا معرَّفين للداكن */
body.dark-mode .alert-ok {
    background: var(--success-bg);
    border-color: rgba(74, 222, 128, 0.35);
}
body.dark-mode .map-overlay-hint {
    background: rgba(17, 36, 51, 0.92);
    border: 1px solid var(--line-strong);
}
""")

append(D + '/pages/medicines_edit.css', 'body.dark-mode .page-heading-icon', """
/* الوضع الداكن — التدرّج الفاتح كان يُرسم كما هو */
body.dark-mode .page-heading-icon { background: var(--teal-mist); }
""")

for k, v in sorted(total.items()):
    print('  %-28s %d' % (k, v))
print('total edits: %d' % sum(total.values()))
