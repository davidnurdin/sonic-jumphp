<?php

namespace App\Save;

use JumPHP\Save\Storage;

/**
 * Ghost of the fastest run of each level. While an act is played, Sonic's position and sprite frame are
 * recorded every 1/60 s of level time; when the act is completed faster than the saved run (or with no run
 * saved yet), it becomes the ghost: var/ghosts/level{N}.json.gz = {time, frames: [[x, y, sx, sy, sw, sh, flip]]}
 * (JumPHP's Storage: IndexedDB in the browser).
 * A run restarted from a checkpoint after a death is not kept (the ghost would jump).
 */
class GhostRecorder
{
    private int|string|null $level = null;
    private array $frames = [];
    private bool $valid = false;
    private float $lastTime = -1;
    /** @var array<int, ?array> best run by level, loaded on demand */
    private array $best = [];

    public function __construct(private Storage $storage, private string $dir)
    {
    }

    /** New attempt of the level (start, or full restart: valid; restart from a checkpoint: not kept). */
    public function begin(int|string $level, bool $valid = true): void
    {
        $this->level = $level;
        $this->frames = [];
        $this->valid = $valid;
        $this->lastTime = -1;
    }

    public function record(int|string $level, float $time, float $x, float $y, array $frame): void
    {
        // the timer went back to 0 (restart) or another level: a new attempt
        if ($level !== $this->level || $time < $this->lastTime) $this->begin($level);
        $this->lastTime = $time;
        $index = (int)floor($time * 60);
        $entry = [(int)round($x), (int)round($y), ...$frame];
        for ($i = count($this->frames); $i <= $index; $i++) {
            $this->frames[$i] = $entry;
        }
    }

    public function invalidate(): void
    {
        $this->valid = false;
    }

    /** The act is complete in $time seconds: kept when it is the fastest. */
    public function finish(int|string $level, float $time): void
    {
        if (!$this->valid || $level !== $this->level || !$this->frames) return;
        $best = $this->load($level);
        if ($best !== null && $best['time'] <= $time) return;
        $this->best[$level] = ['time' => $time, 'frames' => $this->frames];
        $this->storage->write($this->file($level), (string)gzencode((string)json_encode($this->best[$level])));
        $this->valid = false;
    }

    /** Title menu > options > erase: every saved run is deleted. */
    public function clearAll(): void
    {
        foreach (glob($this->storage->path($this->dir) . '/level*.json.gz') ?: [] as $file) {
            $this->storage->delete($this->dir . '/' . basename($file));
        }
        $this->best = [];
        $this->valid = false;
    }

    /** Frame of the best run at $time: [x, y, sx, sy, sw, sh, flip], or null (none, or the run is over). */
    public function frameAt(int|string $level, float $time): ?array
    {
        $best = $this->load($level);
        if ($best === null) return null;
        return $best['frames'][(int)floor($time * 60)] ?? null;
    }

    public function bestTime(int|string $level): ?float
    {
        return $this->load($level)['time'] ?? null;
    }

    private function load(int|string $level): ?array
    {
        if (!array_key_exists($level, $this->best)) {
            $raw = $this->storage->read($this->file($level));
            $data = $raw !== null ? json_decode((string)@gzdecode($raw), true) : null;
            $this->best[$level] = is_array($data) && isset($data['time'], $data['frames']) ? $data : null;
        }
        return $this->best[$level];
    }

    private function file(int|string $level): string
    {
        return $this->dir . '/level' . $level . '.json.gz';
    }
}
