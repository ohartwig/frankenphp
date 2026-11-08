<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Drops the process-wide state one TYPO3 request leaves behind, so the next
 * request handled by the same worker thread starts from what a fresh PHP
 * process would see.
 *
 * Bootstrapping TYPO3 again per request is not enough on its own: Bootstrap::init()
 * builds a new container but leaves every static property where the previous
 * request put it. The ones that matter:
 *
 * - `GeneralUtility::$indpEnvCache` memoises host, scheme and URL of the first
 *   request. On a multi-site installation the second domain would otherwise be
 *   served with the first domain's TYPO3_SITE_URL and TYPO3_REQUEST_HOST.
 * - `GeneralUtility::$singletonInstances` keeps SingletonInterface objects, some
 *   of which cache request data.
 * - `ConnectionPool::$connections` keeps the database connection, including any
 *   open transaction, session variables, and a socket the server may have
 *   closed after wait_timeout. reset() closes it; the worker loop instead
 *   uses resetRequestState() and leaves the connection to
 *   DatabaseConnectionReuse, which keeps it only while that is safe.
 * - `$GLOBALS['BE_USER']`, `['LANG']` and `['TYPO3_REQUEST']` are only set when
 *   a request needs them, so a request that does not would see the previous one's.
 *
 * This is the same set TYPO3's own testing framework resets between in-process
 * frontend requests (FrameworkState::reset()), plus the database connections.
 */
final class RequestStateReset
{
    /** Globals TYPO3 sets conditionally during a request. */
    private const REQUEST_GLOBALS = ['BE_USER', 'LANG', 'TYPO3_REQUEST', 'TSFE'];

    /**
     * Everything, database connections included: what a fresh PHP process
     * would start with.
     */
    public static function reset(): void
    {
        self::resetRequestState();
        PooledConnections::closeAll();
    }

    /**
     * Everything except the database connections, whose lifetime
     * DatabaseConnectionReuse decides.
     */
    public static function resetRequestState(): void
    {
        foreach (self::REQUEST_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }

        GeneralUtility::flushInternalRuntimeCaches();
        GeneralUtility::purgeInstances();
    }

    /**
     * The error and exception handler currently installed, as a pair.
     *
     * PHP has no getter for either. Setting `null` returns the current handler,
     * and restoring removes the `null` entry again.
     *
     * @return array{0: mixed, 1: mixed}
     */
    public static function currentHandlers(): array
    {
        $error = set_error_handler(null);
        restore_error_handler();
        $exception = set_exception_handler(null);
        restore_exception_handler();

        return [$error, $exception];
    }

    /**
     * Pops handlers until the ones installed before the request are back.
     *
     * TYPO3 calls set_error_handler() and set_exception_handler() during every
     * Bootstrap::init(). Without this the handler stacks grow by one entry per
     * request, and each entry keeps that request's handler object — and what it
     * references — alive until the worker restarts.
     *
     * Restoring to a recorded baseline rather than popping a fixed number keeps
     * handlers that were there before the request (the test runner's, or one the
     * worker itself installs) and copes with a request that failed before
     * registering anything.
     *
     * @param array{0: mixed, 1: mixed} $baseline from currentHandlers()
     */
    public static function restoreHandlers(array $baseline): void
    {
        self::popUntil(0, $baseline[0], restore_error_handler(...));
        self::popUntil(1, $baseline[1], restore_exception_handler(...));
    }

    /**
     * Every pop removes one entry, so the stack shrinks on each pass and the
     * loop ends at the baseline or, if the request removed that too, at an
     * empty stack — never in an endless loop.
     */
    private static function popUntil(int $slot, mixed $baseline, \Closure $pop): void
    {
        while (($current = self::currentHandlers()[$slot]) !== $baseline && null !== $current) {
            $pop();
        }
    }
}
