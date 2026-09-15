# -*- coding: utf-8 -*-
"""Normalise every screenshot to 1080px wide, then crop by DISPLAY coords."""
import sys, os
from PIL import Image

P = 'C:/Users/MSI/.workbuddy-ai/clipboard-images/'
NORM = 'outputs/_shots/norm-'
OUT = 'outputs/_shots/'
FILES = {
    '1': 'clipboard-2026-09-15T09-56-03-557Z-ec2ecb4e.png',
    '2': 'clipboard-2026-09-15T09-56-03-559Z-828a2741.png',
    '3': 'clipboard-2026-09-15T09-56-03-561Z-3c4513d6.png',
    '4': 'clipboard-2026-09-15T09-56-03-565Z-9fb9d9b4.png',
    '5': 'clipboard-2026-09-15T09-56-03-569Z-4070f37a.png',
    '6': 'clipboard-2026-09-15T09-56-03-570Z-96e53a5e.png',
}
os.makedirs(OUT, exist_ok=True)


def norm(key):
    img = Image.open(P + FILES[key]).convert('RGB')
    if img.width != 1080:
        h = int(round(img.height * 1080.0 / img.width))
        img = img.resize((1080, h), Image.LANCZOS)
    path = NORM + key + '.png'
    img.save(path)
    return img, path


if __name__ == '__main__':
    key = sys.argv[1]
    img, path = norm(key)
    print('normalised %s -> %s  %dx%d' % (key, path, img.width, img.height))
    for spec in sys.argv[2:]:
        name, l, t, r, b, z = spec.split(':')
        l, t, r, b = int(l), int(t), min(int(r), img.width), min(int(b), img.height)
        c = img.crop((l, t, r, b))
        c = c.resize((int(c.width * float(z)), int(c.height * float(z))), Image.LANCZOS)
        c.save(OUT + '%s-%s.png' % (key, name))
        print('  %-18s (%d,%d,%d,%d) -> %dx%d' % (name, l, t, r, b, c.width, c.height))
