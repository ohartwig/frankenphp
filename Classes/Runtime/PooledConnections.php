<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Reads and trims the connections TYPO3's ConnectionPool keeps in its static
 * `$connections` property.
 *
 * The closures are bound to ConnectionPool to reach the protected property
 * without creating a pool instance, which would read TYPO3_CONF_VARS.
 * `ConnectionPool::resetConnections()` is no substitute: it only empties the
 * list, and the connection objects would stay open until garbage collection
 * reached them.
 *
 * @internal
 */
final class PooledConnections
{
    /** @return array<string, Connection> */
    public static function all(): array
    {
        return \Closure::bind(static fn(): array => static::$connections, null, ConnectionPool::class)();
    }

    /**
     * Closes one pooled connection and removes it from the pool, so the next
     * request that asks for it gets a new one. A connection that cannot be
     * closed cleanly is dropped anyway.
     *
     * @param string $name a key of all()
     */
    public static function close(string $name): void
    {
        \Closure::bind(static function () use ($name): void {
            try {
                static::$connections[$name]->close();
            } catch (\Throwable) {
                // Gone either way once it is out of the pool.
            }
            unset(static::$connections[$name]);
        }, null, ConnectionPool::class)();
    }

    public static function closeAll(): void
    {
        foreach (array_keys(self::all()) as $name) {
            self::close($name);
        }
    }
}
