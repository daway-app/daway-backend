# -*- coding: utf-8 -*-
"""Vertical / horizontal scan-lines: print every colour change along a line."""
import sys
from PIL import Image

P = 'C:/Users/MSI/.workbuddy-ai/clipboard-images/'
FILES = {
    '1': 'clipboard-2026-09-15T09-56-03-557Z-ec2ecb4e.png',
    '2': 'clipboard-2026-09-15T09-56-03-559Z-828a2741.png',
    '3': 'clipboard-2026-09-15T09-56-03-561Z-3c4513d6.png',
    '4': 'clipboard-2026-09-15T09-56-03-565Z-9fb9d9b4.png',
    '5': 'clipboard-2026-09-15T09-56-03-569Z-4070f37a.png',
    '6': 'clipboard-2026-09-15T09-56-03-570Z-96e53a5e.png',
}


def hx(p):
    return '#%02X%02X%02X' % p[:3]


def scan(img, axis, fixed, lo, hi, thr=10, minrun=3):
    """Walk along `axis` at the other coordinate `fixed`; report flat runs."""
    out = []
    prev = None
    start = lo
    for i in range(lo, hi):
        p = img.getpixel((i, fixed)) if axis == 'x' else img.getpixel((fixed, i))
        if prev is None:
            prev, start = p, i
            continue
        if max(abs(p[k] - prev[k]) for k in range(3)) > thr:
            if i - start >= minrun:
                out.append((start, i - 1, hx(prev)))
            prev, start = p, i
    if hi - start >= minrun:
        out.append((start, hi - 1, hx(prev)))
    return out


def main():
    key = sys.argv[1]
    img = Image.open(P + FILES[key]).convert('RGB')
    w, h = img.size
    print('image %s  %dx%d' % (key, w, h))
    for spec in sys.argv[2:]:
        kind, fixed, lo, hi = spec.split(':')
        fixed, lo, hi = int(fixed), int(lo), int(hi)
        print('\n--- %s-scan at %s=%d  (%d..%d) ---'
              % ('horizontal' if kind == 'y' else 'vertical',
                 'y' if kind == 'y' else 'x', fixed, lo, hi))
        for a, b, c in scan(img, 'x' if kind == 'y' else 'y', fixed, lo, hi):
            print('   %s %4d..%-4d  %s   (%d px)'
                  % ('x' if kind == 'y' else 'y', a, b, c, b - a + 1))


main()
