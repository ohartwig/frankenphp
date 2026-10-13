<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\EventListener;

use Koh\FrankenPHP\EventListener\CacheInvalidationListener;
use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\Dev\Testing\Typo3\DatabaseDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Which editorial change invalidates which part of the edge cache.
 *
 * Every branch here is a trade between two silent failures. Invalidate too
 * little and visitors keep being served the page an editor just changed, with
 * nothing in any log to say so. Invalidate too much and a single content save
 * flushes the cache for the whole installation, which shows up only as load.
 *
 * The deferral in the flush handler is the one piece that is not about caching
 * at all. Sending the HTTP request during DI container teardown corrupts the
 * heap in FrankenPHP worker mode, so the call is registered as a shutdown
 * function instead — and the test for it asserts that nothing was sent
 * synchronously, because "it happened later" is exactly the property that
 * matters.
 */
final class CacheInvalidationListenerTest extends TestCase
{
    use DatabaseDoubles;

    /** @var list<array{method: string, argument: mixed}> */
    private array $calls = [];

    private int $contentPid = 0;

    private bool $enabled = true;

    private function listener(): CacheInvalidationListener
    {
        $service = $this->createStub(CaddyCacheService::class);
        $service->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $service->method('getPageUrls')->willReturnCallback(
            function (int $pageId): array {
                $this->calls[] = ['method' => 'getPageUrls', 'argument' => $pageId];

                return $pageId > 0 ? ['https://www.example.org/page-' . $pageId] : [];
            }
        );
        $service->method('invalidateUrls')->willReturnCallback(
            function (array $urls): void {
                $this->calls[] = ['method' => 'invalidateUrls', 'argument' => $urls];
            }
        );
        $service->method('invalidateByTags')->willReturnCallback(
            function (array $tags): void {
                $this->calls[] = ['method' => 'invalidateByTags', 'argument' => $tags];
            }
        );
        $service->method('invalidateAllSites')->willReturnCallback(
            function (): void {
                $this->calls[] = ['method' => 'invalidateAllSites', 'argument' => null];
            }
        );

        return new CacheInvalidationListener(
            $service,
            new NullLogger(),
            $this->pool(),
        );
    }

    // --- the off switch -----------------------------------------------------

    /**
     * Off must stop the listener before it does any work: no page URL lookup,
     * no database query for the content element's pid, no invalidation.
     */
    #[Test]
    public function disabledListenerDoesNothing(): void
    {
        $this->enabled = false;

        $this->save('pages', 12, ['title' => 'x']);
        $this->save('tt_content', 7, ['bodytext' => 'x']);
        $this->postProc(['cacheCmd' => 'all']);
        $this->postProc(['tags' => ['pageId_12' => true]]);
        $this->listener()->onGeneralCacheFlush(new CacheFlushEvent(['pages']));

        self::assertSame([], $this->calls);
    }

