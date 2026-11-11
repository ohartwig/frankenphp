<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\EventListener;

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\FrankenPHP\Service\PurgeAudit;

class CacheInvalidationListener
{
    /**
     * Pages already purged for a given DataHandler run. See clearCachePostProc().
     *
     * @var \WeakMap<DataHandler, array<int, true>>|null
     */
    private static ?\WeakMap $purgedPerDataHandler = null;

    /**
     * Hosts whose query-string variants were purged in a DataHandler run. See
     * purgePages().
     *
     * @var \WeakMap<DataHandler, array<string, true>>|null
     */
    private static ?\WeakMap $queryVariantHostsPerDataHandler = null;

    /**
     * Fields each page record changed in a DataHandler run, by uid. See
     * edgePagesForPageRecord().
     *
     * @var \WeakMap<DataHandler, array<int, list<string>>>|null
     */
    private static ?\WeakMap $changedPageFields = null;

    /**
     * Page fields a menu, breadcrumb or teaser on another page may show, or
     * that decide whether the page appears there at all. A change to any of
     * them keeps the core's sibling purge at the edge.
     */
    private const MENU_FIELDS = [
        'title', 'nav_title', 'subtitle', 'abstract', 'media', 'slug', 'sorting', 'pid',
        'hidden', 'nav_hide', 'starttime', 'endtime', 'fe_group', 'doktype',
        'shortcut', 'shortcut_mode', 'url', 'target', 'l18n_cfg', 'extendToSubpages',
        'categories', 'lastUpdated',
    ];

    public function __construct(
        private readonly CaddyCacheService $caddyCacheService,
        private readonly LoggerInterface $logger,
        private readonly ConnectionPool $connectionPool,
        private readonly MessageBusInterface $messageBus,
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
        $this->deferFullFlush('CacheFlushEvent groups=' . implode(',', $event->getGroups()));
    }

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
     * Everything else purges the URLs of the tagged pages, with one exception:
     * see edgePagesForPageRecord().
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
            $this->deferFullFlush('clear_cacheCmd=' . $cacheCmd);

