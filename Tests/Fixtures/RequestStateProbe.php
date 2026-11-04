<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

/**
 * What StateProbingAutoload saw when its request started.
 *
 * Lives apart from the fixture for the same reason FrankenPhpWorkerApiSpy
 * does: the fixture is a plain script, this class has to satisfy PSR-4.
 */
final class RequestStateProbe
{
    /** @var list<bool> one entry per request: was BE_USER set when it began */
    public static array $backendUserAtStart = [];

    public static function reset(): void
    {
        self::$backendUserAtStart = [];
    }
}
