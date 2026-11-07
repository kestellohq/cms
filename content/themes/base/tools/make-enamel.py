"""Generates the base theme's enamel assets (dev-only; outputs are committed).

    python3 -I content/themes/base/tools/make-enamel.py

enamel.webp greyscale soft-light overlay: domed surface with a specular band,
            a darker rolled rim and a faint orange-peel texture. 50% grey is
            neutral under `background-blend-mode: soft-light`, so the plate's
            own colour token still decides the enamel colour.
screw.webp  one slotted, domed screw head with its contact shadow (alpha),
            48 px for 24 px at 2x; the stylesheet rotates it per corner.
Requires Pillow. Deterministic (fixed seed).
"""
import math, random, pathlib
from PIL import Image, ImageFilter

OUT = pathlib.Path(__file__).resolve().parent.parent / 'assets' / 'img'
random.seed(7)

def enamel(w=640, h=640):
    img = Image.new('L', (w, h))
    px = img.load()
    for y in range(h):
        for x in range(w):
            u, v = x / (w - 1) * 2 - 1, y / (h - 1) * 2 - 1
            # dome: a gentle lift toward the centre
            r = max(abs(u), abs(v))
            dome = 10 * (1 - r ** 2)
            # specular band: diagonal, upper-left of centre, soft edges
            d = (u * 0.55 + v * 0.83 + 0.38)
            band = 34 * math.exp(-(d / 0.16) ** 2) + 12 * math.exp(-(d / 0.42) ** 2)
            # rolled rim: darkens over the last ~5% of each edge
            edge = min(1 - abs(u), 1 - abs(v))
            rim = -46 * max(0.0, 1 - edge / 0.05) ** 1.6
            px[x, y] = max(0, min(255, int(128 + dome + band + rim)))
    peel = Image.effect_noise((w, h), 26).filter(ImageFilter.GaussianBlur(1.4))
    peel = peel.point(lambda p: 128 + (p - 128) * 0.35)
    return Image.blend(img, peel, 0.18).filter(ImageFilter.GaussianBlur(0.6))

def screw(size=48, ss=4):
    S = size * ss
    img = Image.new('RGBA', (S, S), (0, 0, 0, 0))
    px = img.load()
    c, R = S / 2, S * 0.36
    for y in range(S):
        for x in range(S):
            dx, dy = x - c, y - c
            dist = math.hypot(dx, dy)
            # contact shadow, offset down-right
            sd = math.hypot(dx - S * 0.03, dy - S * 0.05)
            if dist > R:
                a = max(0.0, 1 - (sd - R) / (S * 0.08)) if sd < R + S * 0.08 else 0
                px[x, y] = (0, 0, 0, int(90 * a ** 2))
                continue
            nx, ny = dx / R, dy / R
            nz = math.sqrt(max(0.0, 1 - (nx * nx + ny * ny) * 0.82))
            light = max(0.0, -0.45 * nx - 0.6 * ny + 0.66 * nz)
            spec = max(0.0, light) ** 18
            base = 112 + 118 * light + 70 * spec
            if dist > R * 0.9:  # bevelled rim
                base *= 0.72
            # slot: horizontal channel, lit on its lower wall
            if abs(dy) < R * 0.13 and abs(dx) < R * 0.86:
                base = 58 + (70 if dy > R * 0.05 else 0)
            v = int(max(0, min(255, base)))
            px[x, y] = (v, v, min(255, v + 6), 255)
    return img.resize((size, size), Image.LANCZOS)

OUT.mkdir(parents=True, exist_ok=True)
enamel().save(OUT / 'enamel.webp', quality=78, method=6)
screw().save(OUT / 'screw.webp', lossless=True, method=6)
print('wrote', OUT / 'enamel.webp', OUT / 'screw.webp')
