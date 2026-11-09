<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\DatabaseConnectionReuse;
use Koh\FrankenPHP\Runtime\FrontendWorker;
use Koh\FrankenPHP\Runtime\PersistentBootstrap;
use Koh\FrankenPHP\Runtime\PooledConnections;
use Koh\FrankenPHP\Runtime\RequestClassifier;
use Koh\FrankenPHP\Runtime\RequestStateReset;
use Koh\FrankenPHP\Runtime\RequestTiming;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Utility\GeneralUtility;

use Koh\FrankenPHP\Tests\Fixtures\ConnectionProbe;
use Koh\FrankenPHP\Tests\Fixtures\FakeApplication;
use Koh\FrankenPHP\Tests\Fixtures\FrankenPhpWorkerApiSpy;
use Koh\FrankenPHP\Tests\Fixtures\RequestStateProbe;

require_once __DIR__ . '/../../Fixtures/frankenphp_handle_request.php';

/**
 * The worker loop, and what it refuses to serve.
 *
 * `frankenphp_handle_request()` exists only inside a FrankenPHP worker process
 * — not in CLI, not in php-fpm, and therefore not here. Tests/Fixtures supplies
 * a stand-in in the global namespace, which is the only way this loop is
 * reachable from a unit test at all.
 *
 * What is worth pinning is the refusal. A worker is a long-lived process that
 * was never meant to carry an authenticated backend session, so a backend or
 * Install request arriving here must be turned away rather than served — and
 * turned away with a status a proxy understands, not with a blank page.
 */
