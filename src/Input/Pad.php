<?php

namespace App\Input;

use JumPHP\Input\ActionMap;
use JumPHP\Input\Keyboard;

/**
 * The joypad of the game for one frame, from JumPHP's actions (config/input.yaml: keyboard, gamepads, the phone's
 * on-screen pad and swipes / taps, the scripted input of headless runs): left, right, up, down, jump, start, back.
 * The raw keyboard stays for the debug keys of the original (F1-F7, keypad) and the name entry (letters).
 */
final class Pad
{
    public const ACTIONS = ['left', 'right', 'up', 'down', 'jump', 'start', 'back'];

    /** @var array<string, bool> */
    private array $held = [];
    /** @var array<string, bool> */
    private array $wasHeld = [];
    private ?string $lastHorizontal = null;
    private ?string $lastVertical = null;

    public function __construct(private ActionMap $actions, private Keyboard $keyboard)
    {
    }

    /** Reads this frame's actions (once per frame, before the game). */
    public function poll(): void
    {
        $this->wasHeld = $this->held;
        foreach (self::ACTIONS as $action) {
            $this->held[$action] = $this->actions->isHeld($action);
        }
        foreach (['left', 'right'] as $direction) {
            if ($this->pressed($direction)) $this->lastHorizontal = $direction;
        }
        foreach (['up', 'down'] as $direction) {
            if ($this->pressed($direction)) $this->lastVertical = $direction;
        }
    }

    public function held(string $action): bool
    {
        return $this->held[$action] ?? false;
    }

    /** Went down this frame. */
    public function pressed(string $action): bool
    {
        return $this->actions->isPressed($action);
    }

    /** Went up this frame (any source: keys, pad buttons, scripted input). */
    public function released(string $action): bool
    {
        return ($this->wasHeld[$action] ?? false) && !$this->held($action);
    }

    /** Something is down, or went down / up this frame: the original read the keys only then. */
    public function active(): bool
    {
        foreach (self::ACTIONS as $action) {
            if ($this->held($action) || $this->pressed($action) || $this->released($action)) return true;
        }
        return $this->keyboard->getLastKeyPressed() !== null || $this->keyboard->anyPressed();
    }

    /** Of left / right held together, the one pressed last. */
    public function lastHorizontal(): ?string
    {
        return $this->lastHorizontal;
    }

    public function lastVertical(): ?string
    {
        return $this->lastVertical;
    }

    /** A raw key (\SDLK_*) went down this frame. */
    public function key(int $sdlKey): bool
    {
        return $this->keyboard->isKeyPressed($sdlKey);
    }

    public function keyHeld(int $sdlKey): bool
    {
        return $this->keyboard->isKeyHeld($sdlKey);
    }

    /** @return int[] the keys that went down this frame, in order (typing) */
    public function typed(): array
    {
        return $this->keyboard->getTyped();
    }
}
