# -*- coding: utf-8 -*-
"""Sweep the banned zinc palette out of every remaining file.

zinc (#232327 / #333338 / #3f3f46 / #A1A1AA / #E4E4E7 / rgba(35,35,39,*))
belongs to a dark palette that was removed from tokens.css.  It survived in
Blade inline <style> blocks and a few page CSS files, so those surfaces stayed
neutral grey while the rest of the app is navy.

In <style>/CSS we can use var(); inside JS chart configs we must use a literal.
"""
import io, os, collections

R = collections.Counter()

EDITS = {
    'resources/css/pages/medicines.css': [
        ('linear-gradient(145deg, rgba(35, 35, 39, 0.96), rgba(51, 51, 56, 0.88))',
         'linear-gradient(145deg, rgba(17, 36, 51, 0.96), rgba(42, 71, 97, 0.88))'),
        ('linear-gradient(145deg, rgba(35, 35, 39, 0.94), rgba(51, 51, 56, 0.78))',
         'linear-gradient(145deg, rgba(17, 36, 51, 0.94), rgba(42, 71, 97, 0.78))'),
    ],
    'resources/css/pages/pharmacies.css': [
        ('background: rgba(35, 35, 39, 0.75) !important;',
         'background: rgba(17, 36, 51, 0.85) !important;'),
        ('border: 1px solid rgba(51, 51, 56, 0.8) !important;',
         'border: 1px solid rgba(42, 71, 97, 0.8) !important;'),
        ('inset 0 1px 1px 0 rgba(51, 51, 56, 0.9) !important;',
         'inset 0 1px 1px 0 rgba(42, 71, 97, 0.9) !important;'),
        ('background: rgba(35, 35, 39, 0.8);',
         'background: rgba(17, 36, 51, 0.9);'),
    ],
    'resources/views/pharmacies/index.blade.php': [
        ('background: rgba(35, 35, 39, 0.75) !important;',
         'background: rgba(17, 36, 51, 0.85) !important;'),
        ('body.dark-mode .pills-group { background: rgba(35, 35, 39, 0.8); }',
         'body.dark-mode .pills-group { background: rgba(17, 36, 51, 0.9); }'),
        ('body.dark-mode .id-copy-strip { background: rgba(35, 35, 39, 0.5); }',
         'body.dark-mode .id-copy-strip { background: rgba(17, 36, 51, 0.6); }'),
        ('body.dark-mode .code-text { background: #232327; border-color: #333338; color: #A1A1AA; }',
         'body.dark-mode .code-text { background: var(--paper); border-color: var(--line); color: var(--ink-soft); }'),
        ('body.dark-mode .btn-copy-chip { background: #232327; border-color: #333338; color: #A1A1AA; }',
         'body.dark-mode .btn-copy-chip { background: var(--paper); border-color: var(--line); color: var(--ink-soft); }'),
        ('body.dark-mode .info-tile { background: rgba(35, 35, 39, 0.8); border-color: #333338; }',
         'body.dark-mode .info-tile { background: rgba(17, 36, 51, 0.9); border-color: var(--line); }'),
        ('body.dark-mode .map-modal-container { background: #232327 !important; color: #E4E4E7 !important; }',
         'body.dark-mode .map-modal-container { background: var(--paper) !important; color: var(--ink) !important; }'),
        ('background-color: rgba(35, 35, 39, 0.85) !important;',
         'background-color: rgba(17, 36, 51, 0.9) !important;'),
    ],
    'resources/views/pharmacy/profile/complete.blade.php': [
        ('body.dark-mode .complete-card { background: #232327; border-color: #333338; }',
         'body.dark-mode .complete-card { background: var(--paper); border-color: var(--line); }'),
        ('body.dark-mode .btn-quick-ghost { border-color: #3f3f46; color: #a1a1aa; }',
         'body.dark-mode .btn-quick-ghost { border-color: var(--line-strong); color: var(--ink-soft); }'),
        ('body.dark-mode .btn-quick-ghost:hover { background: #232327; }',
         'body.dark-mode .btn-quick-ghost:hover { background: var(--line-soft); }'),
        ('body.dark-mode .btn-mini { background: #232327; color: #e4e4e7; }',
         'body.dark-mode .btn-mini { background: var(--paper); color: var(--ink); }'),
        ('body.dark-mode .btn-mini:hover { background: #333338; }',
         'body.dark-mode .btn-mini:hover { background: var(--line-soft); }'),
    ],
    'resources/views/profile/edit.blade.php': [
        ('body.dark-mode .readonly-row { background: #232327; border-color: #3f3f46; color: #e4e4e7; }',
         'body.dark-mode .readonly-row { background: var(--paper); border-color: var(--line-strong); color: var(--ink); }'),
        ('body.dark-mode .avatar-preview-page-card { background: #232327; border-color: #333338; }',
         'body.dark-mode .avatar-preview-page-card { background: var(--paper); border-color: var(--line); }'),
    ],
    # JS chart configs cannot use var() — literal navy
    'resources/views/dashboard/index.blade.php': [
        ("isDarkMode() ? '#232327' : '#0f172a'", "isDarkMode() ? '#112433' : '#0f172a'"),
    ],
    'resources/js/pharmacy_dashboard.js': [
        ("'#232327'", "'#112433'"),
    ],
}

for path, pairs in EDITS.items():
    p = path.replace('/', os.sep)
    s = io.open(p, encoding='utf-8').read()
    for a, b in pairs:
        n = s.count(a)
        if n == 0:
            print('  !! NOT FOUND in %s: %r' % (path, a[:66]))
            continue
        s = s.replace(a, b)
        R[os.path.basename(path)] += n
    io.open(p, 'w', encoding='utf-8', newline='').write(s)

for k, v in sorted(R.items()):
    print('  %-34s %d' % (k, v))
print('total replacements: %d' % sum(R.values()))
