# -*- coding: utf-8 -*-
"""Print the exact colour at named points of the 1080-wide screenshots."""
import sys
from PIL import Image

S = 'outputs/_shots/norm-%s.png'

PTS = {
    '3': [
        ('page-bg',            540,  40),
        ('stat-card-bg',       300, 127),
        ('stat-card-edge-top', 300, 108),
        ('stat-card-edge-bot', 300, 148),
        ('stat-badge-yellow',  480, 127),
        ('accordion-head-bg',  540, 210),
        ('table-head-bg',      300, 253),
        ('row-bg',             300, 285),
        ('empty-text',         540, 315),
    ],
    '4': [
        ('page-bg',            540, 330),
        ('banner-left',        340, 140),
        ('banner-mid',         600, 140),
        ('banner-right',       950, 120),
        ('field-bg',           600, 300),
        ('field-bg2',          600, 345),
        ('card-bg-side',       140, 300),
        ('label-text',         900, 258),
    ],
    '5': [
        ('page-bg',            540, 300),
        ('card-bg',            300, 120),
        ('bar-black',          620, 112),
        ('bar-black2',         620, 155),
        ('bar-track',          700, 112),
        ('chart-area',         200, 160),
        ('chart-grid',         200, 205),
        ('big-number',         900,  75),
    ],
    '6': [
        ('page-bg',            60, 200),
        ('card-bg',            300, 300),
        ('donut-ring',         660, 200),
        ('donut-center',       655, 196),
        ('donut-hole',         655, 150),
        ('legend-green',       500, 148),
    ],
    '2': [
        ('page-bg',            540, 300),
        ('card-bg',            540, 150),
        ('step-active',        160,  95),
        ('step-inactive',      410,  95),
        ('dropzone',           540, 490),
        ('dropzone-edge',      540, 430),
        ('btn-disabled',       540, 529),
        ('btn-primary',        960, 229),
        ('btn-outline',        770, 229),
    ],
    '1': [
        ('page-bg',            540,  10),
        ('card-bg',            540, 120),
        ('table-head',         300, 165),
        ('row-bg',             300, 210),
        ('stepper-box',        270, 233),
        ('stepper-btn',        200, 233),
        ('chip-active',         60,  29),
        ('chip-idle',          120,  29),
        ('badge-ok',           290, 233),
    ],
}

for key, pts in PTS.items():
    img = Image.open(S % key).convert('RGB')
    print('--- image %s (%dx%d) ---' % (key, img.width, img.height))
    for name, x, y in pts:
        if 0 <= x < img.width and 0 <= y < img.height:
            print('  %-20s (%4d,%4d)  #%02X%02X%02X'
                  % (name, x, y, *img.getpixel((x, y))))
        else:
            print('  %-20s (%4d,%4d)  OUT OF RANGE' % (name, x, y))
    print()
