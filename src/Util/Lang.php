<?php

namespace App\Util;

use JumPHP\Kernel\Engine;
use JumPHP\Kernel\Platform;
use JumPHP\Util\Lang as EngineLang;

/**
 * The game's language: French (the texts of the code) or English (config/lang.yaml, the `en:` table of JumPHP's
 * Lang). TextRenderer translates every text it draws; texts made of parts are put together with t() and a
 * {name} pattern.
 *
 * Language: OPTIONS > LANGUE, else the browser's (JUMPHP_LANG, set by the page) or the system's (LANG=fr_FR...):
 * French, anything else: English.
 */
final class Lang
{
    public static function set(?string $lang): void
    {
        Engine::get()->lang->setLang($lang === 'fr' ? 'fr' : 'en');
    }

    /** 'fr' or 'en'. */
    public static function get(): string
    {
        return Engine::get()->lang->getLang() === 'fr' ? 'fr' : 'en';
    }

    /** The page (the browser's language), else the system. */
    public static function detect(): string
    {
        $page = strtolower((string)Platform::setting('JUMPHP_LANG', ''));
        if ($page !== '') return str_starts_with($page, 'fr') ? 'fr' : 'en';
        $system = strtolower((string)(getenv('LC_ALL') ?: getenv('LC_MESSAGES') ?: getenv('LANG')));
        return str_starts_with($system, 'fr') ? 'fr' : 'en';
    }

    /** $text (French) in the game's language, {key} replaced by $values. */
    public static function t(string $text, array $values = []): string
    {
        return EngineLang::t($text, $values);
    }

    /** "1ER", "2E" / "1ST", "2ND", "3RD", "4TH". */
    public static function rank(int $place): string
    {
        if (self::get() === 'fr') return $place === 1 ? '1ER' : $place . 'E';
        return $place . match ($place % 10 === 1 && $place !== 11 ? 1 : ($place % 10 === 2 && $place !== 12 ? 2 : ($place % 10 === 3 && $place !== 13 ? 3 : 0))) {
            1 => 'ST', 2 => 'ND', 3 => 'RD', default => 'TH',
        };
    }
}
