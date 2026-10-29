#!/usr/bin/env bash
# Rebuilds games/<this game>/assets/ from a checkout of the original Sonic PHP project
# (https://github.com/davidnurdin/sonicGamePhp): its graphics, levels, music and sound effects are not in this
# repository (they come from the Master System ROM; on the playground they are private files).
#
#   games/sonic/tools/import-assets.sh ~/Projets/sonicGamePhp
#
# Copies what the port uses, under the names the port uses (no brand names in file names), and leaves out what
# it does not use (the online / site parts, the unused tilesets, the raw sound captures).
# assets/thumbnail.png is not part of it (a screenshot of the port).
set -euo pipefail

src="${1:?usage: $0 <path of the original Sonic PHP checkout>}"
src="${src%/}/assets"
dst="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/assets"
[ -d "$src/tileset" ] || { echo "no assets/tileset in ${1}" >&2; exit 1; }

mkdir -p "$dst"
for dir in levels screens tileset mixer/music mixer/sfx; do
  mkdir -p "$dst/$dir"
  cp -a "$src/$dir/." "$dst/$dir/"
done
mkdir -p "$dst/fonts"
cp -a "$src/fonts/NiseSegaSonic.TTF" "$dst/fonts/nise.ttf"

# not used by the port
rm -f "$dst/mixer/music/level1.mp3" "$dst/tileset/levels/test.png"
for n in 13 17 20 21 22 23 24 25 28 29 30; do rm -f "$dst/tileset/levels/tileset$n.png"; done

# the names of the port
for f in map1 map2 title; do mv -f "$dst/screens/$f-sonic.png" "$dst/screens/$f-hero.png"; done
cd "$dst/tileset/sprites"
for f in tileset-eggman*.png; do mv -f "$f" "${f/eggman/doctor}"; done
for f in tileset-sonic*.png tileset-rom-sonic*.png; do mv -f "$f" "${f/sonic/hero}"; done

echo "assets imported into $dst ($(find "$dst" -type f | wc -l) files)"
