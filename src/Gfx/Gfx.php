<?php

namespace App\Gfx;

use JumPHP\Kernel\Engine;
use JumPHP\Renderer\Sdl;

/**
 * The game's textures by name, loaded by JumPHP's Sdl (assets/ paths, colour keys, slices of 4064 px), with what
 * the game needs on top of it:
 *  - the blocks of the 35 levels are registered only (~200 MB once decoded) and loaded with their level
 *    (loadGroup / releaseGroupsExcept); several levels use the same files: a file is loaded once, whatever the
 *    names it has;
 *  - wide pictures come with their slices next to them (tileset1-0.png, tileset1-1.png...: cut once, not by GD
 *    at each start in the browser);
 *  - variants: getTextures('objects') gives 'objects-water' while setTextureVariant('-water') is on;
 *  - getRenderer() / getWindow(): the game view (View) on JumPHP's renderer.
 *
 * A texture is a list of slices ['texture' => SDL texture, 'width', 'height', 'x'] like JumPHP's.
 */
final class Gfx
{
    private const SLICE = 4064;

    private View $view;
    /** @var array<string, array> textures by name (slices) */
    private array $textures = [];
    /** @var array<string, array{0: string, 1: ?array, 2: string}> registered textures: name => [path, colour key, group] */
    private array $lazy = [];
    /** @var array<string, array{0: array, 1: string[]}> files loaded for the registered names: path key => [slices, JumPHP names] */
    private array $files = [];
    private string $variant = '';
    private array $fonts = [];
    private string $assets;

    public function __construct(private Sdl $sdl, private Engine $engine)
    {
        $this->view = new View($sdl->getRenderer(), $engine);
        $this->assets = $engine->config->path('assets');
    }

    public function getRenderer(): View
    {
        return $this->view;
    }

    /** The view size (getWidth / getHeight in game pixels). */
    public function getWindow(): View
    {
        return $this->view;
    }

    public function engine(): Engine
    {
        return $this->engine;
    }

    // --- registered textures (the levels' blocks) ----------------------------------------------------

    public function registerTexture(string $name, string $path, string $group, ?array $transparentColor = null): void
    {
        $this->lazy[$name] = [$path, $transparentColor, $group];
    }

    /** Loads every texture of $group not loaded yet. */
    public function loadGroup(string $group): void
    {
        foreach ($this->lazy as $name => [, , $g]) {
            if ($g === $group && !isset($this->textures[$name])) $this->loadLazy($name);
        }
    }

    /** Frees the registered textures of the other groups (their files, unless $group uses them too). */
    public function releaseGroupsExcept(string $group): void
    {
        $keep = [];
        foreach ($this->lazy as [$path, $key, $g]) {
            if ($g === $group) $keep[self::pathKey($path, $key)] = true;
        }
        foreach ($this->lazy as $name => [$path, $key, $g]) {
            if ($g === $group || !isset($this->textures[$name])) continue;
            unset($this->textures[$name]);
            $pathKey = self::pathKey($path, $key);
            if (!isset($keep[$pathKey])) $this->freeFile($pathKey);
        }
    }

    /** Forgets a registered texture, and frees its file when no other name uses it. */
    public function releaseTexture(string $name): void
    {
        if (!isset($this->lazy[$name])) return;
        [$path, $key] = $this->lazy[$name];
        $pathKey = self::pathKey($path, $key);
        unset($this->textures[$name], $this->lazy[$name]);
        foreach ($this->lazy as [$otherPath, $otherKey]) {
            if (self::pathKey($otherPath, $otherKey) === $pathKey) return;
        }
        $this->freeFile($pathKey);
    }

    /** Sizes of the slices of a texture ([['width' => , 'height' => ], ...]) without loading it. */
    public function getTextureSizes(string $name): array
    {
        if (isset($this->textures[$name]) || !isset($this->lazy[$name])) return $this->getTextures($name);
        $file = $this->file($this->lazy[$name][0]);
        $size = @getimagesize($file);
        if ($size === false) throw new \RuntimeException('Image not found: ' . $this->lazy[$name][0]);
        [$width, $height] = $size;
        $sizes = [];
        for ($x = 0; $x < $width || $x === 0; $x += self::SLICE) {
            $sizes[] = ['width' => min(self::SLICE, $width - $x), 'height' => $height];
        }
        return $sizes;
    }

    private function loadLazy(string $name): array
    {
        [$path, $key] = $this->lazy[$name];
        $pathKey = self::pathKey($path, $key);
        if (!isset($this->files[$pathKey])) {
            $engineName = 'file:' . strtr($pathKey, '/|,', '___');
            $this->files[$pathKey] = [$this->loadFile($engineName, $path, $key), $this->engineNames];
        }
        return $this->textures[$name] = $this->files[$pathKey][0];
    }

    private function freeFile(string $pathKey): void
    {
        foreach ($this->files[$pathKey][1] ?? [] as $engineName) $this->sdl->releaseTexture($engineName);
        unset($this->files[$pathKey]);
    }

