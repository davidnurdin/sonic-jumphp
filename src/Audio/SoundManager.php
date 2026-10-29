<?php

namespace App\Audio;

use JumPHP\Audio\SoundManager as EngineSound;

/**
 * Music and sound effects of the original sound driver, played by JumPHP's SoundManager.
 *
 * Every file in assets/mixer/music and assets/mixer/sfx is rendered from the ROM by tools/soundrip.py of the
 * original project: the sound driver runs on a Z80 emulator and its PSG writes go through an SN76489 emulator.
 * A looping song is split into "{name}-intro.ogg" (played once) and "{name}.ogg" (looped forever), at the exact
 * point where the driver state repeats: JumPHP's loadMusic($name, loop, intro) chains them.
 * The speed shoes switch to "{name}-fast" at the same place of the song (music/timing.json: the frames of the
 * intro / loop of every file and its tempo).
 */
class SoundManager
{
    // Music indexes of the original driver (S1_MusicPointers) -> files
    public const MUSIC_BY_INDEX = [
        0x00 => 'greenhill', 0x01 => 'bridge', 0x02 => 'jungle', 0x03 => 'labyrinth', 0x04 => 'scrapbrain',
        0x05 => 'skybase', 0x06 => 'title', 0x07 => 'map', 0x08 => 'invincibility', 0x09 => 'actcomplete',
        0x0A => 'death', 0x0B => 'boss', 0x0C => 'boss', 0x0D => 'boss', 0x0E => 'ending', 0x10 => 'specialstage',
        0x13 => 'allemeralds', 0x14 => 'emerald',
    ];

    // Sound effect indexes (S1_SFXPointers), named after the routines that play them in the disassembly
    public const SFX_JUMP = 0x00;
    public const SFX_EXPLOSION = 0x01;    // badnik / monitor destroyed, boss hit
    public const SFX_RING = 0x02;         // ring, act-complete tally tick
    public const SFX_SKID = 0x03;
    public const SFX_SPRING = 0x04;
    public const SFX_LAUNCH = 0x05;       // ramp block
    public const SFX_ROLL = 0x06;
    public const SFX_BUMPER = 0x07;
    public const SFX_EXTRA_LIFE = 0x09;
    public const SFX_SHOT = 0x0A;         // crab / buzz bomber / newtron shot
    public const SFX_SIGN_SPIN = 0x0B;
    public const SFX_SIGN_STOP = 0x0C;
    public const SFX_HURT = 0x11;
    public const SFX_SPLASH = 0x12;       // Chopper, entering water
    public const SFX_ELECTRIC = 0x13;
    public const SFX_FLAME = 0x17;
    public const SFX_FIREBALL = 0x18;     // Scrap Brain cannons, Labyrinth fireball heads
    public const SFX_LIFT = 0x19;         // lifts, Scrap Brain doors
    public const SFX_SWITCH = 0x1A;       // switch, drowning countdown beep
    public const SFX_BOMB = 0x1B;
    public const SFX_CANNON = 0x1C;
    public const SFX_SPEAR = 0x1D;
    public const SFX_BRIDGE = 0x20;
    public const SFX_GLASS = 0x21;
    public const SFX_BUBBLE = 0x22;       // air bubble breathed

    private bool $ready = false;
    private ?string $currentMusic = null;
    /** Speed shoes: the "-fast" version of the song (tickDivider1 = tickDivider2 + 1 in the original driver). */
    private bool $fast = false;
    /** music/timing.json: frames of the intro / loop of every file and its tempo factor. */
    private array $timing = [];
    /** File playing ("greenhill", "greenhill-fast"...), looped or not, its part ('intro' / 'loop') and when that part started. */
    private ?string $playingFile = null;
    private bool $looping = false;
    private string $part = 'loop';
    private float $partStart = 0;
    /** @var array<string, bool> songs registered in JumPHP's SoundManager */
    private array $registered = [];

    public function __construct(private EngineSound $sound, private string $folder)
    {
    }

    public function Init(): void
    {
        $this->ready = $this->sound->isEnabled();
        if (!$this->ready) return;
        // the driver restarts an effect at once (the tally ticks every 4 frames): no throttle
        $this->sound->throttle = 0.0;
        foreach (glob($this->folder . '/sfx/sfx*.wav') ?: [] as $file) {
            $this->sound->loadSfx(self::sfx(hexdec(substr(basename($file, '.wav'), 3))), $file);
        }
        $timing = $this->folder . '/music/timing.json';
        if (is_file($timing)) {
            $this->timing = json_decode((string)file_get_contents($timing), true) ?: [];
        }
    }

    private static function sfx(int $index): string
    {
        return sprintf('sfx%02x', $index);
    }

    public function playMusicIndex(int $index, bool $loop = true): void
    {
        if (isset(self::MUSIC_BY_INDEX[$index])) {
            $this->playMusic(self::MUSIC_BY_INDEX[$index], $loop);
        }
    }

