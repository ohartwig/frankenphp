<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\RequestStateReset;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What one request must not hand to the next one on the same worker thread.
 *
 * Each case first shows the state surviving — that is the behaviour of a
 * long-lived PHP process, and the reason the reset exists — and then that the
 * reset removes it.
 */
final class RequestStateResetTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyIP'] = '';
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyHeaderMultiValue'] = 'none';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        RequestStateReset::reset();
        parent::tearDown();
    }

    /**
     * The multi-site case: without the reset, a request to the second domain
     * would resolve to the host of the first request this thread served.
     *
     * getIndpEnv() is deprecated since v14.3 but still the cache core code reads
     * through; the deprecation is the subject here, not a defect of the test.
     */
    #[Test]
    #[IgnoreDeprecations]
    public function theNextRequestSeesItsOwnHostRatherThanThePreviousOne(): void
    {
        $_SERVER['HTTP_HOST'] = 'first.example';
        self::assertSame('first.example', GeneralUtility::getIndpEnv('HTTP_HOST'));

        $_SERVER['HTTP_HOST'] = 'second.example';
        self::assertSame('first.example', GeneralUtility::getIndpEnv('HTTP_HOST'), 'precondition: the cache survives');

        RequestStateReset::reset();

        self::assertSame('second.example', GeneralUtility::getIndpEnv('HTTP_HOST'));
    }

    #[Test]
    public function singletonsOfThePreviousRequestAreGone(): void
    {
        $first = GeneralUtility::makeInstance(RequestScopedSingleton::class);
        self::assertSame($first, GeneralUtility::makeInstance(RequestScopedSingleton::class), 'precondition: singletons survive');

        RequestStateReset::reset();

        self::assertNotSame($first, GeneralUtility::makeInstance(RequestScopedSingleton::class));
    }

    #[Test]
    public function conditionallySetRequestGlobalsAreRemoved(): void
    {
        $GLOBALS['BE_USER'] = new \stdClass();
        $GLOBALS['LANG'] = new \stdClass();
        $GLOBALS['TYPO3_REQUEST'] = new \stdClass();

        RequestStateReset::reset();

        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
        self::assertArrayNotHasKey('LANG', $GLOBALS);
        self::assertArrayNotHasKey('TYPO3_REQUEST', $GLOBALS);
    }

    /**
     * Configuration is not request state: Bootstrap::init() reloads it, and
     * removing it here would break code that runs between requests.
     */
    #[Test]
    public function configurationGlobalsAreLeftAlone(): void
    {
        RequestStateReset::reset();

        self::assertArrayHasKey('TYPO3_CONF_VARS', $GLOBALS);
    }

    #[Test]
    public function databaseConnectionsAreClosedAndForgotten(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('close');
        $this->setPooledConnections(['Default' => $connection]);

        RequestStateReset::reset();

        self::assertSame([], $this->pooledConnections());
    }

    /**
     * One connection that fails to close must not keep the others open, nor
     * escape as an exception that would end the worker loop.
     */
    #[Test]
    public function aConnectionThatFailsToCloseDoesNotStopTheOthers(): void
    {
        $broken = self::createStub(Connection::class);
        $broken->method('close')->willThrowException(new \RuntimeException('connection already gone'));
        $healthy = $this->createMock(Connection::class);
        $healthy->expects(self::once())->method('close');
        $this->setPooledConnections(['Broken' => $broken, 'Default' => $healthy]);

        RequestStateReset::reset();

        self::assertSame([], $this->pooledConnections());
    }

    #[Test]
    public function handlersARequestRegisteredAreRemoved(): void
    {
        $baseline = RequestStateReset::currentHandlers();

        set_error_handler(static fn(): bool => true);
        set_error_handler(static fn(): bool => true);
        set_exception_handler(static function (\Throwable $e): void {});

        RequestStateReset::restoreHandlers($baseline);

        self::assertSame($baseline, RequestStateReset::currentHandlers());
    }

    /**
     * A handler that was in place before the request — the test runner's here,
     * possibly the worker's own in production — survives.
     */
    #[Test]
    public function handlersFromBeforeTheRequestSurvive(): void
    {
        $outer = static fn(): bool => true;
        set_error_handler($outer);
        try {
            $baseline = RequestStateReset::currentHandlers();
            set_error_handler(static fn(): bool => true);

            RequestStateReset::restoreHandlers($baseline);

            self::assertSame($outer, RequestStateReset::currentHandlers()[0]);
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string, Connection> $connections */
    private function setPooledConnections(array $connections): void
    {
        \Closure::bind(static function () use ($connections): void {
            static::$connections = $connections;
        }, null, ConnectionPool::class)();
    }

    /** @return array<string, Connection> */
    private function pooledConnections(): array
    {
        return \Closure::bind(static fn(): array => static::$connections, null, ConnectionPool::class)();
    }
}

final class RequestScopedSingleton implements SingletonInterface {}
