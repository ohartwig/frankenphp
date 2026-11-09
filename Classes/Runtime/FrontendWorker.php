<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * FrankenPHP worker loop for TYPO3 frontend requests.
 *
 * Bootstraps TYPO3 per request. A fresh bootstrap does not clear what the
 * previous request left in static properties and globals, so the loop resets
 * that state before and after every request (see RequestStateReset). Database
 * connections are the exception: DatabaseConnectionReuse decides at the same
 * two points whether they stay open for the next request.
 * Backend and Install requests are rejected with HTTP 503. Every frontend
 * request it serves is timed (RequestTiming): bootstrap and application
 * separately, and whether TYPO3 rendered the page.
 *
 * With a PersistentBootstrap (KOH_PERSISTENT_BOOTSTRAP=1) TYPO3 boots once,
 * on the first frontend request, and every later request starts from the
 * recorded boot state instead. LeakGuard then checks each response against
 * its request before it is sent; on a finding the request gets a 503, the
 * finding is logged as one `koh_worker_leak` JSON line, and the worker ends,
 * so FrankenPHP starts a fresh one for the next request.
 */
final readonly class FrontendWorker
{
    private DatabaseConnectionReuse $connections;

    /**
     * @param DatabaseConnectionReuse|null $connections null: reuse with the defaults
     */
    public function __construct(
        private RequestClassifier $classifier,
        private string $autoloadPath,
        ?DatabaseConnectionReuse $connections = null,
        private ?PersistentBootstrap $persistent = null,
    ) {
        $this->connections = $connections ?? DatabaseConnectionReuse::fromEnvironment([]);
    }

    public function run(int $maxRequests = 1): void
    {
        ignore_user_abort(true);

        $nbRequests = 0;
        $running = true;

        $handlers = RequestStateReset::currentHandlers();

        while ($nbRequests < $maxRequests && $running) {
            $workerRequest = $nbRequests + 1;
            $clean = true;
            $running = \frankenphp_handle_request(function () use ($workerRequest, &$clean): void {
                $clean = $this->handleRequest($workerRequest);
            });
            // A worker that served state from another request serves nothing
            // more.
            $running = $running && $clean;
            // After the request as well as before the next one: an idle worker
            // should not hold the last page's objects until traffic arrives
            // again, nor a connection that is not fit for the next request.
            RequestStateReset::resetRequestState();
            $this->persistent?->afterRequest();
            $this->connections->afterRequest();
            RequestStateReset::restoreHandlers($handlers);
            gc_collect_cycles();
            ++$nbRequests;
        }
    }

    /**
     * @return bool false if the leak guard found state from another request
     */
    private function handleRequest(int $workerRequest = 1): bool
    {
        $start = hrtime(true);
        RequestStateReset::resetRequestState();
        $this->connections->beforeRequest();

        if (!$this->classifier->isFrontendRequest()) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Request reached frontend worker but is not a frontend request.';
            return true;
        }

        // Capture all output in a buffer so the full response is sent
        // atomically to FrankenPHP. Without buffering, the PSR-7 response
        // emitter streams output incrementally and FrankenPHP may not
        // receive the complete response before the worker recycles.
        $outputLevel = ob_get_level();
        ob_start();
        RequestTiming::begin();
        $booted = null;
        $reused = false;
        $finding = null;

        try {
            $classLoader = require $this->autoloadPath;
            if ($this->persistent === null) {
                \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(
                    0,
                    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_FE
                );
                $container = \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader);
            } else {
                [$container, $reused] = $this->persistent->container($classLoader);
            }
            $booted = hrtime(true);
            $container->get(\TYPO3\CMS\Core\Http\Application::class)->run();
            if ($this->persistent !== null) {
                $finding = LeakGuard::check(
                    $_SERVER,
                    (string) LeakGuard::indpEnv('HTTP_HOST'),
                    $GLOBALS['TYPO3_REQUEST'] ?? null,
                ) ?? LeakGuard::staleIndpEnv();
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Internal Server Error';
            error_log(sprintf(
                'FrankenPHP worker exception: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            RequestTiming::record($start, $booted, hrtime(true), 500, $workerRequest, $reused);
            return true;
        }

        if ($finding !== null) {
            // The page may show another request's host or language: it is
            // not sent, and nothing in it may be cached.
            while (ob_get_level() > $outputLevel) {
                ob_end_clean();
            }
            header_remove();
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('Retry-After: 0');
            echo 'Service Unavailable';
            error_log(LeakGuard::logLine($finding, $workerRequest));
            RequestTiming::record($start, $booted, hrtime(true), 503, $workerRequest, $reused);
            return false;
        }

        // Get the complete buffered response and send it with correct
        // Content-Length. TYPO3 sets Content-Length before middleware
        // modifications (e.g. BirthdayService comment injection) which
        // causes a mismatch. We recalculate from the actual buffer.
        $content = ob_get_clean();
        if ($content === false) {
            // ob_start() above guarantees a buffer; this is unreachable in
            // practice, but ob_get_clean() is typed string|false.
            return true;
        }
        header_remove('Content-Length');
        header('Content-Length: ' . strlen($content), true);
        echo $content;
        $status = http_response_code();
        RequestTiming::record($start, $booted, hrtime(true), is_int($status) ? $status : 200, $workerRequest, $reused);

        return true;
    }
}
