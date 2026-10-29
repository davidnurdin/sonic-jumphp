<?php

namespace App\Scene;

use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The pause, over GameScene: the console's PAUSE button (Enter / Start), or the page / window hidden
 * (game.yaml pause_scene). The game is frozen and its sound paused by GameScene::pause(); "PAUSE" is drawn by
 * Game::renderWindow in the game's font. Enter / Space / Start: go on. Escape: back to the main menu.
 * Pushed over a screen that can't be paused (menus, map, tallies, demo), it leaves at once.
 */
final class PauseScene extends AbstractScene
{
    public function __construct(private GameScene $game)
    {
    }

    public function update(float $dt): void
    {
        if (!$this->game->isPaused()) {
            $this->scenes()->pop();
            return;
        }
        $actions = $this->actions();
        if ($actions->isPressed('back')) {
            $this->scenes()->pop('title');
        } elseif ($actions->isPressed('start') || $actions->isPressed('jump')) {
            $this->scenes()->pop();
        }
    }

    public function render(Renderer $r): void
    {
        // a dark veil over the frozen game (the word itself: Game::renderWindow, at window resolution)
        $r->fillRect(0, 0, $r->getWidth(), $r->getHeight(), [0, 0, 0, 110]);
    }

    public function isOverlay(): bool
    {
        return true;
    }
}
