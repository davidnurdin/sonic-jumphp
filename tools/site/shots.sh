#!/bin/sh
# The screenshots of the web site, taken headless (var/site/*.png), then the pictures (site/images/).
# Run from the root of the JumPHP project: games/sonic/tools/site/shots.sh (assets/ must be there:
# tools/import-assets.sh)
set -eu
cd "$(dirname "$0")/../../../.."
game=sonic
shot() { name="$1" frames="$2"; shift 2; env "$@" bin/jumphp shot "$game" "$frames" "var/site/$name.png" >/dev/null; }
act() { shot "$1" "$2" JUMPHP_SCENE='App\Scene\GameScene' JUMPHP_SCENE_PARAMS="$3"; }

mkdir -p "games/$game/var/site"
shot title 60
shot map 200 JUMPHP_INPUT='[[30,"start"]]'
# the first act of each zone, the computer playing (levels 1-18: six zones x 3 acts), a boss, a special stage
for level in 1 4 7 10 13 16; do
    act "level-$level" 400 "{\"level\":$level,\"bot\":true}"
done
act boss 1200 '{"level":3,"bot":true}'
act special 400 '{"level":28,"bot":true}'
act versus 420 '{"versus":"race","level":1,"players":4,"bot":true}'
[ -n "${SKIP_IMAGES:-}" ] || python3 -I "games/$game/tools/site/make-images.py" "games/$game"
