<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\EventListener;

use Koh\FrankenPHP\EventListener\CacheInvalidationListener;
use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\MessageHandler\PurgeEdgeCacheHandler;
use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\FrankenPHP\Service\PurgeAudit;
use Koh\Dev\Testing\Typo3\DatabaseDoubles;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
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
#[AllowMockObjectsWithoutExpectations]
final class CacheInvalidationListenerTest extends TestCase
{
    use DatabaseDoubles;

    /** @var list<array{method: string, argument: mixed}> */
    private array $calls = [];

    private int $contentPid = 0;

    /** @var list<bool> */
    private array $withQueryVariants = [];

    /** @var list<list<string>> */
    private array $queryVariantCalls = [];

    /** @var array<string, mixed> The pages row (pid, l10n_parent) the listener looks up. */
    private array $pageRow = [];

    private bool $enabled = true;

    /** @var list<PurgeEdgeCache> what the listener dispatched, in order */
    private array $dispatched = [];

    /** Whether the bus double hands a message to the handler, like TYPO3's synchronous default routing. */
    private bool $handleInline = true;

    /** KOH_EDGE_PURGE_BY_TAG as the listener sees it. */
    private bool $byTag = false;

    private function listener(): CacheInvalidationListener
    {
        $service = $this->createStub(CaddyCacheService::class);
        $service->method('isEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $service->method('getPageUrls')->willReturnCallback(
            function (int $pageId): array {
                $this->calls[] = ['method' => 'getPageUrls', 'argument' => $pageId];

                if ($pageId >= 500) {
                    return ['https://www.example.org/page-' . $pageId, 'https://en.example.org/page-' . $pageId];
                }

                return $pageId > 0 ? ['https://www.example.org/page-' . $pageId] : [];
            }
        );
        $service->method('invalidateUrls')->willReturnCallback(
            function (array $urls, bool $withQueryVariants = true): void {
                $this->calls[] = ['method' => 'invalidateUrls', 'argument' => $urls];
                $this->withQueryVariants[] = $withQueryVariants;
            }
        );
        $service->method('invalidateQueryVariants')->willReturnCallback(
            function (array $urls): void {
                $this->queryVariantCalls[] = $urls;
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
        $service->method('invalidateSurrogateKeys')->willReturnCallback(
            function (array $tags): void {
                $this->calls[] = ['method' => 'invalidateSurrogateKeys', 'argument' => $tags];
            }
        );

        return new CacheInvalidationListener(
            $service,
            new NullLogger(),
            $this->pool(),
            $this->bus(new PurgeEdgeCacheHandler($service)),
            $this->byTag,
        );
    }

    /**
     * TYPO3's default routing in miniature: the message is handled where it
     * is dispatched. Every purge assertion in this file therefore runs through
     * message and handler, which is what keeps the synchronous path identical
     * to the inline purges it replaced.
     */
    private function bus(PurgeEdgeCacheHandler $handler): MessageBusInterface
    {
        return new class ($this, $handler) implements MessageBusInterface {
            public function __construct(
                private readonly CacheInvalidationListenerTest $test,
                private readonly PurgeEdgeCacheHandler $handler,
            ) {}

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                \assert($message instanceof PurgeEdgeCache);
                $this->test->dispatched($message);
                if ($this->test->handlesInline()) {
                    ($this->handler)($message);
                }

                return new Envelope($message, $stamps);
            }
        };
    }

    public function dispatched(PurgeEdgeCache $message): void
    {
        $this->dispatched[] = $message;
    }

    public function handlesInline(): bool
    {
        return $this->handleInline;
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
            fn (string $table): object => $table === 'pages'
                ? $this->dbQueryBuilder($this->pageRow !== [] ? [$this->pageRow] : [])
                : $this->dbQueryBuilder($this->contentPid > 0 ? [['pid' => $this->contentPid]] : []),
        );

        return $pool;
    }

    /** @return list<string> */
    private function methods(): array
    {
        return array_column($this->calls, 'method');
    }

