#!/usr/bin/env python3
"""Builds the pictures of the web site (site/images/) from the headless screenshots of tools/site/shots.sh.

The screenshots are the game's window, 1680 x 852: the 840 x 426 view drawn x2 (halved here pixel for pixel),
with the menus, the map and the versus scores drawn at the window's size (smoothed when halved).

    games/sonic/tools/site/shots.sh                                # screenshots, then pictures
    python3 -I games/sonic/tools/site/make-images.py games/sonic    # the pictures again
"""
import sys
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFont

GAME = Path(sys.argv[1] if len(sys.argv) > 1 else '.').resolve()
SHOTS = GAME / 'var/site'
OUT = GAME / 'site/images'
FONT = GAME / 'assets/fonts/nise.ttf'

GAMEPLAY = ['level-1', 'level-4', 'level-7', 'level-10', 'level-13', 'level-16', 'special']
WINDOW_TEXT = ['title', 'map', 'versus']
YELLOW = (255, 216, 0)


def shot(name):
    return Image.open(SHOTS / f'{name}.png').convert('RGB')


def half(image, smooth=False):
    return image.resize((image.width // 2, image.height // 2), Image.LANCZOS if smooth else Image.NEAREST)


def without_bands(image):
    """A view whose camera is locked (a boss arena) has black bands: cut them, then back to the view's ratio."""
    _, top, _, bottom = image.point(lambda v: 255 if v > 12 else 0).getbbox()
    width = round((bottom - top) * image.width / image.height)
    left = (image.width - width) // 2
    return image.crop((left, top, left + width, bottom))


def content(image, box):
    """The bounding box of what is not the background colour (the top-left pixel) in a part of a picture."""
    part = image.crop(box)
    background = Image.new('RGB', part.size, part.getpixel((0, 0)))
    x0, y0, x1, y1 = ImageChops.difference(part, background).convert('L').point(lambda v: 255 if v > 24 else 0).getbbox()
    return part.crop((x0, y0, x1, y1))


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    for name in GAMEPLAY:
        half(shot(name)).save(OUT / f'{name}.png', optimize=True)
    for name in WINDOW_TEXT:
        half(shot(name), smooth=True).save(OUT / f'{name}.png', optimize=True)
    without_bands(half(shot('boss'))).save(OUT / 'boss.png', optimize=True)

    # icons: the lives icon of the map screen (the hero's head in its white box: 16 x 16 pixels drawn x4)
    head = shot('map').crop((360, 714, 424, 778)).resize((16, 16), Image.NEAREST)
    for size, name in [(32, 'favicon.png'), (180, 'apple-touch-icon.png'), (512, 'icon-512.png')]:
        scale = size // 16
        icon = Image.new('RGB', (size, size), (255, 255, 255))
        icon.paste(head.resize((16 * scale, 16 * scale), Image.NEAREST), ((size - 16 * scale) // 2,) * 2)
        icon.save(OUT / name, optimize=True)

    # the sharing picture (Open Graph / Twitter), 1200 x 630: Green Hill on the left (x2), the title on the right
    og = Image.new('RGB', (1200, 630), (0, 0, 0))
    level = half(shot('level-1'))
    og.paste(level.crop((420, 111, 720, 426)).resize((600, 630), Image.NEAREST), (0, 0))
    logo = content(shot('title'), (0, 0, 1680, 535))
    logo = logo.resize((540, round(logo.height * 540 / logo.width)), Image.LANCZOS)
    og.paste(logo, (600 + 30, 40))
    draw = ImageDraw.Draw(og)
    font = ImageFont.truetype(str(FONT), 34) if FONT.exists() else ImageFont.load_default()
    for i, line in enumerate(['JOUABLE EN LIGNE', 'EN PHP, GRATUIT']):
        width = draw.textlength(line, font=font)
        draw.text((900 - width / 2 + 3, 40 + logo.height + 30 + i * 52 + 3), line, font=font, fill=(160, 0, 0))
        draw.text((900 - width / 2, 40 + logo.height + 30 + i * 52), line, font=font, fill=YELLOW)
    og.save(OUT / 'og.png', optimize=True)
    print('images:', ', '.join(sorted(p.name for p in OUT.iterdir())))


main()
