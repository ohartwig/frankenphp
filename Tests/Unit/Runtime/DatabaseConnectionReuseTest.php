<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Doctrine\DBAL\DriverManager;
use Koh\FrankenPHP\Runtime\DatabaseConnectionReuse;
use Koh\FrankenPHP\Runtime\PooledConnections;
use Koh\FrankenPHP\Runtime\RequestStateReset;
use Koh\FrankenPHP\Tests\Fixtures\ConnectionProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Which database connections a worker keeps for the next request, and which
 * it closes.
 *
 * The connections are real TYPO3 Connection objects on SQLite: whether a
 * connection is open, inside a transaction, or the same one as before is what
 * these tests are about, and a mock would only answer what it was told.
 * A simulated request is afterRequest() followed by beforeRequest(), the two
 * calls the worker loop makes around frankenphp_handle_request().
 */
final class DatabaseConnectionReuseTest extends TestCase
{
    private float $now = 1000.0;

    /** @var list<string> */
    private array $logged = [];

    private string $databaseFile = '';

    protected function tearDown(): void
    {
        PooledConnections::closeAll();
        if ('' !== $this->databaseFile) {
            @unlink($this->databaseFile);
        }
        parent::tearDown();
    }

    private function reuse(
        bool $enabled = true,
        int $idleTimeout = 60,
        int $maxAge = 600,
        int $maxRequests = 1000,
        bool $livenessCheck = true,
    ): DatabaseConnectionReuse {
        return new DatabaseConnectionReuse($enabled, $idleTimeout, $maxAge, $maxRequests, $livenessCheck, fn(): float => $this->now, function (string $message): void {
            $this->logged[] = $message;
        });
    }

    /** A connection the request has used, in the pool under Default. */
    private function pooledConnection(): Connection
    {
        $connection = ConnectionProbe::connection();
        $connection->executeQuery('SELECT 1')->free();
        ConnectionProbe::pool('Default', $connection);

        return $connection;
    }

    private function pooled(): ?Connection
    {
        return PooledConnections::all()['Default'] ?? null;
    }

    #[Test]
    public function aHealthyConnectionServesTheNextRequest(): void
    {
        $reuse = $this->reuse();
        $connection = $this->pooledConnection();
        $native = $connection->getNativeConnection();

        $reuse->afterRequest();
        ++$this->now;
        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
        self::assertTrue($connection->isConnected());
        self::assertSame($native, $connection->getNativeConnection(), 'the same socket, not a reconnect');
    }

    #[Test]
    public function aConnectionStaysOverManyRequests(): void
    {
        $reuse = $this->reuse();
        $connection = $this->pooledConnection();

        for ($request = 0; $request < 5; ++$request) {
            $reuse->afterRequest();
            $this->now += 10;
            $reuse->beforeRequest();
        }

        self::assertSame($connection, $this->pooled());
    }

    /**
     * The next request would otherwise run inside the previous one's
     * transaction: its uncommitted rows visible, its locks held.
     */
    #[Test]
    public function aTransactionLeftOpenIsRolledBackAndTheConnectionClosed(): void
    {
        $connection = $this->fileConnection();
        $connection->executeStatement('CREATE TABLE t (id INTEGER)');
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO t (id) VALUES (1)');
        ConnectionProbe::pool('Default', $connection);

        $this->reuse()->afterRequest();

        self::assertNull($this->pooled());
        self::assertFalse($connection->isConnected());
        self::assertSame(0, (int) $this->fileConnection()->fetchOne('SELECT COUNT(*) FROM t'), 'the insert was rolled back');
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('left a transaction open on database connection "Default"', $this->logged[0]);
    }

