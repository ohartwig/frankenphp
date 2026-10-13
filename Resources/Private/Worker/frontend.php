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
 *   MAX_REQUESTS              Requests per worker lifecycle    (default: 1)
 *   TYPO3_AUTOLOAD_PATH       Path to vendor/autoload.php     (auto-detected)
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

use Koh\FrankenPHP\Runtime\FrontendWorker;
use Koh\FrankenPHP\Runtime\RequestClassifier;

$backendEntryPoint = $_ENV['TYPO3_BACKEND_ENTRYPOINT']
    ?? $_SERVER['TYPO3_BACKEND_ENTRYPOINT']
    ?? '/typo3';

$maxRequests = (int) ($_ENV['MAX_REQUESTS'] ?? 1);

$classifier = new RequestClassifier($backendEntryPoint);
$worker = new FrontendWorker($classifier, $autoloadPath);
$worker->run($maxRequests);