    private function save(string $table, int|string $id, array $fields = [], ?DataHandler $dataHandler = null): void
    {
        $this->listener()->processDatamap_afterDatabaseOperations(
            'update',
            $table,
            $id,
            $fields,
            $dataHandler ?? $this->createStub(DataHandler::class),
        );
    }

    /** @return list<int> the page ids whose URLs were looked up, in order */
    private function purgedPages(): array
    {
        return array_values(array_map(
            static fn (array $call): int => (int)$call['argument'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'getPageUrls'),
        ));
    }

    /**
     * What prepareCacheFlush() hands over for a saved page record: the page
     * (uid_page, default language), its parent and every sibling as tags.
     *
     * @return array<string, mixed>
     */
    private function pageFlush(int $uid, int $pageUid = 0): array
    {
        return [
            'table' => 'pages',
            'uid' => $uid,
            'uid_page' => $pageUid > 0 ? $pageUid : $uid,
            'TSConfig' => [],
            'tags' => ['pageId_41' => true, 'pageId_42' => true, 'pageId_43' => true, 'pageId_10' => true, 'pages' => true],
            'clearCacheCommands' => [],
            'clearCacheEnabled' => true,
        ];
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
     * The core flushes every sibling of a saved page, because any of them may
     * show it in a menu. At the edge that was 170 pages for each saved SEO
     * field on the blog tree. A save that changed nothing a menu or teaser
     * shows purges the page and its parent, which lists it.
     */
    #[Test]
    public function aPageSaveWithoutMenuChangesPurgesOnlyThePageAndItsParent(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 42, ['description' => 'x', 'seo_title' => 'y'], $dataHandler);
        $this->postProc($this->pageFlush(42), $dataHandler);

        self::assertSame([42, 10], $this->purgedPages());
    }

    /**
     * A title, visibility, slug or position change alters the menus on OTHER
     * pages, which the page's own URLs do not cover. Missing this is the
     * classic stale-navigation bug: the page is correct, every menu linking to
     * it is not. So these keep the core's siblings.
     */
    #[Test]
    public function aChangeThatAltersMenusKeepsTheSiblings(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        foreach (['title', 'nav_title', 'nav_hide', 'hidden', 'slug', 'sorting', 'abstract', 'media', 'starttime', 'fe_group'] as $field) {
            $this->calls = [];
            $dataHandler = $this->createStub(DataHandler::class);

            $this->save('pages', 42, ['description' => 'x', $field => 'y'], $dataHandler);
            $this->postProc($this->pageFlush(42), $dataHandler);

            self::assertSame([42, 41, 43, 10], $this->purgedPages(), $field);
        }
    }

    /**
     * A move, delete, publish or new page reaches the hook without a save the
     * listener has seen, so it cannot know what changed and keeps the siblings.
     */
    #[Test]
    public function aPageChangeWithoutAKnownSaveKeepsTheSiblings(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];

        $this->postProc($this->pageFlush(42));

