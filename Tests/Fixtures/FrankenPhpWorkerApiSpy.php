<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

/**
 * State for the stand-in FrankenPHP worker API.
 *
 * Kept apart from the function that uses it because the two have different
 * requirements: the function must be declared in the global namespace, where
 * FrontendWorker calls it, while this file is autoloaded and therefore has to
 * satisfy the PSR-4 mapping of Tests/. Putting the class next to the function
 * failed exactly that check.
 */
final class FrankenPhpWorkerApiSpy
{
    /** @var list<callable> */
    public static array $handlers = [];

    public static int $remaining = 0;

    public static function reset(int $remaining): void
    {
        self::$handlers = [];
        self::$remaining = $remaining;
    }
}