    /**
     * A rollback that fails still ends in a closed connection; closing it ends
     * the transaction on the server.
     */
    #[Test]
    public function aFailingRollbackStillClosesTheConnection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new \RuntimeException('server gone'));
        $connection->expects(self::once())->method('close');
        ConnectionProbe::pool('Default', $connection);

        $this->reuse()->afterRequest();

        self::assertNull($this->pooled());
    }

    /** Every nesting level is rolled back, not only the innermost savepoint. */
    #[Test]
    public function everyNestingLevelIsRolledBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(true, true, true, false);
        $connection->expects(self::exactly(2))->method('rollBack');
        ConnectionProbe::pool('Default', $connection);

        $this->reuse()->afterRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function aConnectionTheRequestNeverOpenedIsNotKept(): void
    {
        ConnectionProbe::pool('Default', ConnectionProbe::connection());

        $this->reuse()->afterRequest();

        self::assertNull($this->pooled());
    }

    /**
     * DBAL closes a connection itself when it finds the server gone; keeping
     * the object would only carry its usage over to a new socket.
     */
    #[Test]
    public function aConnectionDbalClosedDuringTheRequestIsNotKept(): void
    {
        $connection = $this->pooledConnection();
        $connection->close();

        $this->reuse()->afterRequest();

        self::assertNull($this->pooled());
    }

    /**
     * The server would close it after wait_timeout anyway, possibly in the
     * middle of the request's first query.
     */
    #[Test]
    public function aConnectionIdleForTheLimitIsClosedWhenTheNextRequestStarts(): void
    {
        $reuse = $this->reuse(idleTimeout: 60);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 60;
        $reuse->beforeRequest();

        self::assertNull($this->pooled());
        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function aConnectionIdleForLessThanTheLimitIsKept(): void
    {
        $reuse = $this->reuse(idleTimeout: 60);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 59.9;
        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
    }

    /** Idle time counts from the end of the last request, not the first. */
    #[Test]
    public function idleTimeCountsFromTheLastRequest(): void
    {
        $reuse = $this->reuse(idleTimeout: 60);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 50;
        $reuse->beforeRequest();
        $reuse->afterRequest();
        $this->now += 50;
        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
    }

    #[Test]
    public function aConnectionReachingItsMaximumAgeIsClosedAfterTheRequest(): void
    {
        $reuse = $this->reuse(idleTimeout: 0, maxAge: 100);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 99;
        $reuse->beforeRequest();
        self::assertSame($connection, $this->pooled(), 'precondition: younger than the limit');
        ++$this->now;
        $reuse->afterRequest();

        self::assertNull($this->pooled());
    }

    /**
     * Age counts idle time too: a connection that reached its age while the
     * worker waited is not handed to the next request.
     */
    #[Test]
    public function aConnectionThatAgedWhileIdleIsClosedBeforeTheRequest(): void
    {
        $reuse = $this->reuse(idleTimeout: 0, maxAge: 100);
        $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 100;
        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function aConnectionIsClosedAfterItsMaximumNumberOfRequests(): void
    {
        $reuse = $this->reuse(maxRequests: 2);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $reuse->beforeRequest();
        self::assertSame($connection, $this->pooled(), 'precondition: kept after the first request');
        $reuse->afterRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function limitsOfZeroAreNoLimits(): void
    {
        $reuse = $this->reuse(idleTimeout: 0, maxAge: 0, maxRequests: 0);
        $connection = $this->pooledConnection();

        for ($request = 0; $request < 3; ++$request) {
            $reuse->afterRequest();
            $this->now += 100000;
            $reuse->beforeRequest();
        }

        self::assertSame($connection, $this->pooled());
    }

    /**
     * A restart or failover closes every connection on the server side; the
     * request must not be the one that finds out.
     */
    #[Test]
    public function aConnectionThatNoLongerAnswersIsClosedBeforeTheRequest(): void
    {
        $reuse = $this->reuse();
        $connection = $this->deadConnection();
        $connection->expects(self::once())->method('close');
        ConnectionProbe::pool('Default', $connection);
        $reuse->afterRequest();

        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    /**
     * pdo_mysql warns about a socket the server closed before it throws. The
     * warning is not the request's business, and the handler that swallows it
     * must not stay installed.
     */
    #[Test]
    public function theLivenessCheckSwallowsDriverWarningsAndRestoresTheHandlers(): void
    {
        $reuse = $this->reuse();
        $connection = $this->deadConnection(warn: true);
        $connection->expects(self::once())->method('executeQuery');
        ConnectionProbe::pool('Default', $connection);
        $reuse->afterRequest();
        $handlers = RequestStateReset::currentHandlers();
        error_clear_last();

        $reuse->beforeRequest();

        self::assertNull(error_get_last(), 'the warning reached no other handler');
        self::assertSame($handlers, RequestStateReset::currentHandlers());
    }

    #[Test]
    public function aHealthyConnectionAlsoLeavesTheHandlersAsTheyWere(): void
    {
        $reuse = $this->reuse();
        $this->pooledConnection();
        $reuse->afterRequest();
        $handlers = RequestStateReset::currentHandlers();

        $reuse->beforeRequest();

        self::assertSame($handlers, RequestStateReset::currentHandlers());
    }

    #[Test]
    public function withoutTheLivenessCheckNothingIsQueried(): void
    {
        $reuse = $this->reuse(livenessCheck: false);
        $connection = $this->deadConnection();
        $connection->expects(self::never())->method('executeQuery');
        ConnectionProbe::pool('Default', $connection);
        $reuse->afterRequest();

        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
    }

    /** A connection the worker did not see after a request is not trusted. */
    #[Test]
    public function aConnectionWithoutAHistoryIsClosedBeforeTheRequest(): void
    {
        $this->pooledConnection();

        $this->reuse()->beforeRequest();

        self::assertNull($this->pooled());
    }

    /** A connection closed and opened again starts its count afresh. */
    #[Test]
    public function aClosedConnectionTakesItsHistoryWithIt(): void
    {
        $reuse = $this->reuse(maxRequests: 2);
        $first = $this->pooledConnection();
        $reuse->afterRequest();
        $this->now += 60;
        $reuse->beforeRequest();
        self::assertFalse($first->isConnected(), 'precondition: closed for idling');

        $second = $this->pooledConnection();
        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame($second, $this->pooled());
    }

    /**
     * A connection that has to go does not decide for the ones after it.
     */
    #[Test]
    public function aConnectionAfterAnUnusedOneIsStillKept(): void
    {
        $reuse = $this->reuse();
        ConnectionProbe::pool('Unused', ConnectionProbe::connection());
        $healthy = $this->pooledConnection();

        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame(['Default' => $healthy], PooledConnections::all());
    }

    #[Test]
    public function aConnectionAfterOneLeftInATransactionIsStillKept(): void
    {
        $reuse = $this->reuse();
        $busy = ConnectionProbe::connection();
        $busy->beginTransaction();
        ConnectionProbe::pool('Busy', $busy);
        $healthy = $this->pooledConnection();

        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame(['Default' => $healthy], PooledConnections::all());
    }

    #[Test]
    public function aConnectionAfterAnExhaustedOneIsStillKept(): void
    {
        $reuse = $this->reuse(maxRequests: 2);
        $old = ConnectionProbe::connection();
        $old->executeQuery('SELECT 1')->free();
        ConnectionProbe::pool('Old', $old);
        $reuse->afterRequest();
        $reuse->beforeRequest();
        $new = $this->pooledConnection();

        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame(['Default' => $new], PooledConnections::all());
    }

    #[Test]
    public function eachConnectionIsJudgedOnItsOwn(): void
    {
        $reuse = $this->reuse();
        $healthy = $this->pooledConnection();
        $idle = ConnectionProbe::connection();
        ConnectionProbe::pool('Other', $idle);

        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame(['Default' => $healthy], PooledConnections::all());
    }

    /** Switched off, the worker behaves as it did before reuse existed. */
    #[Test]
    public function switchedOffEveryConnectionIsClosedAfterTheRequest(): void
    {
        $connection = $this->pooledConnection();

        $this->reuse(enabled: false)->afterRequest();

        self::assertSame([], PooledConnections::all());
        self::assertFalse($connection->isConnected());
    }

    #[Test]
    public function switchedOffEveryConnectionIsClosedBeforeTheRequest(): void
    {
        $this->pooledConnection();
        $this->reuse()->afterRequest();

        $this->reuse(enabled: false)->beforeRequest();

        self::assertSame([], PooledConnections::all());
    }

    /**
     * @param array<string, string> $environment
     */
    #[Test]
    #[DataProvider('switchedOff')]
    public function theEnvironmentSwitchesReuseOff(array $environment): void
    {
        $this->pooledConnection();

        DatabaseConnectionReuse::fromEnvironment($environment)->afterRequest();

        self::assertSame([], PooledConnections::all());
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function switchedOff(): iterable
    {
        yield '0' => [['DB_REUSE' => '0']];
        yield 'false' => [['DB_REUSE' => 'false']];
        yield 'off' => [['DB_REUSE' => 'off']];
    }

    /**
     * Reuse is the default; a value that is empty or not understood does not
     * switch it off by accident.
     *
     * @param array<string, mixed> $environment
     */
    #[Test]
    #[DataProvider('defaultOn')]
    public function reuseIsOnUnlessSwitchedOff(array $environment): void
    {
        $connection = $this->pooledConnection();
        $reuse = DatabaseConnectionReuse::fromEnvironment($environment);

        $reuse->afterRequest();
        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function defaultOn(): iterable
    {
        yield 'unset' => [[]];
        yield 'empty' => [['DB_REUSE' => '']];
        yield 'not a boolean' => [['DB_REUSE' => 'maybe']];
        yield 'not a scalar' => [['DB_REUSE' => ['1']]];
        yield 'on' => [['DB_REUSE' => '1']];
    }

    #[Test]
    public function theIdleTimeoutComesFromTheEnvironment(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_IDLE_TIMEOUT' => '5'], fn(): float => $this->now);
        $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 5;
        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    /** Sixty seconds, well below MariaDB's wait_timeout. */
    #[Test]
    public function theDefaultIdleTimeoutIsOneMinute(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_IDLE_TIMEOUT' => 'soon'], fn(): float => $this->now);
        $connection = $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 59;
        $reuse->beforeRequest();
        self::assertSame($connection, $this->pooled());
        $reuse->afterRequest();
        $this->now += 60;
        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function theMaximumAgeComesFromTheEnvironment(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_MAX_AGE' => '30'], fn(): float => $this->now);
        $this->pooledConnection();

        $reuse->afterRequest();
        $this->now += 30;
        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function theDefaultMaximumAgeIsTenMinutes(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_MAX_AGE' => 'long'], fn(): float => $this->now);
        $connection = $this->pooledConnection();
        $reuse->afterRequest();

        for ($request = 0; $request < 10; ++$request) {
            $this->now += 59;
            $reuse->beforeRequest();
            $reuse->afterRequest();
        }
        $this->now += 9;
        $reuse->beforeRequest();
        self::assertSame($connection, $this->pooled(), 'precondition: 599 s old');
        ++$this->now;
        $reuse->afterRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function theMaximumNumberOfRequestsComesFromTheEnvironment(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_MAX_REQUESTS' => '1']);
        $this->pooledConnection();

        $reuse->afterRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function theDefaultMaximumIsAThousandRequests(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment([], fn(): float => $this->now);
        $connection = $this->pooledConnection();

        for ($request = 1; $request < 1000; ++$request) {
            $reuse->afterRequest();
            $reuse->beforeRequest();
        }
        self::assertSame($connection, $this->pooled(), 'precondition: 999 requests served');
        $reuse->afterRequest();

        self::assertNull($this->pooled());
    }

    #[Test]
    public function theLivenessCheckCanBeSwitchedOffInTheEnvironment(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment(['DB_REUSE_LIVENESS_CHECK' => '0']);
        $connection = $this->deadConnection();
        $connection->expects(self::never())->method('executeQuery');
        ConnectionProbe::pool('Default', $connection);
        $reuse->afterRequest();

        $reuse->beforeRequest();

        self::assertSame($connection, $this->pooled());
    }

    #[Test]
    public function theLivenessCheckIsOnByDefault(): void
    {
        $reuse = DatabaseConnectionReuse::fromEnvironment([]);
        $connection = $this->deadConnection();
        $connection->expects(self::once())->method('close');
        ConnectionProbe::pool('Default', $connection);
        $reuse->afterRequest();

        $reuse->beforeRequest();

        self::assertNull($this->pooled());
    }

    /**
     * Connected and outside a transaction, so it is kept after the request,
     * but every query fails as on a socket the server has closed.
     *
     * @return Connection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function deadConnection(bool $warn = false): Connection
    {
        $platform = ConnectionProbe::connection()->getDatabasePlatform();
        $connection = $this->createMock(Connection::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->method('isTransactionActive')->willReturn(false);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeQuery')->willReturnCallback(static function () use ($warn): never {
            if ($warn) {
                trigger_error('PDO::query(): MySQL server has gone away', E_USER_WARNING);
            }
            throw new \RuntimeException('MySQL server has gone away');
        });

        return $connection;
    }

    /** A connection to a database file, so a second connection can look at what the first one left. */
    private function fileConnection(): Connection
    {
        if ('' === $this->databaseFile) {
            $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'reuse-db');
        }

        return DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->databaseFile,
            'wrapperClass' => Connection::class,
        ]);
    }
}
