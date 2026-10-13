<?php

declare(strict_types=1);

use Koh\FrankenPHP\Tests\Fixtures\FrankenPhpWorkerApiSpy;

/**
 * Stand-in for the FrankenPHP worker API, in the global namespace where
 * FrontendWorker calls it.
 *
 * `frankenphp_handle_request()` is registered only inside a FrankenPHP worker
 * process — not in CLI, not in php-fpm — so the worker loop is unreachable from
 * a test without it. This file declares no namespace on purpose: the function is
 * called as `\frankenphp_handle_request()`, and a copy inside a test's namespace
 * is a different function that nothing resolves to. It is required explicitly
 * rather than autoloaded, because functions are not.
 *
 * It does what the real one does as far as the loop can tell: invoke the handler
 * for one request and report whether the worker should keep going. The counter
 * belongs to the test, so both exit conditions — the request budget and the
 * server saying stop — are reachable.
 */
if (!function_exists('frankenphp_handle_request')) {
    function frankenphp_handle_request(callable $handler): bool
    {
        FrankenPhpWorkerApiSpy::$handlers[] = $handler;
        $handler();

        return --FrankenPhpWorkerApiSpy::$remaining > 0;
    }
}
