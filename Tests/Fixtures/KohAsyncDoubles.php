<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

/**
 * Stand-ins for the two koh/typo3-async symbols EdgePurgeRouting asks for:
 * Transports::CACHE and AsyncMode::fromEnvironment(). koh/typo3-async is no
 * dependency of this package, and loading a class under its real name would
 * change what every later test in the run sees.
 */
final class KohAsyncTransports
{
    public const CACHE = 'koh_async_cache';

    public const EMPTY = '';
}

final class KohAsyncMode
{
    public static ?bool $enabled = null;

    public static function fromEnvironment(): self
    {
        return new self();
    }

    public function isEnabled(): bool
    {
        return self::$enabled === true;
    }

    public static function notAMode(): string
    {
        return 'enabled';
    }
}
