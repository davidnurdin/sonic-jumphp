<?php

namespace App\Save;

use App\Util\Lang;
use JumPHP\Save\Options as EngineOptions;

/**
 * Options of the title menu, kept in JumPHP's options (var/options.json, IndexedDB in the browser): the ghost,
 * the CRT filter, the speed (PAL / NTSC), the cheats (all off by default), the matches against the computer and
 * the language (JumPHP's own 'lang' option).
 */
class Options
{
    /** Cheats of the options > cheats page: key => label. */
    public const CHEATS = [
        'invincible' => 'INVINCIBLE',
        'lives' => 'VIES INFINIES',
        'continues' => 'CONTINUES INFINIS',
        'rings' => '50 ANNEAUX',
        'turbo' => 'VITESSE TURBO',
        'shield' => 'BOUCLIER PERMANENT',
        'emeralds' => '6 EMERAUDES',
    ];

    /** The game's options and their defaults (kept in JumPHP's options, var/options.json, with the engine's ones). */
    private const DEFAULTS = ['ghost' => false, 'crt' => false, 'speed' => 'pal', 'cheats' => [], 'startLevel' => 1,
        'versusMode' => 'race', 'versusRunners' => 3, 'versusTeam' => 2, 'versusLevel' => 1, 'versusAi' => 1];

    private array $values;

    public function __construct(private EngineOptions $options)
    {
        $this->values = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            $this->values[$key] = $options->get($key, $default);
        }
    }

    public function isGhostEnabled(): bool
    {
        return (bool)$this->values['ghost'];
    }

    public function setGhostEnabled(bool $on): void
    {
        $this->values['ghost'] = $on;
        $this->save();
    }

    /** CRT filter: scanlines and darker corners over the picture, like an old TV. */
    public function isCrtEnabled(): bool
    {
        return (bool)$this->values['crt'];
    }

    public function setCrtEnabled(bool $on): void
    {
        $this->values['crt'] = $on;
        $this->save();
    }

    /**
     * 'pal': the European Master System, 50 frames per second -> the whole game runs at 50/60 of the
     * speed of the Japanese / American one (same ROM, one frame of logic per frame); 'ntsc': 60 Hz.
     */
    public function getSpeed(): string
    {
        return $this->values['speed'] === 'ntsc' ? 'ntsc' : 'pal';
    }

    public function setSpeed(string $speed): void
    {
        $this->values['speed'] = $speed === 'ntsc' ? 'ntsc' : 'pal';
        $this->save();
    }

    /** Game time per real second: 50/60 in PAL, 1 in NTSC. */
    public function getTimeScale(): float
    {
        return $this->getSpeed() === 'pal' ? 50 / 60 : 1.0;
    }

    public function isCheatOn(string $cheat): bool
    {
        return !empty($this->values['cheats'][$cheat]);
    }

    public function toggleCheat(string $cheat): void
    {
        $this->values['cheats'][$cheat] = !$this->isCheatOn($cheat);
        $this->save();
    }

    /** @return array<string, bool> every cheat with its state */
    public function getCheats(): array
    {
        $cheats = [];
        foreach (array_keys(self::CHEATS) as $cheat) {
            $cheats[$cheat] = $this->isCheatOn($cheat);
        }
        return $cheats;
    }

    /** Cheat "start at": the act a new game starts with (1-18), 1 = normal game. */
    public function getStartLevel(): int
    {
        return max(1, min(18, (int)$this->values['startLevel']));
    }

    public function setStartLevel(int $level): void
    {
        $this->values['startLevel'] = max(1, min(18, $level));
        $this->save();
    }

    /** Multiplayer menu: the mode (Versus::MODES), the number of Sonics (2-5; flag: players per team, 1-3), the act. */
    public function getVersusMode(): string
    {
        return in_array($this->values['versusMode'], ['race', 'flag', 'rings', 'deathmatch'], true) ? $this->values['versusMode'] : 'race';
    }

    public function getVersusPlayers(): int
    {
        return max(2, min(5, (int)$this->values['versusRunners']));
    }

    /** Flag mode: Sonics per team (1 against 1 ... 3 against 3). */
    public function getVersusTeamSize(): int
    {
        return max(1, min(3, (int)$this->values['versusTeam']));
    }

    public function setVersusTeamSize(int $size): void
    {
        $this->values['versusTeam'] = max(1, min(3, $size));
        $this->save();
    }

    /** Multiplayer: difficulty of the computer Sonics (0 easy, 1 medium, 2 hard: BotBrain::DIFFICULTIES). */
    public function getVersusDifficulty(): int
    {
        return max(0, min(2, (int)$this->values['versusAi']));
    }

    public function setVersusDifficulty(int $difficulty): void
    {
        $this->values['versusAi'] = ($difficulty % 3 + 3) % 3;
        $this->save();
    }

    /** '' (automatic: Lang::detect), 'fr' or 'en'. */
    public function getLang(): string
    {
        $lang = $this->options->get('lang');
        return in_array($lang, ['fr', 'en'], true) ? $lang : '';
    }

    public function setLang(string $lang): void
    {
        $this->options->set('lang', in_array($lang, ['fr', 'en'], true) ? $lang : null);
    }

    public function getVersusLevel(): int
    {
        return (int)$this->values['versusLevel'] ?: 1;
    }

    public function setVersus(string $mode, int $players, int $level): void
    {
        $this->values['versusMode'] = $mode;
        $this->values['versusRunners'] = $players;
        $this->values['versusLevel'] = $level;
        $this->save();
    }

    private function save(): void
    {
        foreach ($this->values as $key => $value) {
            $this->options->set($key, $value);
        }
    }
}
