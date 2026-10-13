<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\FrontendWorker;
use Koh\FrankenPHP\Runtime\RequestClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use Koh\FrankenPHP\Tests\Fixtures\FrankenPhpWorkerApiSpy;

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
        parent::tearDown();
    }

    private function worker(string $uri, ?string $autoloadPath = null): FrontendWorker
    {
        $_SERVER['REQUEST_URI'] = $uri;

        return new FrontendWorker(
            new RequestClassifier('/typo3'),
            $autoloadPath ?? __DIR__ . '/../../Fixtures/ThrowingAutoload.php',
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
}
