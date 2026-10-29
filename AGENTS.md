# sonic — notes for agents

This game is built with JumPHP. **Read `../../AGENTS.md` first** (rules, commands, API).

**SONIC PHP** is a fan remake of the Master System hedgehog platformer (1991), ported onto JumPHP from the
original Sonic PHP project (https://github.com/davidnurdin/sonicGamePhp, namespace `SonicGame\`, its own SDL
loop). The game logic (physics, objects, badniks, bosses, camera, screens, bots) is the original's, moved to
`App\` and driven by JumPHP; only the platform layer was replaced.

## Assets: not in the repository

`assets/` (graphics, levels, music, sound effects, the font: ~820 files, 19 MB) comes from the ROM and from the
original project: it is **gitignored** (private files on the playground). Rebuild it from a checkout of the
original project:

```bash
games/sonic/tools/import-assets.sh ~/Projets/sonicGamePhp    # copies + renames what the port uses
```

`assets/thumbnail.png` (a screenshot of the port, 1280x720 = 320x180 x4) is not made by the script either.

## Checklist

```bash
bin/jumphp install sonic
bin/jumphp test sonic                 # php -l + phpstan + smoke test (the computer runs Green Hill 1)
bin/jumphp shot sonic 60              # title -> games/sonic/var/screenshots/shot.png
JUMPHP_SCENE='App\Scene\GameScene' JUMPHP_SCENE_PARAMS='{"level":7,"bot":true}' bin/jumphp shot sonic 400 var/screenshots/jungle.png
JUMPHP_SCENE='App\Scene\GameScene' JUMPHP_SCENE_PARAMS='{"versus":"race","level":1,"players":4,"bot":true}' bin/jumphp shot sonic 420 var/screenshots/versus.png
bin/jumphp build:remote sonic && JUMPHP_WEB=remote bin/jumphp webshot sonic 8 '[[1,"Enter"],[3,"Enter"]]'
bin/jumphp build sonic && bin/jumphp webshot sonic 6 '[[1,"Enter"]]'
```

`GameScene` params (tests, shots): `level` 1-35 (a new game straight in that act: 1-18 the six zones x 3 acts,
19 the ending, 28-35 the special stages), `bot: true` (the computer plays: the F7 autoplay), `versus`
(`race|flag|rings|deathmatch`) with `level` and `players` (2-5; flag: 2-6), `demo: true` (the title demo).

## Structure

- `src/Scene/GameScene.php` — the only real JumPHP scene: builds the game objects (what the original's service
  container did), each frame `Pad::poll()`, `Game::frame($dt)`, `Game::updateView()`, draws with `Game::render()`,
  and registers `Game::renderWindow()` as a JumPHP **renderer overlay** (drawn at window resolution, after JumPHP
  scaled the view: the title, menus, tallies, the versus scores, the CRT filter, the debug panels).
- `src/Scene/PauseScene.php` — overlay pushed by Enter / Start during an act, or by JumPHP when the page /
  window is hidden (`game.yaml pause_scene`, `pause_on_blur: false`). GameScene freezes, `Mix_PauseMusic`.
  Enter / Space: resume; Escape: back to the title.
- `src/Game.php` — the original game class: events between the parts, the frame (PAL time scale, physics steps of
  at most 1/120 s, objects, versus, camera, music), the input (the original's key handler on `Pad`), demo,
  autoplay, ghosts, best times, debug keys.
- `src/Gfx/View.php` — the original Renderer + Window on JumPHP's `Renderer`: the view is JumPHP's view texture,
  **resized like the original** (`Game::updateView` -> `Renderer::resizeView`): about 426 px high, as wide as the
  window ratio (keypad `+`/`-` zoom, keypad `*` the 256x192 SMS screen). `View::current()` replaces the original
  `Window::getInstance()`.
- `src/Gfx/Gfx.php` — textures by name over JumPHP's `Sdl`: lazy per-level groups shared by file (the 35 levels'
  blocks are ~200 MB decoded), precomputed slices (`name-0.png`...) for pictures wider than 4064 px, the `-water`
  / `-flash` variants, the TTF digits of the debug view.
- `src/Gfx/TextRenderer.php`, `CrtFilter.php` — the original's text (TTF font `assets/fonts/nise.ttf`) and filter.
- `src/Audio/SoundManager.php` — the original sound API (driver music / sfx indexes) on JumPHP's `SoundManager`:
  `{song}-intro.ogg` + `{song}.ogg` chained by `loadMusic($name, $loop, $intro)`, speed shoes switching to
  `{song}-fast` at the same place of the song (`mixer/music/timing.json`).
- `src/Input/Pad.php` — the joypad from JumPHP actions (`config/input.yaml`): held / pressed / released, the
  last direction pressed, raw keys for F1-F7 / keypad / name entry letters.
- `src/Save/` — `Options` on JumPHP `Options` (`var/options.json`), `BestTimes` and `GhostRecorder` on JumPHP
  `Storage` (`var/besttimes.json`, `var/ghosts/level{N}.json.gz`; IndexedDB in the browser).
- `src/Util/Lang.php` — the original's static Lang over JumPHP `Lang`: texts are French in the code,
  `config/lang.yaml` has the English table; OPTIONS > LANGUAGE (AUTO = English unless the system says French).
- `src/Stage/` (was the original `Scene/`: Stage, Camera, HUD, Screens, TileSet), `src/Entities/`, `src/Level/`,
  `src/Multiplayer/` (Versus, Rival, BotBrain, BotRoute): the original code, renamed.

## Controls (`config/input.yaml`, `game.yaml touch`)

Arrows: run / look up / roll (down). Space (pad A/B/X/Y, tap): jump. Enter (pad Start): menus, pause.
Escape (pad Select): back / back to the title (quits on the desktop from the main menu).
Original debug keys kept: F1/F2 collision views, F3/F4 previous / next act, F5 god mode, F6 coordinates, F7
autoplay, keypad 2/4/6/8 free camera, 5 carry Sonic, +/- zoom, * SMS screen. JumPHP's own keys moved:
debug overlay F8, fullscreen F12.

## Differences from the original

- Removed: **online multiplayer** (relay / WebRTC rooms, chat), the web site features (site levels, rooms,
  accounts, stats, replays and their viewer). Their menu entries are gone. Local versus against bots is kept
  (4 modes, 2-5 runners, AI levels).
- Added: a pause (the original had none; Enter only chatted online).
- Brand words are out of identifiers and file names (sonic -> hero, eggman / robotnik -> doctor, texture names,
  option key `versusRunners`); the texts on screen are unchanged.

## Non-obvious

- The engine's view size changes at run time: never cache `$r->getWidth()`; read `View`.
- `GameObjectManager` and `Level` still have the original's (inert) hooks for replays / online
  (`objectListener`, `replayPuppet`...): nothing sets them.
- phpstan ignores a few identifiers of the original code style (`phpstan.neon`), e.g. `new \SDL_Rect` built empty.
- `bin/jumphp lint` reports forbidden words for this game (its title and comments): expected, accepted.
