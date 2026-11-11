<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Service;

use Koh\FrankenPHP\Service\EdgeCacheTags;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a response carries in Surrogate-Key and how a purge splits its tags.
 * Souin splits the header on ", ", so a tag must hold neither a comma nor a
 * space; the header must stay below what the proxies in front accept.
 */
final class EdgeCacheTagsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(EdgeCacheTags::ENV);
        unset($GLOBALS['TCA']);
        parent::tearDown();
    }

    /** @return array<string, mixed> TCA in miniature: two content tables, two backend-only ones */
    private static function tca(): array
    {
        return [
            'tt_content' => ['ctrl' => ['title' => 'Content']],
            'tx_news_domain_model_news' => ['ctrl' => []],
            'tx_scheduler_task' => ['ctrl' => ['adminOnly' => true, 'rootLevel' => 1]],
            'tx_scheduler_task_group' => ['ctrl' => ['adminOnly' => true, 'rootLevel' => 1]],
        ];
    }

    #[Test]
    public function offUnlessSwitchedOn(): void
    {
        self::assertFalse(EdgeCacheTags::enabled());
        putenv(EdgeCacheTags::ENV . '=1');
        self::assertTrue(EdgeCacheTags::enabled());
        putenv(EdgeCacheTags::ENV . '=0');
        self::assertFalse(EdgeCacheTags::enabled());
    }

    #[Test]
    public function onlyTypo3CacheTagsAreUsableEachOnce(): void
    {
        self::assertSame(
            ['pageId_1', 'tx_news_domain_model_news_5', 'a-b%26c&d'],
            EdgeCacheTags::usable(['pageId_1', 'a, b', 'c d', '', 'pageId_1', 'tx_news_domain_model_news_5', 'a-b%26c&d', 12, null, str_repeat('x', 251)]),
        );
    }

    #[Test]
    public function thePageTagsComeFirst(): void
    {
        [$tags, $dropped] = EdgeCacheTags::forResponse(['menuId_2', 'pageId_2303', 'tt_content_5', 'pageId_2300'], 2300);

        self::assertSame(['pageId_2300', 'pageId_2303', 'menuId_2', 'tt_content_5'], $tags);
        self::assertSame(0, $dropped);
    }

    #[Test]
    public function whatDoesNotFitIsDroppedFromTheEndButNeverThePageItself(): void
    {
        $many = array_map(static fn (int $i): string => 'tx_example_domain_model_item_' . $i, range(1, 1000));

        [$tags, $dropped] = EdgeCacheTags::forResponse([...$many, 'pageId_7', 'pageId_8'], 7, 20);

        self::assertSame(['pageId_7', 'pageId_8'], array_slice($tags, 0, 2));
        self::assertGreaterThan(0, $dropped);
        self::assertSame(1002, count($tags) + $dropped);
        self::assertLessThanOrEqual(EdgeCacheTags::MAX_HEADER_LENGTH, 20 + strlen(', ' . implode(', ', $tags)));
    }

    #[Test]
    public function headerValuesSplitAtTheLimitAndKeepEveryTag(): void
    {
        $tags = array_map(static fn (int $i): string => 'pageId_' . $i, range(1, 2000));

        $values = EdgeCacheTags::headerValues($tags);

        self::assertGreaterThan(1, count($values));
        foreach ($values as $value) {
            self::assertLessThanOrEqual(EdgeCacheTags::MAX_HEADER_LENGTH, strlen($value));
        }
        self::assertSame($tags, explode(', ', implode(', ', $values)));
        self::assertSame([], EdgeCacheTags::headerValues([]));
    }

    #[Test]
    public function joinSkipsEmptyParts(): void
    {
        self::assertSame('a, b', EdgeCacheTags::join('', 'a', '', 'b'));
        self::assertSame('', EdgeCacheTags::join(''));
    }

    /**
     * A response carries the tags of what it rendered; a backend-only table
     * (TCA ctrl.adminOnly) renders on no page, so its tags are on none.
     */
    #[Test]
    public function theTagsOfABackendOnlyTableAreNoEdgeTags(): void
    {
        $tca = self::tca();

        foreach (['tx_scheduler_task', 'tx_scheduler_task_1', 'tx_scheduler_task_group_3', 'tx_scheduler_task_pid_0'] as $tag) {
            self::assertFalse(EdgeCacheTags::isEdgeTag($tag, $tca), $tag);
        }
        foreach (['pageId_1', 'menuId_7', 'main', 'tt_content', 'tt_content_5', 'tx_news_domain_model_news_pid_12', 'sys_category_3', 'navigation'] as $tag) {
            self::assertTrue(EdgeCacheTags::isEdgeTag($tag, $tca), $tag);
        }
    }

    #[Test]
    public function withoutTcaEveryTagIsKept(): void
    {
        self::assertTrue(EdgeCacheTags::isEdgeTag('tx_scheduler_task_1'));
        self::assertTrue(EdgeCacheTags::isEdgeTag('tx_scheduler_task_1', []));
    }

    /**
     * Header and purge share usable(), so they share the filter: what the
     * purge leaves out, no response was filed under.
     */
    #[Test]
    public function headerAndPurgeLeaveOutTheSameTags(): void
    {
        $GLOBALS['TCA'] = self::tca();
        $tags = ['pageId_7', 'tx_scheduler_task', 'tt_content_5', 'tx_scheduler_task_1'];

        self::assertSame(['pageId_7', 'tt_content_5'], EdgeCacheTags::usable($tags));
        self::assertSame([['pageId_7', 'tt_content_5'], 0], EdgeCacheTags::forResponse($tags, 7));
        self::assertSame(['pageId_7, tt_content_5'], EdgeCacheTags::headerValues($tags));
        self::assertSame([], EdgeCacheTags::headerValues(['tx_scheduler_task', 'tx_scheduler_task_1']));
    }
}
