<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\EventListener;

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use Koh\FrankenPHP\Service\CaddyCacheService;

class CacheInvalidationListener
{
    /**
     * Pages already purged for a given DataHandler run. See clearCachePostProc().
     *
     * @var \WeakMap<DataHandler, array<int, true>>|null
     */
    private static ?\WeakMap $purgedPerDataHandler = null;

    public function __construct(
        private readonly CaddyCacheService $caddyCacheService,
        private readonly LoggerInterface $logger,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Handle general cache flush events
     */
    public function onGeneralCacheFlush(CacheFlushEvent $event): void
    {
        if (!$this->caddyCacheService->isEnabled()) {
            return;
        }
        if (!$event->hasGroup('pages') && !$event->hasGroup('all')) {
            return;
        }

        $this->logger->info('Caddy Cache: General cache flush detected', [
            'cacheIdentifier' => $event->getGroups(),
        ]);

        // Defer the HTTP request to Caddy until after the current request finishes.
        // Executing outgoing HTTP requests during DI container teardown in
        // FrankenPHP worker mode causes zend_mm_heap corruption.
        $this->deferFullFlush();
    }

    /**
     * DataHandler hook: called after a record has been inserted or updated.
     *
     * @param string $status 'new' or 'update'
     * @param string $table Database table name
     * @param string|int $id Record UID (may be a temporary "NEW…" string for new records)
     * @param array<string, mixed> $fieldArray Changed fields
     * @param DataHandler $dataHandler
     */
    /**
     * DataHandler hook clearCachePostProc -- the one path every editorial
     * change takes, whatever form it has.
     *
     * processDatamap_afterDatabaseOperations below only sees direct record
     * saves. It never sees a workspace publish (a cmdmap "version" swap), a
     * move, a delete, a TSconfig clearCacheCmd or a "flush page" from the page
     * module, so on the k3s tenants -- where editors and the MCP write into a
     * workspace and publish -- the edge kept serving the old page until the
     * next deploy flushed it. Found on a customer site on 2026-09-08: the whole
     * start page was republished and the Souin key for "/" kept its age.
     *
     * The core calls this hook from two places with two shapes:
     *  - prepareCacheFlush(), once per changed record, with
     *    ['table', 'uid', 'uid_page', 'TSConfig', 'tags' => [tag => true],
     *    'clearCacheCommands', 'clearCacheEnabled'] -- tags carry pageId_<n>
     *    for every page whose cache the core flushes for that record;
     *  - clear_cacheCmd(), with ['cacheCmd' => 'all'|'pages'|'<pid>'|'cachetag:x',
     *    'tags' => [pageId_<n>, ...]].
     *
     * 'all' and 'pages' become a deferred full flush (same reason as the event
     * handler: no outgoing HTTP during container teardown in worker mode).
     * Everything else purges the URLs of the tagged pages. The per-DataHandler
     * WeakMap keeps a save that touches five records from purging the same page
     * five times; a WeakMap rather than a plain static because a worker
     * process outlives the request, and entries die with their DataHandler.
     *
     * @param array<string, mixed> $params
     */
    public function clearCachePostProc(array $params, DataHandler $dataHandler): void
    {
        if (!$this->caddyCacheService->isEnabled()) {
            return;
        }

        $cacheCmd = strtolower((string)($params['cacheCmd'] ?? ''));
        if ($cacheCmd === 'all' || $cacheCmd === 'pages') {
            $this->deferFullFlush();
            return;
        }

        $pageIds = [];
        foreach ((array)($params['tags'] ?? []) as $key => $value) {
            $tag = is_string($key) ? $key : (string)$value;
            if (preg_match('/^pageId_(\d+)$/', $tag, $matches) === 1) {
                $pageIds[(int)$matches[1]] = true;
            }
        }
        if (isset($params['uid_page']) && (int)$params['uid_page'] > 0) {
            $pageIds[(int)$params['uid_page']] = true;
        }

        self::$purgedPerDataHandler ??= new \WeakMap();
        /** @var array<int, true> $done */
        $done = self::$purgedPerDataHandler[$dataHandler] ?? [];

        foreach (array_keys($pageIds) as $pageId) {
            if ($pageId <= 0 || isset($done[$pageId])) {
                continue;
            }
            $done[$pageId] = true;
            $urls = $this->caddyCacheService->getPageUrls($pageId);
            if ($urls !== []) {
                $this->caddyCacheService->invalidateUrls($urls);
            }
        }

        self::$purgedPerDataHandler[$dataHandler] = $done;
    }

    private function deferFullFlush(): void
    {
        $service = $this->caddyCacheService;
        $logger = $this->logger;
        register_shutdown_function(static function () use ($service, $logger): void {
            try {
                $service->invalidateAllSites();
            } catch (\Throwable $e) {
                $logger->error('Caddy Cache: Deferred flush failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /**
     * DataHandler hook processDatamapClass: direct record saves only. Everything
     * else (publish, move, delete, clearCacheCmd) arrives via clearCachePostProc().
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        if (!$this->caddyCacheService->isEnabled()) {
            return;
        }

        // Handle page updates
        if ($table === 'pages') {
            $this->handlePageUpdate((int)$id, $fieldArray);
        }

        // Handle content element updates
        if ($table === 'tt_content') {
            $this->handleContentUpdate((int)$id, $fieldArray);
        }

        // Handle other relevant table updates
        if (in_array($table, ['sys_file', 'sys_file_reference', 'fe_users', 'fe_groups'], true)) {
            $this->handleRelatedUpdate($table, (int)$id, $fieldArray);
        }
    }

    /**
     * Handle page record updates
     *
     * @param array<string, mixed> $updatedFields Changed fields of the pages record
     */
    private function handlePageUpdate(int $pageId, array $updatedFields): void
    {
        $this->logger->info('Caddy Cache: Page updated', [
            'pageId' => $pageId,
            'updatedFields' => array_keys($updatedFields)
        ]);

        // Get all URLs for this page
        $urls = $this->caddyCacheService->getPageUrls($pageId);

        if ($urls !== []) {
            $this->caddyCacheService->invalidateUrls($urls);
        }

        // Also invalidate parent pages if menu structure might be affected
        if (isset($updatedFields['nav_title']) ||
            isset($updatedFields['title']) ||
            isset($updatedFields['nav_hide']) ||
            isset($updatedFields['hidden'])) {

            $this->invalidateNavigationCache($pageId);
        }
    }

    /**
     * Handle content element updates
     *
     * @param array<string, mixed> $updatedFields Changed fields of the tt_content record
     */
    private function handleContentUpdate(int $contentId, array $updatedFields): void
    {
        // Get the page this content belongs to
        $pageId = $this->getPageIdFromContentElement($contentId);

        if ($pageId > 0) {
            $this->logger->info('Caddy Cache: Content updated', [
                'contentId' => $contentId,
                'pageId' => $pageId,
                'updatedFields' => array_keys($updatedFields)
            ]);

            $urls = $this->caddyCacheService->getPageUrls($pageId);

            if ($urls !== []) {
                $this->caddyCacheService->invalidateUrls($urls);
            }
        }
    }

    /**
     * Handle updates to related records that might affect cache
     *
     * @param array<string, mixed> $updatedFields Changed fields of the related record
     */
    private function handleRelatedUpdate(string $table, int $recordId, array $updatedFields): void
    {
        $this->logger->info('Caddy Cache: Related record updated', [
            'table' => $table,
            'recordId' => $recordId,
            'updatedFields' => array_keys($updatedFields)
        ]);

        // For file and user updates, we might want to invalidate more broadly
        // depending on how they're used across the site
        if ($table === 'sys_file' || $table === 'sys_file_reference') {
            // Could implement more specific logic here based on file usage
            $this->caddyCacheService->invalidateByTags(['file:' . $recordId]);
        }
    }

    /**
     * Invalidate navigation cache for parent pages
     */
    private function invalidateNavigationCache(int $pageId): void
    {
        // This could be extended to walk up the page tree and invalidate
        // parent pages that might show this page in their navigation
        $this->caddyCacheService->invalidateByTags(['navigation', 'page:' . $pageId]);
    }

    /**
     * Get page ID from content element
     */
    private function getPageIdFromContentElement(int $contentId): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');

        $result = $queryBuilder
            ->select('pid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($contentId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)($result ?? 0);
    }
}
