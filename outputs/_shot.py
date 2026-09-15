# -*- coding: utf-8 -*-
"""Sample the real pixel colours out of the user's screenshots.

For each image: print the dominant palette and an ASCII map of which palette
entry owns each cell, so the layout can be read as colours instead of guesses.
"""
import sys, collections
from PIL import Image

P = 'C:/Users/MSI/.workbuddy-ai/clipboard-images/'
SHOTS = [
    ('1-quantity-edit', 'clipboard-2026-09-15T09-56-03-557Z-ec2ecb4e.png'),
    ('2-import',        'clipboard-2026-09-15T09-56-03-559Z-828a2741.png'),
    ('3-alternatives',  'clipboard-2026-09-15T09-56-03-561Z-3c4513d6.png'),
    ('4-profile',       'clipboard-2026-09-15T09-56-03-565Z-9fb9d9b4.png'),
    ('5-reviews',       'clipboard-2026-09-15T09-56-03-569Z-4070f37a.png'),
    ('6-availability',  'clipboard-2026-09-15T09-56-03-570Z-96e53a5e.png'),
]

LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'


def quantise(px, step=8):
    return tuple(min(255, (v // step) * step + step // 2) for v in px)


def analyse(name, fn, cols=60):
    im = Image.open(P + fn).convert('RGB')
    w, h = im.size
    rows = max(1, int(cols * h / w / 2.1))
    small = im.resize((cols, rows), Image.BOX)
    px = list(small.getdata())

    counts = collections.Counter(quantise(p) for p in px)
    top = [c for c, _ in counts.most_common(36)]
    if len(top) > len(LETTERS):
        top = top[:len(LETTERS)]

    def nearest(p):
        best, bd = 0, 1 << 30
        for i, t in enumerate(top):
            d = sum((p[k] - t[k]) ** 2 for k in range(3))
            if d < bd:
                bd, best = d, i
        return best

    print('=' * 78)
    print('%s   %dx%d   cells %dx%d' % (name, w, h, cols, rows))
    print('=' * 78)
    print('palette:')
    for i, t in enumerate(top[:22]):
        pct = 100.0 * counts[t] / len(px)
        print('  %s  #%02X%02X%02X  %5.1f%%' % (LETTERS[i], t[0], t[1], t[2], pct))
    print()
    print('map (each cell = %s):' % 'the nearest palette entry')
    for r in range(rows):
        line = ''.join(LETTERS[nearest(px[r * cols + c])] for c in range(cols))
        print('  ' + line)
    print()


if __name__ == '__main__':
    want = sys.argv[1:] or [s[0] for s in SHOTS]
    for name, fn in SHOTS:
        if name in want:
            analyse(name, fn)
