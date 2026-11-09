<?php

declare(strict_types=1);

/*
 * FrankenPHP frontend worker for TYPO3 13/14.
 *
 * Configure as worker entry point in your Caddyfile:
 *   worker /path/to/vendor/koh/frankenphp/Resources/Private/Worker/frontend.php
 *
 * Environment variables:
 *   TYPO3_BACKEND_ENTRYPOINT  Backend entry point path or URL (default: /typo3)
 *   MAX_REQUESTS              Requests per worker lifecycle    (default: 500)
 *   TYPO3_AUTOLOAD_PATH       Path to vendor/autoload.php     (auto-detected)
 *   DB_REUSE                  Keep database connections between requests (default: 1)
 *   DB_REUSE_IDLE_TIMEOUT     Seconds a kept connection may sit idle      (default: 60)
 *   DB_REUSE_MAX_AGE          Seconds a connection is kept at most        (default: 600)
 *   DB_REUSE_MAX_REQUESTS     Requests a connection serves at most        (default: 1000)
 *   DB_REUSE_LIVENESS_CHECK   SELECT 1 before reusing a connection        (default: 1)
 *   KOH_PERSISTENT_BOOTSTRAP  Boot TYPO3 once per worker, reset per request (default: 0)
 */

if (!($_SERVER['FRANKENPHP_WORKER'] ?? false)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'This script must be executed as a FrankenPHP worker.';
    exit(1);
}

// Resolve autoload path:
// Installed location: vendor/koh/frankenphp/Resources/Private/Worker/frontend.php
// 5 levels up from Worker/ → vendor/, then autoload.php
$autoloadPath = $_ENV['TYPO3_AUTOLOAD_PATH']
    ?? dirname(__DIR__, 5) . '/autoload.php';

require $autoloadPath;

use Koh\FrankenPHP\Runtime\DatabaseConnectionReuse;
use Koh\FrankenPHP\Runtime\FrontendWorker;
use Koh\FrankenPHP\Runtime\PersistentBootstrap;
use Koh\FrankenPHP\Runtime\RequestClassifier;

$backendEntryPoint = $_ENV['TYPO3_BACKEND_ENTRYPOINT']
    ?? $_SERVER['TYPO3_BACKEND_ENTRYPOINT']
    ?? '/typo3';

$maxRequests = (int) ($_ENV['MAX_REQUESTS'] ?? 500);

$classifier = new RequestClassifier($backendEntryPoint);
// Settings may come from the worker's `env` lines in the Caddyfile or from the
// container environment; $_ENV is empty when variables_order lacks E.
$environment = $_ENV + $_SERVER + getenv();
$connections = DatabaseConnectionReuse::fromEnvironment($environment);
$persistent = PersistentBootstrap::isEnabled($environment) ? new PersistentBootstrap() : null;
$worker = new FrontendWorker($classifier, $autoloadPath, $connections, $persistent);
$worker->run($maxRequests);