        self::assertSame([41, 42, 43, 10], $this->purgedPages());
    }

    /**
     * A tree of articles whose pages never list each other says so in its
     * page TSconfig. Publishing a new article there then purges the article
     * and the list it appears in, not every other article: on one tree that
     * was 115 pages in two languages, for each of the publish's runs.
     */
    #[Test]
    public function aNewPageInATreeWhoseSiblingsDoNotListEachOtherPurgesOnlyThePageAndItsParent(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $flush = $this->pageFlush(42);
        $flush['TSConfig'] = ['edgeCache.' => ['purgeSiblings' => '0']];

        $this->postProc($flush);

        self::assertSame([42, 10], $this->purgedPages());
    }

    /**
     * The same holds for a field a menu shows: no sibling shows it.
     */
    #[Test]
    public function aMenuChangeInSuchATreeAlsoLeavesTheSiblingsAlone(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);
        $flush = $this->pageFlush(42);
        $flush['TSConfig'] = ['clearCache_pageGrandParent' => '1', 'edgeCache.' => ['purgeSiblings' => 0]];

        $this->save('pages', 42, ['title' => 'x'], $dataHandler);
        $this->postProc($flush, $dataHandler);

        self::assertSame([42, 10], $this->purgedPages());
    }

    /**
     * Anything but an explicit 0 keeps the core's assumption: a typo must
     * leave a navigation stale nowhere.
     */
    #[Test]
    public function onlyAnExplicitZeroDropsTheSiblings(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        foreach (['1', '', 'no', ['x' => '0']] as $value) {
            $this->calls = [];
            $flush = $this->pageFlush(42);
            $flush['TSConfig'] = ['edgeCache.' => ['purgeSiblings' => $value]];

            $this->postProc($flush);

            self::assertSame([41, 42, 43, 10], $this->purgedPages(), var_export($value, true));
        }
        foreach ([['edgeCache.' => []], ['edgeCache' => '0'], 'garbage'] as $tsConfig) {
            $this->calls = [];
            $flush = $this->pageFlush(42);
            $flush['TSConfig'] = $tsConfig;

            $this->postProc($flush);

            self::assertSame([41, 42, 43, 10], $this->purgedPages(), var_export($tsConfig, true));
        }
    }

    /**
     * The switch is about page records. A content element on a page in such
     * a tree keeps every page the core tagged.
     */
    #[Test]
    public function theSwitchLeavesContentFlushesAlone(): void
    {
        $this->contentPid = 7;
        $this->postProc([
            'table' => 'tt_content',
            'uid' => 99,
            'uid_page' => 7,
            'TSConfig' => ['edgeCache.' => ['purgeSiblings' => '0']],
            'tags' => ['pageId_7' => true, 'pageId_8' => true],
            'clearCacheEnabled' => true,
        ]);

        self::assertSame([7, 8], $this->purgedPages());
    }

    /**
     * A translated page record has its own uid; the core names the default
     * page in uid_page, and that is what has URLs.
     */
    #[Test]
    public function aTranslatedPageSaveAlsoPurgesOnlyThePageAndItsParent(): void
    {
        // As the database hands them over: strings.
        $this->pageRow = ['pid' => '10', 'l10n_parent' => '42'];
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 142, ['description' => 'x'], $dataHandler);
        $this->postProc($this->pageFlush(142, 42), $dataHandler);

        self::assertSame([42, 10], $this->purgedPages());
    }

    /**
     * A top-level page's parent is the tree root, which the core does not tag
     * (pid 0 has no page cache): the page alone.
     */
    #[Test]
    public function aParentTheCoreDidNotFlushIsNotAddedEither(): void
    {
        $this->pageRow = ['pid' => 7, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 42, ['description' => 'x'], $dataHandler);
        $this->postProc($this->pageFlush(42), $dataHandler);

        self::assertSame([42], $this->purgedPages());
    }

    /**
     * A save in a workspace changes a version, not the live page the edge
     * holds, and the core reports its flush as disabled. Neither purges; the
     * publish does, via clearCachePostProc with the live tags.
     */
    #[Test]
    public function aWorkspaceSaveLeavesTheEdgeAlone(): void
    {
        $this->contentPid = 7;
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->workspace = 1;
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;

        $this->save('pages', 42, ['title' => 'x'], $dataHandler);
        $this->save('tt_content', 99, ['header' => 'x'], $dataHandler);
        $this->postProc(['table' => 'tt_content', 'uid' => 99, 'uid_page' => 7, 'tags' => ['pageId_7' => true], 'clearCacheEnabled' => false], $dataHandler);

        self::assertSame([], $this->calls);
    }

    /**
     * Workspace 0 is live: a save there purges.
     */
    #[Test]
    public function aLiveSaveByABackendUserPurges(): void
    {
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->workspace = 0;
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;

        $this->save('pages', 42, ['description' => 'x'], $dataHandler);

        self::assertSame([42], $this->purgedPages());
    }

    /**
     * A version saved in a workspace and published in the same run: the save
     * purged nothing, so narrowing must still purge the page itself, not only
     * its parent.
     */
    #[Test]
    public function aNarrowedFlushStillPurgesThePageItself(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $backendUser = $this->createStub(BackendUserAuthentication::class);
        $backendUser->workspace = 3;
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->BE_USER = $backendUser;

        $this->save('pages', 42, ['description' => 'x'], $dataHandler);
        self::assertSame([], $this->calls);
        $this->postProc($this->pageFlush(42), $dataHandler);

        self::assertSame([42, 10], $this->purgedPages());
    }

    /**
     * Two saves of one page in a run add up: a menu field in the first keeps
     * the siblings although the second changed only an SEO field.
     */
    #[Test]
    public function changedFieldsAddUpOverARun(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 42, ['title' => 'x'], $dataHandler);
        $this->save('pages', 42, ['description' => 'y'], $dataHandler);
        $this->postProc($this->pageFlush(42), $dataHandler);

        self::assertSame([42, 41, 43, 10], $this->purgedPages());
    }

    /**
     * Narrowing applies to page records only. A content element that happens
     * to share its uid with a saved page keeps every page the core tagged.
     */
    #[Test]
    public function aContentFlushIsNeverNarrowed(): void
    {
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $this->contentPid = 7;
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 99, ['description' => 'x'], $dataHandler);
        $this->postProc(['table' => 'tt_content', 'uid' => 99, 'uid_page' => 7, 'tags' => ['pageId_7' => true, 'pageId_8' => true], 'clearCacheEnabled' => true], $dataHandler);

        self::assertSame([99, 7, 8], $this->purgedPages());
    }

    /**
     * A page whose record cannot be found has no parent to add, even where
     * the core happens to have tagged page 1.
     */
    #[Test]
    public function aPageWithoutARecordIsNarrowedToItself(): void
    {
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('pages', 42, ['description' => 'x'], $dataHandler);
        $flush = $this->pageFlush(42);
        $flush['tags']['pageId_1'] = true;
        $this->postProc($flush, $dataHandler);

        self::assertSame([42], $this->purgedPages());
    }

    /**
     * "all" is a full flush; it does not also purge the pages it lists.
     */
    #[Test]
    public function aFullFlushCommandDoesNotAlsoPurgePages(): void
    {
        $this->postProc(['cacheCmd' => 'all', 'tags' => ['pageId_5' => true], 'uid_page' => 5]);

        self::assertSame([], $this->calls);
    }

    /**
     * The save hook purges the page and the flush hook names it again; within
     * one DataHandler run it goes out once.
     */
    #[Test]
    public function aPageSavedAndFlushedInOneRunIsPurgedOnce(): void
    {
        $this->contentPid = 7;
        $dataHandler = $this->createStub(DataHandler::class);

        $this->save('tt_content', 99, ['header' => 'x'], $dataHandler);
        $this->postProc(['table' => 'tt_content', 'uid' => 99, 'uid_page' => 7, 'tags' => ['pageId_7' => true], 'clearCacheEnabled' => true], $dataHandler);

        self::assertSame([7], $this->purgedPages());
    }

    #[Test]
    public function anOrdinaryFieldChangeLeavesTheNavigationAlone(): void
    {
        $this->save('pages', 42, ['bodytext' => 'x', 'subtitle' => 'y']);

        self::assertNotContains('invalidateByTags', $this->methods());
    }

    /**
     * A new record arrives with its temporary "NEW…" identifier. Casting that
     * to int yields 0, which is not a page — so nothing is invalidated, which
     * is correct: the record does not exist at a URL yet. Its flush hook call
     * (with the real uid) purges the parent and siblings that will list it.
     */
    #[Test]
    public function aRecordThatIsStillNewInvalidatesNothing(): void
    {
        $this->save('pages', 'NEW64f1c0a2b3');

        self::assertSame([], $this->calls);
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

    /**
     * File records used to be purged by a "file:<uid>" tag that nothing emits:
     * each save asked Souin for its whole tag list and logged a warning. Files
     * are FileCacheInvalidationListener's, by path; here they cost nothing.
     */
    #[Test]
    public function aFileRecordChangeIsLeftToTheFileListener(): void
    {
        foreach (['sys_file', 'sys_file_reference'] as $table) {
            $this->calls = [];

            $this->save($table, 5);

            self::assertSame([], $this->calls, $table);
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

    // --- purge audit ---------------------------------------------------------

    /**
     * With the audit switched on, a scheduled full flush says why it was
     * scheduled: the flush itself runs at shutdown, where the cause is gone.
     */
    #[Test]
    public function aScheduledFullFlushRecordsItsReason(): void
    {
        $lines = [];
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        putenv(PurgeAudit::ENV . '=1');
        try {
            $this->listener()->onGeneralCacheFlush(new CacheFlushEvent(['pages', 'system']));
            $this->postProc(['cacheCmd' => 'ALL']);
            $this->postProc(['tags' => ['pageId_12' => true]]);
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        $reasons = array_column(array_filter($lines, static fn (mixed $l): bool => is_array($l) && ($l['koh_purge_audit'] ?? '') === 'full-flush-scheduled'), 'reason');
        self::assertSame(['CacheFlushEvent groups=pages,system', 'clear_cacheCmd=all'], $reasons);
    }

    #[Test]
    public function aDisabledListenerRecordsNothing(): void
    {
        $this->enabled = false;
        $lines = [];
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        putenv(PurgeAudit::ENV . '=1');
        try {
            $this->listener()->onGeneralCacheFlush(new CacheFlushEvent(['all']));
            $this->postProc(['cacheCmd' => 'pages']);
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        self::assertSame([], $lines);
    }

    // --- query-string variants ---------------------------------------------

    /**
     * Each page purges its exact URLs only; the query-string variants of a
     * host go once per DataHandler run, however many pages on that host were
     * purged. A publish of three pages used to send the same host-wide key
     * three times.
     */
    #[Test]
    public function queryVariantsArePurgedOncePerHostAndRun(): void
    {
        $dataHandler = $this->createStub(DataHandler::class);

        $this->postProc(['tags' => ['pageId_501' => true, 'pageId_502' => true]], $dataHandler);
        $this->postProc(['tags' => ['pageId_503' => true]], $dataHandler);

        self::assertSame([false, false, false], $this->withQueryVariants);
        self::assertSame([
            ['https://www.example.org/page-501', 'https://en.example.org/page-501'],
        ], $this->queryVariantCalls);

        $this->postProc(['tags' => ['pageId_501' => true]], $this->createStub(DataHandler::class));
        self::assertCount(2, $this->queryVariantCalls, 'a new run purges them again');
    }

    #[Test]
    public function aRunWithoutUrlsPurgesNoQueryVariants(): void
    {
        $this->postProc(['tags' => ['pageId_0' => true, 'cachetag' => true]]);

        self::assertSame([], $this->queryVariantCalls);
    }

    // --- the message ---------------------------------------------------------

    /**
     * One message per purgePages() call, carrying every page's URLs as the
     * service returned them and the hosts whose query variants are due. The
     * listener sends nothing itself: with the message routed to the consumer,
     * the request makes no HTTP call to the edge at all.
     */
    #[Test]
    public function aRunDispatchesOneMessagePerCallAndSendsNothingItself(): void
    {
        $this->handleInline = false;
        $dataHandler = $this->createStub(DataHandler::class);

        $this->postProc(['tags' => ['pageId_501' => true, 'pageId_12' => true]], $dataHandler);
        $this->postProc(['tags' => ['pageId_501' => true, 'pageId_13' => true]], $dataHandler);

        self::assertSame([], array_values(array_filter($this->methods(), static fn (string $m): bool => $m !== 'getPageUrls')));
        self::assertSame([], $this->queryVariantCalls);
        self::assertCount(2, $this->dispatched);
        self::assertSame([
            ['https://www.example.org/page-501', 'https://en.example.org/page-501'],
            ['https://www.example.org/page-12'],
        ], $this->dispatched[0]->pageUrls);
        self::assertSame(['https://www.example.org/page-501', 'https://en.example.org/page-501'], $this->dispatched[0]->queryVariantUrls);
        self::assertSame([['https://www.example.org/page-13']], $this->dispatched[1]->pageUrls, 'page 501 was done in this run');
        self::assertSame([], $this->dispatched[1]->queryVariantUrls, 'and so were its hosts');
    }

    #[Test]
    public function nothingToPurgeNothingDispatched(): void
    {
        $this->postProc(['tags' => ['pageId_0' => true, 'cachetag' => true]]);
        $dataHandler = $this->createStub(DataHandler::class);
        $this->postProc(['tags' => ['pageId_12' => true]], $dataHandler);
        $this->postProc(['tags' => ['pageId_12' => true]], $dataHandler);

        self::assertCount(1, $this->dispatched, 'only the first call had a page to purge');
    }

    #[Test]
    public function theMessageCarriesTheAuditContextOnlyWhileTheAuditIsOn(): void
    {
        $this->postProc(['tags' => ['pageId_12' => true]]);
        self::assertNull($this->dispatched[0]->origin);

        putenv(PurgeAudit::ENV . '=1');
        PurgeAudit::writeTo(static function (string $line): void {});
        try {
            $this->postProc(['tags' => ['pageId_13' => true]]);
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        $origin = $this->dispatched[1]->origin;
        self::assertIsArray($origin);
        self::assertSame(PHP_SAPI, $origin['sapi']);
        self::assertSame(CacheInvalidationListener::class . '::purgePages', $origin['caller'][0], 'the code path that asked, as an inline purge recorded it');
    }

    /**
     * Synchronous handling writes the audit line an inline purge wrote: same
     * context, no handled_by.
     */
    #[Test]
    public function handledInTheRequestTheAuditLineIsUnchanged(): void
    {
        $lines = [];
        putenv(PurgeAudit::ENV . '=1');
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });
        try {
            $service = $this->createStub(CaddyCacheService::class);
            $service->method('isEnabled')->willReturn(true);
            $service->method('getPageUrls')->willReturn(['https://www.example.org/page-12']);
            $service->method('invalidateUrls')->willReturnCallback(static function (): void {
                PurgeAudit::record('purge', ['key' => 'k']);
            });
            $listener = new CacheInvalidationListener($service, new NullLogger(), $this->pool(), $this->bus(new PurgeEdgeCacheHandler($service)));
            $listener->clearCachePostProc(['tags' => ['pageId_12' => true]], $this->createStub(DataHandler::class));
        } finally {
            putenv(PurgeAudit::ENV);
            PurgeAudit::writeTo(null);
        }

        self::assertCount(1, $lines);
        self::assertArrayNotHasKey('handled_by', $lines[0]);
        self::assertSame(CacheInvalidationListener::class . '::purgePages', $lines[0]['caller'][0]);
    }

    // --- purging by tag (KOH_EDGE_PURGE_BY_TAG) -------------------------------

    /** @return list<list<string>> the Surrogate-Key purges, in order */
    private function purgedTags(): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['argument'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === 'invalidateSurrogateKeys'),
        ));
    }

    /**
     * A content save reaches every URL the page renders under -- pagination,
     * a feed, plugin detail routes -- through the page's tag, and the record
     * tags the core flushed with it. The page URLs are still purged: what was
     * cached before the switch carries no page tag.
     */
    #[Test]
    public function byTagAContentFlushPurgesThePageTagAndTheRecordTags(): void
    {
        $this->byTag = true;

        $this->postProc([
            'table' => 'tt_content',
            'uid' => 5,
            'uid_page' => 1,
            'TSConfig' => [],
            'tags' => ['pageId_1' => true, 'tt_content' => true, 'tt_content_5' => true],
            'clearCacheCommands' => [],
            'clearCacheEnabled' => true,
        ]);

        self::assertSame([['pageId_1', 'tt_content', 'tt_content_5']], $this->purgedTags());
        self::assertSame([1], $this->purgedPages());
        self::assertContains('invalidateUrls', $this->methods());
    }

    /**
     * The host-wide purge of query variants took every other page's variants
     * with it. Under the switch a variant goes with its page's tag instead.
     */
    #[Test]
    public function byTagNoHostWideQueryVariantPurge(): void
    {
        $this->byTag = true;

        $this->postProc(['tags' => ['pageId_501' => true]]);

        self::assertSame([], $this->queryVariantCalls);
        self::assertSame([['pageId_501']], $this->purgedTags());
    }

    /**
     * The narrowing holds for tags: a save that changed no menu field purges
     * the page and its parent by tag, and neither the siblings nor the
     * record tags ("pages", "pages_<uid>", "menuId_<pid>") a menu or a link
     * to the page is tagged with.
     */
    #[Test]
    public function byTagANarrowedPageSaveLeavesSiblingsAndMenuTagsAlone(): void
    {
        $this->byTag = true;
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);
        $flush = $this->pageFlush(42);
        $flush['tags'] += ['pages_42' => true, 'menuId_10' => true];

        $this->save('pages', 42, ['description' => 'x'], $dataHandler);
        $this->postProc($flush, $dataHandler);

        self::assertSame([['pageId_42'], ['pageId_10']], $this->purgedTags());
    }

    /**
     * A menu field change keeps everything the core flushed, menu tags
     * included: every page that shows the menu shows the new title.
     */
    #[Test]
    public function byTagAMenuChangePurgesEverythingTheCoreFlushed(): void
    {
        $this->byTag = true;
        $this->pageRow = ['pid' => 10, 'l10n_parent' => 0];
        $dataHandler = $this->createStub(DataHandler::class);
        $flush = $this->pageFlush(42);
        $flush['tags'] += ['menuId_10' => true];

        $this->save('pages', 42, ['title' => 'x'], $dataHandler);
        $this->postProc($flush, $dataHandler);

        self::assertSame([['pageId_42'], ['pageId_41', 'pageId_43', 'pageId_10', 'pages', 'menuId_10']], $this->purgedTags());
    }

    /**
     * "cachetag:<tag>" flushes a tag no page id stands for; by tag it reaches
     * the edge, which it never did by URL.
     */
    #[Test]
    public function byTagACacheTagCommandIsPurged(): void
    {
        $this->byTag = true;

        $this->postProc(['cacheCmd' => 'cachetag:tx_news', 'tags' => ['tx_news']]);

        self::assertSame([['tx_news']], $this->purgedTags());
        self::assertSame([], $this->purgedPages());
    }

    #[Test]
    public function byTagEachTagOncePerDataHandlerRun(): void
    {
        $this->byTag = true;
        $dataHandler = $this->createStub(DataHandler::class);

        $this->postProc(['tags' => ['pageId_12' => true, 'tt_content_5' => true]], $dataHandler);
        $this->postProc(['tags' => ['pageId_12' => true, 'tt_content_5' => true, 'tt_content_6' => true]], $dataHandler);

        self::assertSame([['pageId_12', 'tt_content_5'], ['tt_content_6']], $this->purgedTags());
    }

    /**
     * A tag that would split in Souin's header (a comma or a space) is no
     * TYPO3 cache tag and is not sent.
     */
    #[Test]
    public function byTagOnlyValidCacheTagsAreSent(): void
    {
        $this->byTag = true;

        $this->postProc(['tags' => ['pageId_12' => true, 'a, b' => true, 'c d' => true]]);

        self::assertSame([['pageId_12']], $this->purgedTags());
    }

    /**
     * The tags travel in the message, so the koh_async consumer sends them
     * whatever its own environment says.
     */
    #[Test]
    public function byTagTheMessageCarriesTheTags(): void
    {
        $this->byTag = true;
        $this->handleInline = false;

        $this->postProc(['tags' => ['pageId_501' => true, 'tt_content_5' => true]]);

        self::assertCount(1, $this->dispatched);
        self::assertSame(['pageId_501', 'tt_content_5'], $this->dispatched[0]->tags);
        self::assertSame([], $this->dispatched[0]->queryVariantUrls);
        self::assertSame([], $this->purgedTags(), 'nothing sent in the request');
    }

    #[Test]
    public function withoutTheSwitchNoTagIsPurged(): void
    {
        $this->postProc(['cacheCmd' => 'cachetag:tx_news', 'tags' => ['tx_news']]);
        $this->postProc(['tags' => ['pageId_12' => true, 'tt_content_5' => true]]);

        self::assertSame([], $this->purgedTags());
        self::assertSame([], $this->dispatched[0]->tags);
    }
}
