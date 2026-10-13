<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

use TYPO3\CMS\Core\Service\OpcodeCacheService;

/**
 * Worker-safe drop-in for the core {@see OpcodeCacheService}, registered as an
 * XCLASS in ext_localconf.php.
 *
 * The core service's argument-less clearAllActive() performs a GLOBAL opcache_reset().
 * OPcache is shared memory, so that reset pulls the compiled bytecode out from under
 * every resident FrankenPHP frontend worker on the machine — wedging them: they stop
 * answering while the pod stays "1/1 Running", so Kubernetes never self-heals and only
 * a rollout restart recovers it. It fires from any full flush: the backend "Flush all
 * caches" button, CLI `cache:flush`, DataHandler::clear_cacheCmd('all'), the MCP server.
 *
 * OPcache is already reset cleanly on every deploy / pod-restart, so a runtime global
 * reset has no legitimate use on a worker deployment. We therefore skip ONLY the global
 * reset there. Per-file invalidation (clearAllActive($file), used on every cache write
 * by the file cache backends) stays fully intact — and non-worker installs are unchanged.
 */
final readonly class WorkerSafeOpcodeCacheService extends OpcodeCacheService
{
    public function clearAllActive(?string $fileAbsPath = null): void
    {
        // Only the global reset (no specific file) is dangerous in worker mode.
        if ($fileAbsPath === null && self::isFrankenPhpWorkerDeployment()) {
            return;
        }

        parent::clearAllActive($fileAbsPath);
    }

    /**
     * True when this deployment runs FrankenPHP frontend workers that share OPcache.
     *
     * The triggering request (backend / CLI / MCP) is itself NOT the worker, so the
     * per-request `frankenphp_handle_request()` signal is absent here — the deployment
     * toggle is the server-wide FRANKENPHP_WORKER env the Caddyfile/image exports.
     */
    public static function isFrankenPhpWorkerDeployment(): bool
    {
        $flag = getenv('FRANKENPHP_WORKER');
        if (!is_string($flag)) {
            $fromServer = $_SERVER['FRANKENPHP_WORKER'] ?? $_ENV['FRANKENPHP_WORKER'] ?? '';
            $flag = is_string($fromServer) ? $fromServer : '';
        }

        return in_array(strtolower($flag), ['1', 'true', 'on', 'yes'], true);
    }
}
