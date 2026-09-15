# -*- coding: utf-8 -*-
"""Full zinc-palette sweep (pass 2) — explicit, per-occurrence.

Zinc is a *neutral grey* dark palette that was banned from tokens.css but
survived as a **local palette override** inside Blade <style> blocks and a few
page CSS files.  Result: those pages render grey while the rest of the app is
navy.  Inside <style>/CSS we swap to var(); inside JS chart configs (which
cannot read var()) we swap to the resolved dark value.
"""
import io, os, collections

R = collections.Counter()

EDITS = {
    'resources/views/pharmacies/index.blade.php': [
        ('--bg-body: #18181B;', '--bg-body: var(--canvas);'),
        ('--border-color: #333338;', '--border-color: var(--line);'),
        ('--text-main: #E4E4E7;', '--text-main: var(--ink);'),
        ('--text-muted: #A1A1AA;', '--text-muted: var(--ink-soft);'),
        ('border: 1px solid rgba(51, 51, 56, 0.8) !important;',
         'border: 1px solid rgba(42, 71, 97, 0.8) !important;'),
        ('inset 0 1px 1px 0 rgba(51, 51, 56, 0.9) !important;',
         'inset 0 1px 1px 0 rgba(42, 71, 97, 0.9) !important;'),
        ('background: linear-gradient(135deg, #E4E4E7, #A1A1AA);',
         'background: linear-gradient(135deg, var(--ink), var(--ink-soft));'),
        ('body.dark-mode .grid-count-tag { background: #333338; color: var(--text-muted); }',
         'body.dark-mode .grid-count-tag { background: var(--line-soft); color: var(--text-muted); }'),
        ('body.dark-mode .btn-show-map { background: rgba(161, 161, 170, 0.15); color: #E4E4E7; border-color: rgba(161, 161, 170, 0.3); }',
         'body.dark-mode .btn-show-map { background: var(--line-soft); color: var(--ink); border-color: var(--line-strong); }'),
        ('body.dark-mode .btn-external-gmaps { background: #333338; color: #E4E4E7; }',
         'body.dark-mode .btn-external-gmaps { background: var(--line-soft); color: var(--ink); }'),
        ('body.dark-mode .close-btn-secondary { background: #3F3F46; color: #E4E4E7; }',
         'body.dark-mode .close-btn-secondary { background: var(--line-soft); color: var(--ink); }'),
        ('color: #E4E4E7 !important;', 'color: var(--ink) !important;'),
        ('border-color: #333338 !important;', 'border-color: var(--line) !important;'),
    ],
    'resources/views/pharmacy/profile/complete.blade.php': [
        ('body.dark-mode .complete-col { background: #18181B; border-color: #333338; }',
         'body.dark-mode .complete-col { background: var(--paper); border-color: var(--line); }'),
        ('body.dark-mode .complete-hero { border-color: #3f3f46; }',
         'body.dark-mode .complete-hero { border-color: var(--line-strong); }'),
        ('body.dark-mode .complete-security-head { border-color: #3f3f46; }',
         'body.dark-mode .complete-security-head { border-color: var(--line-strong); }'),
        ('body.dark-mode .complete-security-head p { color: #a1a1aa; }',
         'body.dark-mode .complete-security-head p { color: var(--ink-soft); }'),
        ('body.dark-mode .complete-card-head { border-color: #333338; }',
         'body.dark-mode .complete-card-head { border-color: var(--line); }'),
        ('body.dark-mode .complete-map { border-color: #333338; }',
         'body.dark-mode .complete-map { border-color: var(--line); }'),
        ('body.dark-mode .complete-day-row { border-color: #333338; }',
         'body.dark-mode .complete-day-row { border-color: var(--line); }'),
        ('body.dark-mode .complete-day-label { color: #E4E4E7; }',
         'body.dark-mode .complete-day-label { color: var(--ink); }'),
        ('body.dark-mode .hint-under { color: #71717A; }',
         'body.dark-mode .hint-under { color: var(--ink-faint); }'),
    ],
    'resources/views/profile/edit.blade.php': [
        ('body.dark-mode .profile-col { background: #18181B; border-color: #333338; }',
         'body.dark-mode .profile-col { background: var(--paper); border-color: var(--line); }'),
        ('body.dark-mode .profile-hero { border-color: #3f3f46; }',
         'body.dark-mode .profile-hero { border-color: var(--line-strong); }'),
        ('body.dark-mode .btn-ghost { border-color: #3f3f46; color: #e4e4e7; }',
         'body.dark-mode .btn-ghost { border-color: var(--line-strong); color: var(--ink); }'),
        ('body.dark-mode .security-head { border-color: #3f3f46; }',
         'body.dark-mode .security-head { border-color: var(--line-strong); }'),
        ('body.dark-mode .security-head p { color: #a1a1aa; }',
         'body.dark-mode .security-head p { color: var(--ink-soft); }'),
        ('body.dark-mode .avatar-preview-page-head { border-color: #333338; }',
         'body.dark-mode .avatar-preview-page-head { border-color: var(--line); }'),
        ('body.dark-mode .avatar-preview-page-actions { background: #18181B; border-color: #333338; }',
         'body.dark-mode .avatar-preview-page-actions { background: var(--paper); border-color: var(--line); }'),
    ],
    # --- JS chart configs: var() is not available, use the resolved dark value
    'resources/views/dashboard/index.blade.php': [
        ("isDarkMode() ? '#333338' : '#e2e8f0'", "isDarkMode() ? '#3A6383' : '#e2e8f0'"),
        ("isDarkMode() ? '#A1A1AA' : '#64748b'", "isDarkMode() ? '#93C5FD' : '#64748b'"),
        ("titleColor: '#E4E4E7',", "titleColor: '#EAF4F8',"),
        ("bodyColor: '#E4E4E7',", "bodyColor: '#EAF4F8',"),
    ],
    'resources/js/pharmacy_dashboard.js': [
        ("? '#A1A1AA'", "? '#93C5FD'"),
        ("? '#18181B'", "? '#112433'"),
    ],
    # --- plain CSS
    'resources/css/pages/inventory.css': [
        ('background-color: rgba(161, 161, 170, 0.1);',
         'background-color: var(--line-soft);'),
    ],
    'resources/css/pages/settings.css': [
        ('box-shadow: 0 0 0 3px rgba(161, 161, 170, 0.15);',
         'box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.28);'),
    ],
    'resources/css/pages/pharmacies.css': [
        ('background: rgba(51, 51, 56, 0.6);',
         'background: rgba(42, 71, 97, 0.6);'),
    ],
}

for path, pairs in EDITS.items():
    p = path.replace('/', os.sep)
    s = io.open(p, encoding='utf-8').read()
    for a, b in pairs:
        n = s.count(a)
        if n == 0:
            print('  !! NOT FOUND in %s: %r' % (path, a[:70]))
            continue
        s = s.replace(a, b)
        R[path] += n
    io.open(p, 'w', encoding='utf-8', newline='').write(s)

for k, v in sorted(R.items()):
    print('  %-58s %d' % (k, v))
print('total replacements: %d' % sum(R.values()))
