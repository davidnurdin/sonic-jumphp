<?php

namespace App\Util;

use JumPHP\Kernel\GameConfig;

/** The game's files (assets/: levels, pictures, sounds), the same on desktop and in the browser. */
final class Assets
{
    public function __construct(private GameConfig $config)
    {
    }

    public function getAssetFolder(): string
    {
        return $this->config->path('assets');
    }
}