    private static function pathKey(string $path, ?array $key): string
    {
        return $path . ($key === null ? '' : '|' . implode(',', $key));
    }

    // --- textures loaded now --------------------------------------------------------------------------

    /** $path: from assets/; $transparentColor: ['r' => , 'g' => , 'b' => ] becomes transparent. */
    public function loadTexture(string $name, string $path, ?array $transparentColor = null): void
    {
        $this->removeTexture($name);
        $this->textures[$name] = $this->loadFile($name, $path, $transparentColor);
        $this->owned[$name] = $this->engineNames;
    }

    /** @var array<string, string[]> JumPHP names of the textures loaded by loadTexture() */
    private array $owned = [];

    /** Loads $name again from another file, freeing the texture it had. */
    public function replaceTexture(string $name, string $path, ?array $transparentColor = null): void
    {
        if (isset($this->lazy[$name])) return;
        $this->loadTexture($name, $path, $transparentColor);
    }

    /** Forgets a texture loaded by loadTexture(). */
    public function removeTexture(string $name): void
    {
        if (isset($this->lazy[$name])) return;
        foreach ($this->owned[$name] ?? [] as $engineName) $this->sdl->releaseTexture($engineName);
        unset($this->textures[$name], $this->owned[$name]);
    }

    public function hasTexture(string $name): bool
    {
        return isset($this->textures[$name]) || isset($this->lazy[$name]);
    }

    public function getTextures(string $name): array
    {
        if ($this->variant !== '' && $this->hasTexture($name . $this->variant)) {
            $name .= $this->variant;
        }
        if (isset($this->textures[$name])) return $this->textures[$name];
        if (isset($this->lazy[$name])) return $this->loadLazy($name);
        throw new \RuntimeException("Unknown texture '$name'");
    }

    /** Suffix tried first by getTextures() (e.g. "-water": the underwater palette below the water line). */
    public function setTextureVariant(string $variant): void
    {
        $this->variant = $variant;
    }

    /** Loads "{name}-water" from "{path without .png}-water.png" when that file exists. */
    public function loadTextureVariant(string $name, string $path, string $variant, ?array $transparentColor = null): void
    {
        $variantPath = substr($path, 0, -4) . $variant . '.png';
        if (is_file($this->file($variantPath))) {
            $this->loadTexture($name . $variant, $variantPath, $transparentColor);
        }
    }

    /** @var string[] the JumPHP names of the last file loaded */
    private array $engineNames = [];

    /**
     * Loads assets/$path with JumPHP's Sdl as $engineName. A picture wider than 4064 px (WebGL's limit) is
     * loaded from its slices next to it ("name-0.png", "name-1.png"...) when they are there, else JumPHP cuts it.
     */
    private function loadFile(string $engineName, string $path, ?array $key): array
    {
        $file = $this->file($path);
        if (!is_file($file)) throw new \RuntimeException('Image not found: ' . $path);
        $rgb = $key === null ? null : [(int)$key['r'], (int)$key['g'], (int)$key['b']];
        $size = @getimagesize($file);
        $width = $size ? (int)$size[0] : 0;
        $base = substr($file, 0, -4);
        if ($width <= self::SLICE || !is_file($base . '-0.png')) {
            $this->engineNames = [$engineName];
            return $this->sdl->loadTexture($engineName, $file, $rgb);
        }
        $slices = [];
        $this->engineNames = [];
        for ($i = 0, $x = 0; $x < $width; $i++, $x += self::SLICE) {
            $part = $engineName . '#' . $i;
            $slice = $this->sdl->loadTexture($part, $base . '-' . $i . '.png', $rgb)[0];
            $slice['x'] = $x;
            $slices[] = $slice;
            $this->engineNames[] = $part;
        }
        return $slices;
    }

    private function file(string $path): string
    {
        return $this->assets . '/' . $path;
    }

    // --- font of the debug view (F1): the digits of the TTF font -------------------------------------

    public function loadFont(string $name, string $path): void
    {
        $file = $this->file($path);
        if (!is_file($file)) throw new \RuntimeException("Font file not found: $path");
        if (!function_exists('TTF_OpenFont')) return;
        \TTF_Init();
        $font = \TTF_OpenFont($file, 29);
        if (!$font) return;
        $color = new \SDL_Color(255, 0, 0, 0);
        $glyphs = [];
        foreach (str_split('0123456789ABCDEF') as $char) {
            $surface = \TTF_RenderText_Solid($font, $char, $color);
            if (!$surface) continue;
            $glyphs[$char] = \SDL_CreateTextureFromSurface($this->view->getRenderer(), $surface);
            \SDL_FreeSurface($surface);
        }
        $this->fonts[$name] = $glyphs;
    }

    public function getFont(string $name): array
    {
        return $this->fonts[$name] ?? [];
    }
}
