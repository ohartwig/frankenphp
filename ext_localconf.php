<?php

declare(strict_types=1);

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Koh\FrankenPHP\EventListener\CacheInvalidationListener::class;

// Every cache flush the DataHandler performs -- direct saves, workspace
// publishes, moves, deletes, TSconfig clearCacheCmd, "flush page" -- ends in
// this hook with the pageId_<n> tags it flushed. processDatamapClass above
// only covers direct saves; without this line a workspace publish never
// reached the edge cache (a customer site, 2026-09-08).
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['clearCachePostProc'][]
    = \Koh\FrankenPHP\EventListener\CacheInvalidationListener::class . '->clearCachePostProc';

// Replace the core OpcodeCacheService so a full flush ("all") no longer runs a
// GLOBAL opcache_reset() on worker deployments — that wedges the resident FrankenPHP
// frontend workers (shared-memory OPcache). The XCLASS is resolved both by
// GeneralUtility::makeInstance() and by container-injected consumers (e.g. DataHandler),
// so every full-flush trigger (backend button, CLI, DataHandler, MCP) is covered.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][\TYPO3\CMS\Core\Service\OpcodeCacheService::class] = [
    'className' => \Koh\FrankenPHP\Service\WorkerSafeOpcodeCacheService::class,
];

// A failed edge-cache purge must be visible where the platform reads logs.
// TYPO3 writes to var/log inside the pod, which nobody reads and which is gone
// with the pod; error_log() goes to the container's stderr, from there to
// Loki, where the alert on "Caddy Cache: PURGE failed" counts it. The file
// writer stays alongside it, because a class-level writer configuration
// replaces the inherited one instead of adding to it.
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Koh']['FrankenPHP']['Service']['CaddyCacheService']['writerConfiguration'] = [
    \Psr\Log\LogLevel::ERROR => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [],
        \TYPO3\CMS\Core\Log\Writer\PhpErrorLogWriter::class => [],
    ],
];

// The edge cache purge after a save is a message (PurgeEdgeCache). It stays in
// the request, as before, unless koh/typo3-async provides koh_async_cache, its
// async mode is on and KOH_ASYNC_EDGE_PURGE=1: then the consumer sends it.
// See EdgePurgeRouting for why all three.
\Koh\FrankenPHP\Messenger\EdgePurgeRouting::configure(
    $GLOBALS['TYPO3_CONF_VARS'],
    \Koh\FrankenPHP\Messenger\EdgePurgeRouting::availableTransport(
        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('koh_async')
    ),
    \Koh\FrankenPHP\Messenger\EdgePurgeRouting::asyncModeEnabled(),
    \Koh\FrankenPHP\Messenger\EdgePurgeRouting::switchedOn(),
);

// KOH_WORKER_CACHE_MEMO=1: read-mostly caches (hash, rootline, ...) get a
// frontend that, in a FrankenPHP worker, skips the HMAC check and unserialize()
// for backend payloads it has already verified byte for byte. Every read still
// goes to the backend, so flushes on any pod apply at once. See
// WorkerMemoVariableFrontend. Off: nothing changes.
\Koh\FrankenPHP\Cache\WorkerCacheMemo::configure($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']);
