<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Functional;

use Koh\FrankenPHP\Page\LazyPageLayoutResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\ContentAreaCollection;
use TYPO3\CMS\Core\Page\PageLayout;
use TYPO3\CMS\Core\Page\PageLayoutResolver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * KOH_LAZY_PAGE_LAYOUT: the container's PageLayoutResolver hands out a lazy
 * proxy for a page TSconfig layout, which resolves to exactly what TYPO3's
 * resolver returns, and nothing changes for any other layout or with the
 * switch off.
 */
final class LazyPageLayoutResolverTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/frankenphp'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/pages_with_layouts.csv');
    }

    protected function tearDown(): void
    {
        putenv(LazyPageLayoutResolver::ENV);
        parent::tearDown();
    }

    #[Test]
    public function theContainerHandsOutTheLazyResolver(): void
    {
        self::assertInstanceOf(LazyPageLayoutResolver::class, $this->get(PageLayoutResolver::class));
    }

    #[Test]
    public function withTheSwitchOffTheLayoutIsResolvedAtOnce(): void
    {
        $layout = $this->layoutOf(1);

        self::assertInstanceOf(PageLayout::class, $layout);
        self::assertFalse(self::isLazy($layout));
        self::assertSame('WA layout', $layout->getTitle());
    }

    #[Test]
    public function aPageTsConfigLayoutResolvesOnFirstUseToWhatTypo3Resolves(): void
    {
        $eager = $this->layoutOf(1);
        putenv(LazyPageLayoutResolver::ENV . '=1');

        $lazy = $this->layoutOf(1);

        self::assertInstanceOf(PageLayout::class, $lazy);
        self::assertTrue(self::isLazy($lazy), 'nothing resolved before something reads the layout');
        self::assertInstanceOf(PageLayout::class, $eager);
        self::assertSame(self::describe($eager), self::describe($lazy));
        self::assertFalse(self::isLazy($lazy));
    }

    #[Test]
    public function anyOtherLayoutIsResolvedAtOnceAsBefore(): void
    {
        $eager = $this->layoutOf(2);
        putenv(LazyPageLayoutResolver::ENV . '=1');

        $layout = $this->layoutOf(2);

        self::assertInstanceOf(PageLayout::class, $layout);
        self::assertFalse(self::isLazy($layout));
        self::assertInstanceOf(PageLayout::class, $eager);
        self::assertSame(self::describe($eager), self::describe($layout));
    }

    /**
     * TYPO3 returns no layout for a page TSconfig identifier page TSconfig
     * does not define; the proxy, already handed out, resolves to an empty
     * layout of that identifier (see LazyPageLayoutResolver).
     */
    #[Test]
    public function anUndefinedPageTsConfigLayoutResolvesToAnEmptyLayout(): void
    {
        $eager = $this->layoutOf(3);
        self::assertNull($eager);
        putenv(LazyPageLayoutResolver::ENV . '=1');

        $layout = $this->layoutOf(3);

        self::assertInstanceOf(PageLayout::class, $layout);
        self::assertSame(['identifier' => 'pagets__undefined', 'title' => '', 'areas' => []], self::describe($layout));
    }

    private function layoutOf(int $pageId): ?PageLayout
    {
        $page = BackendUtility::getRecord('pages', $pageId);
        self::assertIsArray($page);
        $rootLine = GeneralUtility::makeInstance(RootlineUtility::class, $pageId)->get();

        return $this->get(PageLayoutResolver::class)->getLayoutForPage($page, $rootLine);
    }

    private static function isLazy(PageLayout $layout): bool
    {
        return (new \ReflectionClass(PageLayout::class))->isUninitializedLazyObject($layout);
    }

    /**
     * The layout's identifier, title and content area identifiers. The areas
     * themselves are closures that fetch their records for a frontend
     * request; their keys say which areas the layout has.
     *
     * @return array{identifier: string, title: string, areas: list<array-key>}
     */
    private static function describe(PageLayout $layout): array
    {
        $areas = (new \ReflectionProperty(ContentAreaCollection::class, 'contentAreas'))->getValue($layout->getContentAreas());
        self::assertIsArray($areas);

        return ['identifier' => $layout->getIdentifier(), 'title' => $layout->getTitle(), 'areas' => array_keys($areas)];
    }
}
