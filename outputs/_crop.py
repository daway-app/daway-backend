# -*- coding: utf-8 -*-
"""Crop + upscale regions of the screenshots so they can be inspected visually.

usage: python outputs/_crop.py <image-key> <name>:<l>:<t>:<r>:<b>:<zoom> ...
"""
import sys, os
from PIL import Image

P = 'C:/Users/MSI/.workbuddy-ai/clipboard-images/'
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
key = sys.argv[1]
img = Image.open(P + FILES[key]).convert('RGB')
W, H = img.size
print('image %s is %dx%d' % (key, W, H))

for spec in sys.argv[2:]:
    name, l, t, r, b, z = spec.split(':')
    box = (int(l), int(t), int(r), int(b))
    z = float(z)
    c = img.crop(box)
    c = c.resize((int(c.width * z), int(c.height * z)), Image.NEAREST)
    path = OUT + '%s-%s.png' % (key, name)
    c.save(path)
    print('  %-24s %s  ->  %dx%d' % (name, box, c.width, c.height))
