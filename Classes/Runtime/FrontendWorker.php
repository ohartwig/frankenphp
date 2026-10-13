<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * FrankenPHP worker loop for TYPO3 frontend requests.
 *
 * Bootstraps TYPO3 per request — no state is shared between requests.
 * Backend and Install requests are rejected with HTTP 503.
 */
final readonly class FrontendWorker
{
    public function __construct(
        private RequestClassifier $classifier,
        private string $autoloadPath,
    ) {}

    public function run(int $maxRequests = 1): void
    {
        ignore_user_abort(true);

        $nbRequests = 0;
        $running = true;

        while ($nbRequests < $maxRequests && $running) {
            $running = \frankenphp_handle_request($this->handleRequest(...));
            gc_collect_cycles();
            ++$nbRequests;
        }
    }

    private function handleRequest(): void
    {
        if (!$this->classifier->isFrontendRequest()) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Request reached frontend worker but is not a frontend request.';
            return;
        }

        // Capture all output in a buffer so the full response is sent
        // atomically to FrankenPHP. Without buffering, the PSR-7 response
        // emitter streams output incrementally and FrankenPHP may not
        // receive the complete response before the worker recycles.
        ob_start();

        try {
            $classLoader = require $this->autoloadPath;
            \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(
                0,
                \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::REQUESTTYPE_FE
            );
            \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader)
                ->get(\TYPO3\CMS\Core\Http\Application::class)
                ->run();
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
            return;
        }

        // Get the complete buffered response and send it with correct
        // Content-Length. TYPO3 sets Content-Length before middleware
        // modifications (e.g. BirthdayService comment injection) which
        // causes a mismatch. We recalculate from the actual buffer.
        $content = ob_get_clean();
        if ($content === false) {
            // ob_start() above guarantees a buffer; this is unreachable in
            // practice, but ob_get_clean() is typed string|false.
            return;
        }
        header_remove('Content-Length');
        header('Content-Length: ' . strlen($content), true);
        echo $content;
    }
}
