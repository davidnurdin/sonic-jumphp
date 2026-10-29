<?php

namespace App\Scene;

use App\Audio\SoundManager;
use App\Entities\Physics\CollisionSystem;
use App\Entities\Player;
use App\Game;
use App\Gfx\Gfx;
use App\Input\Pad;
use App\Level\LevelManager;
use App\Stage\Camera;
use App\Stage\Stage;
use App\Util\Assets;
use JumPHP\Renderer\Renderer;
use JumPHP\Scene\AbstractScene;

/**
 * The whole game in one JumPHP scene: builds the original game's objects (App\Game and what it was given by the
 * original's service container), then each frame reads the joypad, runs one tick of the game and draws it.
 * The title screen, the menus and the tallies are the game's own (App\Stage\Screens), drawn at window
 * resolution by a JumPHP renderer overlay (Game::renderWindow).
 *
 * Params (tests, screenshots, the smoke test):
 *   level: 1-35        a new game straight in that act (no title, no map)
 *   bot: true          the computer plays (the autoplay of F7: the smoke test runs Green Hill this way)
 *   versus: race|flag|rings|deathmatch   a match against the computer (with level, players)
 *   demo: true         the title screen's demo (the ROM's joypad on Green Hill 1)
 */
final class GameScene extends AbstractScene
{
    private ?Game $game = null;
    private ?Pad $pad = null;
    private ?int $overlay = null;

    public function enter(array $params = []): void
    {
        $engine = $this->engine;
        $renderer = $engine->sdl->getRenderer();
        if ($this->overlay !== null) {
            $renderer->removeOverlay($this->overlay);
            $this->overlay = null;
        }
        // the original's mix: sound effects at full volume, music at 96 / 128
        $engine->sound->setSfxVolume((float)$engine->options->get('sfx_volume', 1.0));
        $engine->sound->setMusicVolume((float)$engine->options->get('music_volume', 0.75));

        $assets = new Assets($engine->config);
        $gfx = new Gfx($engine->sdl, $engine);
        $player = new Player($gfx);
        $stage = new Stage(new Camera(), $gfx, $player);
        $this->game = new Game($engine, $gfx, $player, $stage, new LevelManager($gfx, $assets),
            new SoundManager($engine->sound, $assets->getAssetFolder() . '/mixer'), new CollisionSystem());
        $this->pad = new Pad($engine->actions, $engine->input->getKeyboard());
        $this->game->boot();
        $this->overlay = $renderer->addOverlay(fn () => $this->game?->renderWindow());

        if (isset($params['versus'])) {
            $this->game->startMatchAt((string)$params['versus'], (int)($params['level'] ?? 1), (int)($params['players'] ?? 3),
                (bool)($params['bot'] ?? false));
        } elseif (isset($params['level'])) {
            $this->game->startAt((int)$params['level'], (bool)($params['bot'] ?? false));
        } elseif (!empty($params['demo'])) {
            $this->game->startDemo();
        } else {
            $this->game->showTitle();
        }
        $this->game->updateView();
    }

    public function update(float $dt): void
    {
        if ($this->game === null || $this->pad === null) return;
        $this->pad->poll();
        $this->game->frame($dt, $this->pad);
        // the view follows the window (zoom, fullscreen, phone turned): resized before JumPHP draws into it
        $this->game->updateView();
        if ($this->game->takePauseRequest()) {
            $this->scenes()->push(PauseScene::class);
        }
    }

    /** Something over the game (PauseScene): an act being played freezes, its music and effects too. */
    public function pause(): void
    {
        if ($this->game === null || !$this->game->canPause()) return;
        $this->game->paused = true;
        if ($this->engine->sound->isEnabled()) {
            \Mix_PauseMusic();
            \Mix_Pause(-1);
        }
    }

    /** Back from PauseScene ('title': Escape, the game is abandoned). */
    public function resume(mixed $result = null): void
    {
        if ($this->game === null || !$this->game->paused) return;
        $this->game->paused = false;
        if ($this->engine->sound->isEnabled()) {
            \Mix_ResumeMusic();
            \Mix_Resume(-1);
        }
        if ($result === 'title') $this->game->backToTitle();
    }

    public function isPaused(): bool
    {
        return $this->game !== null && $this->game->paused;
    }

    public function render(Renderer $r): void
    {
        $this->game?->render();
    }

    public function exit(): void
    {
        $this->resume();
        if ($this->overlay !== null) {
            $this->engine->sdl->getRenderer()->removeOverlay($this->overlay);
            $this->overlay = null;
        }
    }
}
