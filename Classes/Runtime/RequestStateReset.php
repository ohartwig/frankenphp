<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use TYPO3\CMS\Core\Database\ConnectionPool;
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
 *   closed after wait_timeout.
 * - `$GLOBALS['BE_USER']`, `['LANG']` and `['TYPO3_REQUEST']` are only set when
 *   a request needs them, so a request that does not would see the previous one's.
 *
 * This is the same set TYPO3's own testing framework resets between in-process
 * frontend requests (FrameworkState::reset()), plus the database connections.
 *
 * Classes are only touched if they are already loaded: before the first
 * bootstrap there is nothing to reset, and the check keeps this usable without
 * TYPO3 on the include path.
 */
final class RequestStateReset
{
    /** Globals TYPO3 sets conditionally during a request. */
    private const REQUEST_GLOBALS = ['BE_USER', 'LANG', 'TYPO3_REQUEST', 'TSFE'];

    public static function reset(): void
    {
        foreach (self::REQUEST_GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }

        if (class_exists(GeneralUtility::class, false)) {
            GeneralUtility::flushInternalRuntimeCaches();
            GeneralUtility::purgeInstances();
        }

        if (class_exists(ConnectionPool::class, false)) {
            self::closeDatabaseConnections();
        }
    }

    /**
     * The error and exception handler currently installed, as a pair.
     *
     * PHP has no getter for either; installing a placeholder returns the
     * previous one, and restoring removes the placeholder again.
     *
     * @return array{0: mixed, 1: mixed}
     */
    public static function currentHandlers(): array
    {
        $error = set_error_handler(static fn(): bool => false);
        restore_error_handler();
        $exception = set_exception_handler(static function (\Throwable $e): void {});
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
     * registering anything. The bound stops a handler that re-registers itself
     * from turning this into an endless loop.
     *
     * @param array{0: mixed, 1: mixed} $baseline from currentHandlers()
     */
    public static function restoreHandlers(array $baseline): void
    {
        for ($i = 0; $i < 16 && self::currentHandlers()[0] !== $baseline[0]; ++$i) {
            restore_error_handler();
        }
        for ($i = 0; $i < 16 && self::currentHandlers()[1] !== $baseline[1]; ++$i) {
            restore_exception_handler();
        }
    }

    /**
     * Closes and forgets every connection the request opened.
     *
     * `ConnectionPool::resetConnections()` only empties the list; the connection
     * objects would stay open until garbage collection reached them. The closure
     * is bound to ConnectionPool to reach its protected static property without
     * creating a pool instance, which would read TYPO3_CONF_VARS.
     */
    private static function closeDatabaseConnections(): void
    {
        $close = \Closure::bind(static function (): void {
            foreach (static::$connections as $connection) {
                try {
                    $connection->close();
                } catch (\Throwable) {
                    // A connection that cannot be closed cleanly is dropped anyway.
                }
            }
            static::$connections = [];
        }, null, ConnectionPool::class);

        $close();
    }
}
