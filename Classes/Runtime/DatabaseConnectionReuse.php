<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use TYPO3\CMS\Core\Database\Connection;

/**
 * Decides at each request boundary whether the worker keeps a database
 * connection for the next request or closes it.
 *
 * Opening a connection costs a TCP handshake, a TLS handshake when the
 * connection is encrypted, authentication and TYPO3's initCommands. A request
 * TYPO3 answers from its page cache spends little else, so a worker that
 * closes every connection after each request pays that again and again.
 * Keeping it is safe only as long as the next request finds a connection in
 * the state a new one would have:
 *
 * - **Open transaction.** A request that ends inside a transaction would hand
 *   its uncommitted writes and its row locks to the next one. The transaction
 *   is rolled back and the connection closed; the event is logged, since it
 *   is a defect of the code that left it open.
 * - **Server-closed socket.** MariaDB drops a connection idle for longer than
 *   `wait_timeout`, and a restart or failover drops all of them. A connection
 *   idle for longer than the idle limit is closed before the request starts,
 *   and a cheap `SELECT 1` on the others catches the rest, so no request meets
 *   a dead socket on its first query.
 * - **Session state.** Session variables, advisory locks, temporary tables and
 *   the like would carry over; PDO offers no COM_RESET_CONNECTION to clear
 *   them. TYPO3 core leaves none behind in a frontend request, and its
 *   connect-time initCommands are meant to hold for every request anyway. An
 *   extension that runs SET SESSION, GET_LOCK() without releasing, or CREATE
 *   TEMPORARY TABLE in the frontend is the case for switching reuse off. Age
 *   and request limits bound how long anything unforeseen could carry over.
 *
 * The connection object itself holds nothing of the request that created it:
 * TYPO3 builds it from DBAL's Configuration, the stateless core driver
 * middlewares and its schema manager factory, and resolves caches and other
 * services afresh on each call. A third-party driver middleware that captures
 * request-scoped services would break that; such a setup should switch reuse
 * off.
 *
 * Disabled, both hooks close every connection, which is what the worker did
 * before reuse existed.
 */
final class DatabaseConnectionReuse
{
    /** @var \WeakMap<Connection, array{openedAt: float, lastUsedAt: float, requests: int}> */
    private \WeakMap $usage;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(string): mixed */
    private readonly \Closure $log;

    /**
     * @param int                            $idleTimeout seconds a connection may sit unused between requests; <= 0 disables the limit
     * @param int                            $maxAge      seconds since the connection was first kept; <= 0 disables the limit
     * @param int                            $maxRequests requests a connection serves before it is closed; <= 0 disables the limit
     * @param (\Closure(): float)|null       $clock       current time in seconds, for tests
     * @param (\Closure(string): mixed)|null $log         where the rollback is reported; error_log() by default
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly int $idleTimeout,
        private readonly int $maxAge,
        private readonly int $maxRequests,
        private readonly bool $livenessCheck,
        ?\Closure $clock = null,
        ?\Closure $log = null,
    ) {
        $this->usage = new \WeakMap();
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->log = $log ?? error_log(...);
    }

    /**
     * Reads the settings from environment variables, falling back to the
     * defaults for any that are missing or not understood: reuse on, 60 s idle,
     * 600 s age, 1000 requests, liveness check on.
     *
     * The worker runs before TYPO3 has loaded its configuration, so an
     * extension setting is not available here; environment variables are how
     * the worker is configured anyway (MAX_REQUESTS).
     *
     * @param array<array-key, mixed>  $environment
     * @param (\Closure(): float)|null $clock       current time in seconds, for tests
     */
    public static function fromEnvironment(array $environment, ?\Closure $clock = null): self
    {
        return new self(
            self::flag($environment['DB_REUSE'] ?? null, true),
            self::number($environment['DB_REUSE_IDLE_TIMEOUT'] ?? null, 60),
            self::number($environment['DB_REUSE_MAX_AGE'] ?? null, 600),
            self::number($environment['DB_REUSE_MAX_REQUESTS'] ?? null, 1000),
            self::flag($environment['DB_REUSE_LIVENESS_CHECK'] ?? null, true),
            $clock,
        );
    }

    /**
     * Runs before TYPO3 boots for a request: closes what has been idle or alive
     * too long, and what no longer answers.
     */
    public function beforeRequest(): void
    {
        $now = ($this->clock)();
        foreach (PooledConnections::all() as $name => $connection) {
            $usage = $this->usage[$connection] ?? null;
            if (!$this->enabled
                || null === $usage
                || $this->exceeds($now - $usage['lastUsedAt'], $this->idleTimeout)
                || $this->exceeds($now - $usage['openedAt'], $this->maxAge)
                || ($this->livenessCheck && !self::answers($connection))
            ) {
                $this->close($name, $connection);
            }
        }
    }

    /**
     * Runs once the response is out: decides which connections stay for the
     * next request.
     */
    public function afterRequest(): void
    {
        $now = ($this->clock)();
        foreach (PooledConnections::all() as $name => $connection) {
            $usage = $this->usageIfKept($name, $connection, $now);
            if (null === $usage) {
                $this->close($name, $connection);
            } else {
                $this->usage[$connection] = $usage;
            }
        }
    }

    /**
     * The connection's usage including the request that just ended, or null
     * when it must not serve another one.
     *
     * @return array{openedAt: float, lastUsedAt: float, requests: int}|null
     */
    private function usageIfKept(string $name, Connection $connection, float $now): ?array
    {
        if (!$this->enabled || !$connection->isConnected()) {
            // Switched off, never used, or closed by DBAL after losing it.
            return null;
        }
        if ($connection->isTransactionActive()) {
            $this->rollBack($name, $connection);

            return null;
        }

        $usage = $this->usage[$connection] ?? ['openedAt' => $now, 'lastUsedAt' => $now, 'requests' => 0];
        $usage['lastUsedAt'] = $now;
        ++$usage['requests'];

        return $this->exceeds($usage['requests'], $this->maxRequests)
            || $this->exceeds($now - $usage['openedAt'], $this->maxAge)
            ? null
            : $usage;
    }

    private function close(string $name, Connection $connection): void
    {
        unset($this->usage[$connection]);
        PooledConnections::close($name);
    }

    /** A limit of zero or less is no limit. */
    private function exceeds(float|int $value, int $limit): bool
    {
        return $limit > 0 && $value >= $limit;
    }

    /**
     * Whether the server still answers on this connection.
     *
     * pdo_mysql reports a socket the server has closed with a warning on top of
     * the exception; the warning is swallowed, the exception decides.
     */
    private static function answers(Connection $connection): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            $connection->executeQuery($connection->getDatabasePlatform()->getDummySelectSQL())->free();

            return true;
        } catch (\Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Rolls back every nesting level. The connection is closed afterwards in
     * any case, which makes the server roll back whatever this did not reach.
     */
    private function rollBack(string $name, Connection $connection): void
    {
        ($this->log)(sprintf(
            'FrankenPHP worker: a request left a transaction open on database connection "%s"; rolled back and closed.',
            $name
        ));
        try {
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            // Closing the connection ends the transaction on the server.
        }
    }

    /** Empty counts as unset: filter_var() would read it as false. */
    private static function flag(mixed $value, bool $default): bool
    {
        if (!is_scalar($value) || '' === $value) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function number(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