            return;
        }
        // prepareCacheFlush() for a record the core leaves alone: a workspace
        // version, or a page tree with TCEMAIN.clearCache_disable. The live
        // pages did not change, so neither does the edge.
        if (($params['clearCacheEnabled'] ?? true) === false) {
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

        if (($params['table'] ?? null) === 'pages') {
            $this->purgePages($this->edgePagesForPageRecord($params, $pageIds, $dataHandler), $dataHandler);

            return;
        }

        $this->purgePages(array_keys($pageIds), $dataHandler);
    }

    /**
     * For a saved page record the core flushes the page, its parent and every
     * sibling, because any of them may show the page in a menu. Our own page
     * cache keeps doing that; the edge only follows when the save changed
     * something a menu or a teaser shows. Otherwise it purges the page and its
     * parent, which lists it. On the blog tree that is 2 pages instead of 170
     * for each saved SEO field.
     *
     * Only a save processDatamap_afterDatabaseOperations() has seen counts as
     * known. A move, delete, publish or new page arrives here without one and
     * keeps the siblings -- unless the page TSconfig says that no sibling in
     * this part of the tree shows the others, see siblingsShowEachOther().
     *
     * @param array<string, mixed> $params
     * @param array<int, true>     $pageIds
     *
     * @return list<int>
     */
    private function edgePagesForPageRecord(array $params, array $pageIds, DataHandler $dataHandler): array
    {
        $pageUid = (int)($params['uid_page'] ?? 0);
        $changed = (self::$changedPageFields[$dataHandler] ?? [])[(int)($params['uid'] ?? 0)] ?? null;
        $menusMayChange = $changed === null || array_intersect($changed, self::MENU_FIELDS) !== [];
        if ($menusMayChange && self::siblingsShowEachOther($params)) {
            return array_keys($pageIds);
        }

        $narrowed = [$pageUid];
        $parent = (int)($this->pageRow($pageUid)['pid'] ?? 0);
        if (isset($pageIds[$parent])) {
            $narrowed[] = $parent;
        }

        return $narrowed;
    }

    /**
     * Whether a page's siblings may show it, so that a change to it reaches
     * them. That is the core's assumption, and the default here.
     *
     * A tree of articles is where it is wrong and where it costs most: each
     * article lists hand-picked related pages, none lists its siblings, and
     * only the parent's list shows a new or changed one. Publishing one new
     * article there purged every other article at the edge -- twice, once for
     * each language. Its page TSconfig can say so:
     *
     *     TCEMAIN.edgeCache.purgeSiblings = 0
     *
     * and the edge then purges the page and its parent, as for a save that
     * changed no menu field. The page cache still flushes the siblings; this
     * only decides what leaves the edge cache. The TSconfig is the one the core
     * read for this flush, that of the page itself, so a condition on the
     * parent (`[123 in tree.rootLineParentIds]`) covers the articles without
     * covering the parent's own siblings.
     *
     * @param array<string, mixed> $params
     */
    private static function siblingsShowEachOther(array $params): bool
    {
        $tsConfig = $params['TSConfig'] ?? null;
        $edgeCache = is_array($tsConfig) ? ($tsConfig['edgeCache.'] ?? null) : null;
        $value = is_array($edgeCache) ? ($edgeCache['purgeSiblings'] ?? null) : null;

        return !is_scalar($value) || trim((string)$value) !== '0';
    }

    /**
     * Purge the URLs of these pages, each at most once per DataHandler run: a
     * save that touches five records on one page, or reaches this through both
     * hooks, purges it once. The query-string variants of a host go once per
     * run as well, however many pages on it were purged. WeakMaps rather than
     * plain statics because a worker process outlives the request, and entries
     * die with their DataHandler.
     *
     * The purges themselves go out as one PurgeEdgeCache per call. TYPO3
     * handles it in the request unless EdgePurgeRouting sends it to the
     * koh/typo3-async consumer; the URLs are resolved here either way, while
     * the DataHandler's view of the pages is current.
     *
     * @param list<int> $pageIds
     */
    private function purgePages(array $pageIds, DataHandler $dataHandler): void
    {
        self::$purgedPerDataHandler ??= new \WeakMap();
        self::$queryVariantHostsPerDataHandler ??= new \WeakMap();
        /** @var array<int, true> $done */
        $done = self::$purgedPerDataHandler[$dataHandler] ?? [];
        /** @var array<string, true> $hostsDone */
        $hostsDone = self::$queryVariantHostsPerDataHandler[$dataHandler] ?? [];

        $pageUrls = [];
        $newHosts = [];
        foreach ($pageIds as $pageId) {
            if ($pageId <= 0 || isset($done[$pageId])) {
                continue;
            }
            $done[$pageId] = true;
            $urls = $this->caddyCacheService->getPageUrls($pageId);
            if ($urls === []) {
                continue;
            }
            $pageUrls[] = $urls;
            foreach ($urls as $url) {
                $host = CaddyCacheService::hostOf($url);
                if (!isset($hostsDone[$host])) {
                    $hostsDone[$host] = true;
                    $newHosts[] = $url;
                }
            }
        }
        if ($pageUrls !== []) {
            $this->messageBus->dispatch(new PurgeEdgeCache($pageUrls, $newHosts, PurgeAudit::originContext()));
        }

        self::$purgedPerDataHandler[$dataHandler] = $done;
        self::$queryVariantHostsPerDataHandler[$dataHandler] = $hostsDone;
    }

    private function deferFullFlush(string $reason): void
    {
        // The flush itself runs at shutdown, where the code path that asked
        // for it is gone; record it now.
        PurgeAudit::record('full-flush-scheduled', ['reason' => $reason]);
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
     * Runs before the core flushes caches for the save, so it is also where
     * clearCachePostProc() learns which fields of a page record changed.
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

        // A new record still has its "NEW…" placeholder here, which is 0 as a
        // uid: nothing to purge or remember. Its flush hook call, with the
        // real uid, purges the pages that will list it.
        $uid = (int)$id;
        if ($table === 'pages') {
            self::$changedPageFields ??= new \WeakMap();
            $known = self::$changedPageFields[$dataHandler] ?? [];
            $known[$uid] = [...$known[$uid] ?? [], ...array_keys($fieldArray)];
            self::$changedPageFields[$dataHandler] = $known;
        }

        if (!$this->isLive($dataHandler)) {
            return;
        }

        if ($table === 'pages') {
            $this->purgePages([$this->defaultLanguagePageId($uid)], $dataHandler);
        } elseif ($table === 'tt_content') {
            $this->purgePages([$this->getPageIdFromContentElement($uid)], $dataHandler);
        }
    }

    /**
     * A save in a workspace changes a version, not the live page the edge
     * holds; its publish purges via clearCachePostProc().
     */
    private function isLive(DataHandler $dataHandler): bool
    {
        // BE_USER is unset on a DataHandler that was never started (some CLI
        // paths); without a user there is no workspace either.
        return !isset($dataHandler->BE_USER) || $dataHandler->BE_USER->workspace === 0;
    }

    private function defaultLanguagePageId(int $pageId): int
    {
        $parent = (int)($this->pageRow($pageId)['l10n_parent'] ?? 0);

        return $parent === 0 ? $pageId : $parent;
    }


    /**
     * @return array<string, mixed>
     */
    private function pageRow(int $pageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('pid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : [];
    }

    /**
     * Get page ID from content element.
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
