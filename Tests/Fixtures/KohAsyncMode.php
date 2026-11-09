<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

/**
 * Stands in for koh/typo3-async's AsyncMode::fromEnvironment(); see
 * KohAsyncTransports for why not the real name.
 */
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
