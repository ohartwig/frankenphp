<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

use Doctrine\DBAL\DriverManager;
use Koh\FrankenPHP\Runtime\PooledConnections;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * What ConnectionUsingAutoload found in the connection pool when its request
 * started, and how it puts a connection there the way TYPO3 would.
 */
final class ConnectionProbe
{
    /** @var list<Connection|null> one entry per request: the pooled Default connection at its start */
    public static array $foundAtStart = [];

    public static function reset(): void
    {
        self::$foundAtStart = [];
    }

    /**
     * A TYPO3 Connection on an in-memory SQLite database: the wrapper class
     * TYPO3 uses, without a server.
     */
    public static function connection(): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
            'wrapperClass' => Connection::class,
        ]);
    }

    /** What ConnectionPool::getConnectionByName() does on a miss. */
    public static function pool(string $name, Connection $connection): void
    {
        \Closure::bind(static function () use ($name, $connection): void {
            static::$connections[$name] = $connection;
        }, null, ConnectionPool::class)();
    }

    /** Uses the pooled Default connection, creating it first if there is none. */
    public static function useDefault(): void
    {
        $connection = PooledConnections::all()['Default'] ?? null;
        self::$foundAtStart[] = $connection;
        if (null === $connection) {
            $connection = self::connection();
            self::pool('Default', $connection);
        }
        $connection->executeQuery('SELECT 1')->free();
    }
}