    /** Starts a song (no restart if it is already playing). */
    public function playMusic(string $name, bool $loop = true, bool $restart = false): void
    {
        if (!$this->ready || ($name === $this->currentMusic && !$restart)) return;
        $this->currentMusic = $name;
        $this->startFile($this->fileFor($name), $loop, 'intro', 0.0);
    }

    /** The speed shoes start / stop: the song goes on at the same place, faster or back to normal. */
    public function setFast(bool $fast): void
    {
        if ($fast === $this->fast) return;
        $this->fast = $fast;
        if (!$this->ready || $this->currentMusic === null || $this->playingFile === null) return;
        $file = $this->fileFor($this->currentMusic);
        if ($file === $this->playingFile) return;
        $from = $this->timing[$this->playingFile] ?? null;
        $to = $this->timing[$file] ?? null;
        if ($from === null || $to === null) return;
        // position in the part, in frames of the playing file -> in ticks of the song -> frames of the other one
        $frames = (microtime(true) - $this->partStart) * 60;
        $part = $this->part;
        if ($part === 'intro' && $from['intro'] > 0 && $frames >= $from['intro']) {
            // JumPHP went on to the loop at the end of the intro
            $part = 'loop';
            $frames -= $from['intro'];
        }
        if ($part === 'loop') {
            $frames = fmod($frames, max(1, $from['loop']));
        }
        $frames = $frames * $from['speed'] / $to['speed'];
        if ($part === 'loop' || $to['intro'] === 0) {
            $part = $to['intro'] === 0 ? 'intro' : 'loop';
            $frames = fmod($frames, max(1, $to['loop']));
        } elseif ($frames >= $to['intro']) {
            $part = 'loop';
            $frames = fmod($frames - $to['intro'], max(1, $to['loop']));
        }
        $this->startFile($file, true, $part, $frames / 60);
    }

    /** "{name}-fast" while the shoes last, when that version exists. */
    private function fileFor(string $name): string
    {
        return $this->fast && isset($this->timing[$name . '-fast']) ? $name . '-fast' : $name;
    }

    /**
     * Plays a part of a song file ('intro' then its loop, or the loop / a jingle) from $seconds: the song with its
     * intro is "$file" in JumPHP's SoundManager, its loop alone "$file#loop".
     */
    private function startFile(string $file, bool $loop, string $part, float $seconds): void
    {
        $this->playingFile = $file;
        $this->looping = $loop;
        $intro = $this->folder . '/music/' . $file . '-intro.ogg';
        $this->sound->stopMusic();
        if ($part === 'intro' && $loop && is_file($intro)) {
            if (!$this->register($file, $intro)) return;
            $this->sound->playMusic($file, true);
            $this->part = 'intro';
        } else {
            if (!$this->register($file . '#loop', null)) return;
            $this->sound->playMusic($file . '#loop', $loop);
            $this->part = 'loop';
        }
        if ($seconds > 0) {
            \Mix_SetMusicPosition($seconds);
        }
        $this->partStart = microtime(true) - $seconds;
    }

    /** Loads a song in JumPHP's SoundManager the first time it is played. */
    private function register(string $name, ?string $intro): bool
    {
        if (isset($this->registered[$name])) return true;
        $loop = $this->folder . '/music/' . explode('#', $name)[0] . '.ogg';
        if (!is_file($loop)) return false;
        try {
            $this->sound->loadMusic($name, $loop, $intro);
        } catch (\RuntimeException $e) {
            error_log('[music] ' . $e->getMessage());
            return false;
        }
        return $this->registered[$name] = true;
    }

    public function stopMusic(): void
    {
        if (!$this->ready) return;
        $this->sound->stopMusic();
        $this->currentMusic = null;
        $this->playingFile = null;
    }

    public function getCurrentMusic(): ?string
    {
        return $this->currentMusic;
    }

    /** False once a non-looping song (jingle) has finished. */
    public function isMusicPlaying(): bool
    {
        if (!$this->ready || $this->playingFile === null) return false;
        // between the end of an intro and the start of its loop (JumPHP chains them each frame)
        return \Mix_PlayingMusic() || ($this->part === 'intro' && $this->looping);
    }

    /** Called every frame (JumPHP's SoundManager chains the intro of a song to its looping part). */
    public function update(): void
    {
    }

    /** Plays a sound effect; the same effect restarts instead of stacking (like the single SFX track of the driver). */
    public function playSfx(int $index, bool $loop = false): void
    {
        if (!$this->ready) return;
        $this->sound->playSfx(self::sfx($index), $loop ? -1 : 0);
    }

    public function stopSfx(int $index): void
    {
        if (!$this->ready) return;
        $this->sound->stopSfx(self::sfx($index));
    }
}