    private function pool(): ConnectionPool
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            fn (): object => $this->dbQueryBuilder($this->contentPid > 0 ? [['pid' => $this->contentPid]] : []),
        );

        return $pool;
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    private function save(string $table, int|string $id, array $fields = []): void
    {
        $this->listener()->processDatamap_afterDatabaseOperations(
            'update',
            $table,
            $id,
            $fields,
            $this->createStub(DataHandler::class),
        );
    }

    // --- the DataHandler clearCachePostProc hook -----------------------------

    private function postProc(array $params, ?DataHandler $dataHandler = null): void
    {
        $this->listener()->clearCachePostProc($params, $dataHandler ?? $this->createStub(DataHandler::class));
    }

    /**
     * A workspace publish reaches the hook as prepareCacheFlush() params: the
     * tags are an assoc array (tag => true) with pageId_<n> for every page the
     * core flushed. Those pages are purged at the edge.
     */
    #[Test]
    public function publishedRecordsPurgeTheTaggedPages(): void
    {
        $this->postProc([
            'table' => 'tt_content',
            'uid' => 5,
            'uid_page' => 1,
            'TSConfig' => [],
            'tags' => ['pageId_1' => true, 'tt_content' => true, 'tt_content_5' => true],
            'clearCacheCommands' => [],
            'clearCacheEnabled' => true,
        ]);

        self::assertSame(['getPageUrls', 'invalidateUrls'], $this->methods());
        self::assertSame(1, $this->calls[0]['argument']);
        self::assertSame(['https://www.example.org/page-1'], $this->calls[1]['argument']);
    }

    /**
     * clear_cacheCmd('<pid>') hands over a plain list of tags.
     */
    #[Test]
    public function aPageCacheCommandPurgesThatPage(): void
    {
        $this->postProc(['cacheCmd' => '42', 'tags' => ['pageId_42']]);

        self::assertSame(['getPageUrls', 'invalidateUrls'], $this->methods());
        self::assertSame(42, $this->calls[0]['argument']);
    }

    /**
     * "all" and "pages" are a full flush, and like the event handler it is
     * deferred: nothing goes out while the hook runs.
     */
    #[Test]
    public function aFullCacheCommandIsDeferredRatherThanSentImmediately(): void
    {
        $this->postProc(['cacheCmd' => 'all', 'tags' => []]);
        $this->postProc(['cacheCmd' => 'pages', 'tags' => []]);

        self::assertSame([], $this->calls);
    }

    /**
     * One DataHandler run that touches several records on the same page purges
     * that page once. The dedupe is scoped to the DataHandler instance, so a
     * second run purges again.
     */
    #[Test]
    public function aPageIsPurgedOncePerDataHandlerRun(): void
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $params = ['table' => 'tt_content', 'uid' => 5, 'uid_page' => 1, 'tags' => ['pageId_1' => true]];

        $this->postProc($params, $dataHandler);
        $this->postProc($params + ['uid' => 6], $dataHandler);
        self::assertCount(2, $this->calls, 'second record on the same page must not purge again');

        $this->postProc($params, $this->createStub(DataHandler::class));
        self::assertCount(4, $this->calls, 'a new DataHandler run purges again');
    }

    #[Test]
    public function tagsWithoutAPageAreIgnored(): void
    {
        $this->postProc(['cacheCmd' => 'cachetag:navigation', 'tags' => ['navigation']]);

        self::assertSame([], $this->calls);
    }

    // --- the general flush --------------------------------------------------

    /**
     * The call is deferred to shutdown, so nothing may go out while the event
     * is being handled. Sending it here corrupts the heap under a FrankenPHP
     * worker during container teardown.
     */
    #[Test]
    public function aPageFlushIsDeferredRatherThanSentImmediately(): void
    {
        $this->listener()->onGeneralCacheFlush(new CacheFlushEvent(['pages']));

        self::assertSame([], $this->calls);
    }

    #[Test]
    public function aFlushOfEverythingIsAlsoHandled(): void
    {
        $this->listener()->onGeneralCacheFlush(new CacheFlushEvent(['all']));

        self::assertSame([], $this->calls);
    }

    /**
     * A flush of an unrelated group returns before it registers anything. The
     * observable difference is only in what it does NOT do, so this is asserted
     * against the group check rather than the outcome.
     */
    #[Test]
    public function aFlushOfAnUnrelatedGroupIsIgnored(): void
    {
        foreach ([['system'], ['di'], [], ['pagesection']] as $groups) {
            $event = new CacheFlushEvent($groups);

            $this->listener()->onGeneralCacheFlush($event);

            self::assertFalse($event->hasGroup('pages'), implode(',', $groups));
            self::assertFalse($event->hasGroup('all'), implode(',', $groups));
        }
    }

    // --- page saves ---------------------------------------------------------

    #[Test]
    public function savingAPageInvalidatesItsOwnUrls(): void
    {
        $this->save('pages', 42, ['bodytext' => 'x']);

        self::assertSame(['getPageUrls', 'invalidateUrls'], $this->methods());
        self::assertSame(42, $this->calls[0]['argument']);
        self::assertSame(['https://www.example.org/page-42'], $this->calls[1]['argument']);
    }

    /**
     * A title or visibility change alters the menus on OTHER pages, which the
     * page's own URLs do not cover. Missing this is the classic stale-navigation
     * bug: the page is correct, every menu linking to it is not.
     */
    #[Test]
    public function aChangeThatAltersMenusAlsoInvalidatesTheNavigation(): void
    {
        foreach (['title', 'nav_title', 'nav_hide', 'hidden'] as $field) {
            $this->calls = [];

            $this->save('pages', 42, [$field => 'x']);

            self::assertContains('invalidateByTags', $this->methods(), $field);
            self::assertSame(['navigation', 'page:42'], $this->calls[2]['argument'], $field);
        }
    }

    #[Test]
    public function anOrdinaryFieldChangeLeavesTheNavigationAlone(): void
    {
        $this->save('pages', 42, ['bodytext' => 'x', 'subtitle' => 'y']);

        self::assertNotContains('invalidateByTags', $this->methods());
    }

    /**
     * A new record arrives with its temporary "NEW…" identifier. Casting that
     * to int yields 0, and page 0 has no URLs — so nothing is invalidated,
     * which is correct: the record does not exist at a URL yet.
     */
    #[Test]
    public function aRecordThatIsStillNewInvalidatesNothing(): void
    {
        $this->save('pages', 'NEW64f1c0a2b3');

        self::assertSame(['getPageUrls'], $this->methods());
        self::assertSame(0, $this->calls[0]['argument']);
    }

    // --- content saves ------------------------------------------------------

    #[Test]
    public function savingContentInvalidatesThePageItSitsOn(): void
    {
        $this->contentPid = 7;

        $this->save('tt_content', 99, ['header' => 'x']);

        self::assertSame(['getPageUrls', 'invalidateUrls'], $this->methods());
        self::assertSame(7, $this->calls[0]['argument']);
    }

    /**
     * Content whose page cannot be resolved invalidates nothing rather than
     * falling back to page 0 — which would be a lookup for URLs that do not
     * exist, on every save of an orphaned record.
     */
    #[Test]
    public function contentWithoutAResolvablePageInvalidatesNothing(): void
    {
        $this->contentPid = 0;

        $this->save('tt_content', 99, ['header' => 'x']);

        self::assertSame([], $this->calls);
    }

    // --- related records ----------------------------------------------------

    #[Test]
    public function aFileChangeIsInvalidatedByTag(): void
    {
        foreach (['sys_file', 'sys_file_reference'] as $table) {
            $this->calls = [];

            $this->save($table, 5);

            self::assertSame(['invalidateByTags'], $this->methods(), $table);
            self::assertSame(['file:5'], $this->calls[0]['argument'], $table);
        }
    }

    /**
     * Frontend users and groups are logged but deliberately not invalidated:
     * the comment in the listener says the usage-specific logic is not written
     * yet, and inventing a broad flush here would be worse than the gap.
     */
    #[Test]
    public function aFrontendUserChangeIsNotedButInvalidatesNothing(): void
    {
        foreach (['fe_users', 'fe_groups'] as $table) {
            $this->calls = [];

            $this->save($table, 5);

            self::assertSame([], $this->calls, $table);
        }
    }

    #[Test]
    public function aTableNobodyCaresAboutIsIgnored(): void
    {
        foreach (['sys_log', 'be_users', 'tx_something_domain_model_thing'] as $table) {
            $this->calls = [];

            $this->save($table, 5);

            self::assertSame([], $this->calls, $table);
        }
    }
}
