# SONIC PHP

A fan remake of the Master System hedgehog platformer (1991), written in PHP, running on
[JumPHP](https://github.com/davidnurdin/jumPhp): on the desktop and in the browser (PHP compiled to WebAssembly).
Ported from [Sonic PHP](https://github.com/davidnurdin/sonicGamePhp).

**Play it: [jumphp.net/sonic](https://jumphp.net/sonic)** · the complete version, with online multiplayer
(up to 6 players, rooms, chat, replays): [sonic.mimichat.fr](https://sonic.mimichat.fr)

- the six zones and their bosses, the special stages, the ending;
- best times and a ghost of your fastest run (OPTIONS > GHOST);
- local matches against computer runners: race, flag, rings, deathmatch;
- CRT filter, PAL / NTSC speed, English / French, touch controls on phones.

## Controls

| | |
|---|---|
| ← → | run |
| ↑ / ↓ | look up / roll |
| Space | jump |
| Enter | menus, pause |
| Esc | back to the title |

## Run it

The graphics, levels, music and sounds are not in this repository: import them from a checkout of Sonic PHP,
then use JumPHP's commands from the root of the repository:

```bash
games/sonic/tools/import-assets.sh ~/Projets/sonicGamePhp
bin/jumphp install sonic
bin/jumphp run sonic       # desktop
bin/jumphp test sonic      # checks + headless smoke test
bin/jumphp build sonic && bin/jumphp serve sonic    # browser, http://localhost:8080
```

## Credits and disclaimer

Game code: Sonic PHP by David Nurdin. Font: NiseSegaSonic (fan font, by its author). Graphics, levels, music and
sound effects decoded from the original ROM by the Sonic PHP tools.

This is a free, non-commercial fan project. Sonic the Hedgehog, its characters, graphics and music are the
property of SEGA. This project is not affiliated with or endorsed by SEGA.
