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
