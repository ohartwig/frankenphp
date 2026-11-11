<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Page;

use Koh\FrankenPHP\Runtime\WorkerSwitch;
use TYPO3\CMS\Core\Page\ContentAreaCollection;
use TYPO3\CMS\Core\Page\PageLayout;
use TYPO3\CMS\Core\Page\PageLayoutResolver;

/**
 * Resolves a page's backend layout on first use instead of on every request
 * (KOH_LAZY_PAGE_LAYOUT=1).
 *
 * TYPO3's PageInformationFactory resolves the layout of every frontend page
 * while it builds the page information, also for a request the page cache
 * then answers, which never looks at it. For a layout from page TSconfig
 * (`pagets__*`, the usual kind) that means building the page TSconfig of the
 * page, conditions included, for nothing. Here the layout is a PHP lazy proxy
 * instead: a PageLayout that runs TYPO3's own resolution the first time
 * anything reads it (PAGEVIEW, the page-content data processor), and returns
 * exactly what that resolution returns.
 *
 * Only `pagets__*` layouts are resolved lazily. Every other identifier
 * (`default`, `none`, a backend_layout record, another data provider's) is
 * cheap and goes the eager way, unchanged.
 *
 * The one difference: a `pagets__*` identifier that page TSconfig does not
 * define. TYPO3 then sets no layout at all (getPageLayout() is null), which a
 * proxy cannot be after the fact. The proxy resolves to a layout with that
 * identifier, an empty title and no content areas, and logs a
 * `koh_page_layout_missing` line. PageContentFetchingProcessor treats a null
 * layout as no content areas anyway; PAGEVIEW hands its template an empty
 * collection instead of null, so `{content.main}` stays empty either way and
 * only a template that tests `{content}` itself could tell. A page with a
 * missing layout is a misconfiguration the log line points at.
 *
 * Needs PHP 8.4 (lazy objects) and TYPO3 14 (ContentAreaCollection);
 * otherwise, and with the switch off, this is TYPO3's resolver unchanged.
 *
 * @internal
 */
readonly class LazyPageLayoutResolver extends PageLayoutResolver
{
    public const ENV = 'KOH_LAZY_PAGE_LAYOUT';

    private const LAZY_PREFIX = 'pagets__';

    /**
     * @param array<array-key, mixed> $pageRecord
     * @param array<array-key, mixed> $rootLine
     */
    public function getLayoutForPage(array $pageRecord, array $rootLine): ?PageLayout
    {
        if (!self::isEnabled()) {
            return parent::getLayoutForPage($pageRecord, $rootLine);
        }
        $identifier = $this->getLayoutIdentifierForPage($pageRecord, $rootLine);
        if (!str_starts_with($identifier, self::LAZY_PREFIX)) {
            return parent::getLayoutForPage($pageRecord, $rootLine);
        }

        // Lazy objects: PHP 8.4+, checked in isEnabled().
        return (new \ReflectionClass(PageLayout::class))->newLazyProxy(
            fn(): PageLayout => parent::getLayoutForPage($pageRecord, $rootLine) ?? self::missing($identifier, (int) ($pageRecord['uid'] ?? 0)),
        );
    }

    public static function isEnabled(): bool
    {
        return \PHP_VERSION_ID >= 80400
            && class_exists(ContentAreaCollection::class)
            && WorkerSwitch::isOn(self::ENV);
    }

    private static function missing(string $identifier, int $pageId): PageLayout
    {
        error_log((string) json_encode(['koh_page_layout_missing' => $identifier, 'page' => $pageId], JSON_UNESCAPED_SLASHES));

        return new PageLayout($identifier, '', new ContentAreaCollection([]));
    }
}
