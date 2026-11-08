<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

/**
 * Stands in for koh/typo3-async's Transports::CACHE, which
 * EdgePurgeRouting asks for. koh/typo3-async is no dependency of this
 * package, and loading a class under its real name would change what every
 * later test in the run sees.
 */
final class KohAsyncTransports
{
    public const CACHE = 'koh_async_cache';

    public const EMPTY = '';
}
