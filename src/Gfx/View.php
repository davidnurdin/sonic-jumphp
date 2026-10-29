<?php

namespace App\Gfx;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Renderer;
use App\Entities\Player;
use App\Level\Level;
use App\Stage\Stage;

/**
 * The game view on JumPHP's renderer: what the original Renderer (its render target) and Window (the view size in
 * game pixels) were. The view is JumPHP's view texture, resized like the original did to the window's ratio
 * (Game::updateView, Renderer::resizeView); JumPHP scales it into the window and presents the frame.
 */
final class View
{
    private static ?View $current = null;

    public function __construct(private Renderer $renderer, private Engine $engine)
    {
        self::$current = $this;
    }

    /** The view of the running game (the original's Window::getInstance(), for the camera and the clouds). */
    public static function current(): View
    {
        if (self::$current === null) throw new \LogicException('No game view yet');
        return self::$current;
    }

    /** The raw SDL renderer, for the \SDL_* calls of the game. */
    public function getRenderer()
    {
        return $this->renderer->getRenderer();
    }

    /** The game view texture (the target the game draws into, between JumPHP's beginFrame and endFrame). */
    public function getRenderTexture()
    {
        return $this->renderer->getViewTexture();
    }

    public function engineRenderer(): Renderer
    {
        return $this->renderer;
    }

    public function clear(): void
    {
        \SDL_RenderClear($this->renderer->getRenderer());
    }

    public function setColor(int $r, int $g, int $b, int $a): void
    {
        \SDL_SetRenderDrawColor($this->renderer->getRenderer(), $r, $g, $b, $a);
    }

    /** New view size (zoom, window resized): only between two frames (Game::updateView, in the update). */
    public function resizeTarget(int $width, int $height): void
    {
        if ($width === $this->renderer->getWidth() && $height === $this->renderer->getHeight()) return;
        $this->renderer->resizeView($width, $height);
    }

    /** The size is the view's (resizeTarget). */
    public function setViewSize(int $width, int $height): void
    {
    }

    /** Width of the game view, in game pixels. */
    public function getWidth(): int
    {
        return $this->renderer->getWidth();
    }

    public function getHeight(): int
    {
        return $this->renderer->getHeight();
    }

    /** @return array{0:int,1:int} the window (or headless picture) in real pixels */
    public function outputSize(): array
    {
        return $this->renderer->outputSize();
    }

    /** @return array{0:int,1:int,2:int,3:int} where the view is in the window (x, y, w, h) */
    public function letterbox(): array
    {
        return $this->renderer->letterbox();
    }

    public function toggleFullscreen(): void
    {
        $this->engine->toggleFullscreen();
    }

    public function createScene(Stage $scene, Player $player, $fontTab, Level $level): void
    {
        $scene->drawScene($fontTab, $level);
    }
}