final class FrontendWorkerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        FrankenPhpWorkerApiSpy::reset(1);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        PooledConnections::closeAll();
        parent::tearDown();
    }

    private function worker(string $uri, ?string $autoloadPath = null, ?DatabaseConnectionReuse $connections = null): FrontendWorker
    {
        $_SERVER['REQUEST_URI'] = $uri;

        return $connections === null
            ? new FrontendWorker(
                new RequestClassifier('/typo3'),
                $autoloadPath ?? __DIR__ . '/../../Fixtures/ThrowingAutoload.php',
            )
            : new FrontendWorker(
                new RequestClassifier('/typo3'),
                $autoloadPath ?? __DIR__ . '/../../Fixtures/ThrowingAutoload.php',
                $connections,
            );
    }

    /**
     * Runs the worker with output captured, and returns what it wrote.
     *
     * error_log() is diverted into a file for the duration. The worker logs its
     * bootstrap failure that way, and with no `error_log` ini setting that goes
     * to the SAPI logger — STDERR on CLI. Infection's InitialTestsRunner stops
     * the PHPUnit process on the first byte written there, which surfaces as a
     * suite killed at signal 15 rather than as a failing test.
     */
    private function runCapturing(FrontendWorker $worker, int $maxRequests = 1): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'worker-log');
        self::assertIsString($logFile);
        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);

        ob_start();
        try {
            $worker->run($maxRequests);
        } finally {
            $output = (string)ob_get_clean();
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            unlink($logFile);
        }

        return $output;
    }

    #[Test]
    public function aBackendRequestIsTurnedAwayRatherThanServed(): void
    {
        $output = $this->runCapturing($this->worker('/typo3/module/web/layout'));

        self::assertStringContainsString('not a frontend request', $output);
        self::assertSame(503, http_response_code());
    }

    #[Test]
    public function anInstallToolRequestIsTurnedAwayToo(): void
    {
        $output = $this->runCapturing($this->worker('/typo3/install.php'));

        self::assertStringContainsString('not a frontend request', $output);
    }

    /**
     * A frontend request gets as far as bootstrapping TYPO3, which cannot
     * succeed here — the point is that it fails as a 500 with a generic body
     * rather than escaping as an exception. A worker that dies on one bad
     * request stops serving every subsequent one.
     */
    #[Test]
    public function aFailedBootstrapBecomesAFiveHundredRatherThanAnException(): void
    {
        $output = $this->runCapturing($this->worker('/a-page'));

        self::assertSame('Internal Server Error', $output);
        self::assertSame(500, http_response_code());
    }

    /**
     * The buffered body is what reaches FrankenPHP, and nothing of the failed
     * attempt may be left in it — the buffer is discarded before the error is
     * written, or the visitor gets half a page followed by an error string.
     */
    #[Test]
    public function aFailedBootstrapLeavesNothingOfTheAttemptInTheBody(): void
    {
        $output = $this->runCapturing($this->worker('/a-page'));

        self::assertSame('Internal Server Error', $output);
        self::assertStringNotContainsString('bootstrap unavailable', $output);
    }

    /**
     * A request starts clean even when something left state behind before it —
     * the reset at the start of a request, not only the one after the last.
     */
    #[Test]
    public function aRequestDoesNotStartWithStateLeftBehind(): void
    {
        RequestStateProbe::reset();
        $GLOBALS['BE_USER'] = new \stdClass();

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/StateProbingAutoload.php'));

        self::assertSame([false], RequestStateProbe::$backendUserAtStart);
    }

    /**
     * What a request leaves in globals is gone once the worker is back in the
     * loop, so an idle worker holds nothing of it and the next request on this
     * thread cannot pick it up.
     */
    #[Test]
    public function requestStateDoesNotOutliveTheRequest(): void
    {
        RequestStateProbe::reset();
        unset($GLOBALS['BE_USER']);

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/StateProbingAutoload.php'));

        self::assertArrayNotHasKey('BE_USER', $GLOBALS);
    }

    /**
     * Over several requests none of them sees what the previous one left.
     */
    #[Test]
    public function noRequestSeesThePreviousOnesState(): void
    {
        RequestStateProbe::reset();
        FrankenPhpWorkerApiSpy::reset(3);

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/StateProbingAutoload.php'), 3);

        self::assertSame([false, false, false], RequestStateProbe::$backendUserAtStart);
    }

    /**
     * Handlers registered while serving a request are removed afterwards; the
     * ones in place before the loop started stay.
     */
    #[Test]
    public function theLoopLeavesTheHandlerStackAsItFoundIt(): void
    {
        $before = RequestStateReset::currentHandlers();

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/HandlerRegisteringAutoload.php'));

        self::assertSame($before, RequestStateReset::currentHandlers());
    }

    /**
     * The connection the first request opened serves the ones after it, by
     * default — the TLS handshake is paid once, not per request.
     */
    #[Test]
    public function laterRequestsGetTheConnectionTheFirstOneOpened(): void
    {
        ConnectionProbe::reset();
        FrankenPhpWorkerApiSpy::reset(3);

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/ConnectionUsingAutoload.php'), 3);

        [$first, $second, $third] = ConnectionProbe::$foundAtStart;
        self::assertNull($first);
        self::assertNotNull($second);
        self::assertSame($second, $third);
        self::assertTrue($second->isConnected());
    }

    /**
     * The check before a request runs in the worker, not only after one: a
     * connection the worker has not seen through a request is not handed on.
     */
    #[Test]
    public function aConnectionTheWorkerDidNotKeepIsNotHandedToARequest(): void
    {
        ConnectionProbe::reset();
        $stray = ConnectionProbe::connection();
        $stray->executeQuery('SELECT 1')->free();
        ConnectionProbe::pool('Default', $stray);

        $this->runCapturing($this->worker('/a-page', __DIR__ . '/../../Fixtures/ConnectionUsingAutoload.php'));

        self::assertSame([null], ConnectionProbe::$foundAtStart);
        self::assertFalse($stray->isConnected());
    }

    /** Switched off, every request opens its own connection, as before. */
    #[Test]
    public function withReuseSwitchedOffEveryRequestOpensItsOwnConnection(): void
    {
        ConnectionProbe::reset();
        FrankenPhpWorkerApiSpy::reset(3);

        $this->runCapturing(
            $this->worker('/a-page', __DIR__ . '/../../Fixtures/ConnectionUsingAutoload.php', DatabaseConnectionReuse::fromEnvironment(['DB_REUSE' => '0'])),
            3,
        );

        self::assertSame([null, null, null], ConnectionProbe::$foundAtStart);
        self::assertSame([], PooledConnections::all());
    }

    #[Test]
    public function theLoopStopsAfterTheRequestedNumberOfRequests(): void
    {
        FrankenPhpWorkerApiSpy::reset(5);

        $this->runCapturing($this->worker('/typo3'), 3);

        self::assertCount(3, FrankenPhpWorkerApiSpy::$handlers);
    }

    /**
     * The loop also stops when the server says so, whatever the request budget
     * was — that is how FrankenPHP recycles a worker.
     */
    #[Test]
    public function theLoopStopsWhenTheServerSaysSo(): void
    {
        FrankenPhpWorkerApiSpy::reset(2);

        $this->runCapturing($this->worker('/typo3'), 10);

        self::assertCount(2, FrankenPhpWorkerApiSpy::$handlers);
    }

    #[Test]
    public function everyFrontendRequestIsTimedAndCounted(): void
    {
        $lines = [];
        RequestTiming::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        FrankenPhpWorkerApiSpy::reset(2);
        try {
            $this->runCapturing($this->worker('/blog'), 2);
        } finally {
            RequestTiming::writeTo(null);
        }

        self::assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $second = json_decode($lines[1], true);
        self::assertIsArray($first);
        self::assertIsArray($second);
        // The fixture's bootstrap throws: a 500, timed, without a boot time.
        self::assertSame(500, $first['status']);
        self::assertNull($first['boot_ms']);
        self::assertSame(1, $first['worker_request']);
        self::assertSame(2, $second['worker_request']);
    }

    #[Test]
    public function aRequestTurnedAwayIsNotTimed(): void
    {
        $lines = [];
        RequestTiming::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        try {
            $this->runCapturing($this->worker('/typo3/module/web/layout'));
        } finally {
            RequestTiming::writeTo(null);
        }

        self::assertSame([], $lines);
    }

    /** How often the boot function of persistentWorker() ran. */
    private int $boots = 0;

    /** How often the per-request environment refresh ran. */
    private int $refreshes = 0;

    private function persistentWorker(string $uri, bool $completeBoot = true): FrontendWorker
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_HOST'] = 'site-b.test';
        $GLOBALS['TYPO3_CONF_VARS']['FAKE'] = 'boot';
        $this->boots = 0;
        $this->refreshes = 0;
        FakeApplication::reset();

        $persistent = new PersistentBootstrap(
            function () use ($completeBoot): Container {
                ++$this->boots;
                $container = new Container();
                $state = new \stdClass();
                $state->complete = true;
                $state->cacheDisabled = !$completeBoot;
                $container->set('boot.state', $state);
                $container->set(\TYPO3\CMS\Core\Http\Application::class, new FakeApplication());
                GeneralUtility::setContainer($container);

                return $container;
            },
            function (): void {
                ++$this->refreshes;
            },
        );

        return new FrontendWorker(
            new RequestClassifier('/typo3'),
            __DIR__ . '/../../Fixtures/ClassLoaderAutoload.php',
            DatabaseConnectionReuse::fromEnvironment(['DB_REUSE' => '0']),
            $persistent,
        );
    }

    /**
     * Switched on, TYPO3 boots once; every later request runs on the kept
     * container, without what the request before it left in globals,
     * TYPO3_CONF_VARS or the container.
     */
    #[Test]
    public function aPersistentBootstrapBootsOnceAndStartsEveryRequestFromTheBootState(): void
    {
        $lines = [];
        RequestTiming::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        FrankenPhpWorkerApiSpy::reset(3);
        try {
            $output = $this->runCapturing($this->persistentWorker('/blog'), 3);
        } finally {
            RequestTiming::writeTo(null);
            unset($GLOBALS['FAKE_APPLICATION_LEFTOVER'], $GLOBALS['TYPO3_CONF_VARS']['FAKE']);
        }

        self::assertSame('page 1page 2page 3', $output);
        self::assertSame(1, $this->boots);
        self::assertSame(2, $this->refreshes);
        $clean = ['container' => true, 'leftover' => false, 'requestService' => false, 'confVar' => 'boot'];
        self::assertSame([$clean, $clean, $clean], FakeApplication::$seen);
        $modes = array_map(static fn(string $line): mixed => (json_decode($line, true) ?: [])['bootstrap'] ?? null, $lines);
        self::assertSame(['fresh', 'reused', 'reused'], $modes);
    }

    /** A boot TYPO3 marks as failsafe or uncached is not kept. */
    #[Test]
    public function anIncompleteBootIsNotKept(): void
    {
        FrankenPhpWorkerApiSpy::reset(2);
        try {
            $this->runCapturing($this->persistentWorker('/blog', false), 2);
        } finally {
            unset($GLOBALS['FAKE_APPLICATION_LEFTOVER'], $GLOBALS['TYPO3_CONF_VARS']['FAKE']);
        }

        self::assertSame(2, $this->boots);
        self::assertSame(0, $this->refreshes);
    }

    /**
     * A response built with another request's host is not sent: the client
     * gets a 503, and the worker serves nothing more.
     */
    #[Test]
    public function aLeakEndsTheWorkerWithAServiceUnavailable(): void
    {
        FrankenPhpWorkerApiSpy::reset(3);
        $worker = $this->persistentWorker('/blog');
        FakeApplication::$globalRequestHost = 'site-a.test';
        try {
            $output = $this->runCapturing($worker, 3);
        } finally {
            unset($GLOBALS['FAKE_APPLICATION_LEFTOVER'], $GLOBALS['TYPO3_CONF_VARS']['FAKE']);
        }

        self::assertSame('Service Unavailable', $output);
        self::assertSame(503, http_response_code());
        self::assertCount(1, FrankenPhpWorkerApiSpy::$handlers);
    }
}
