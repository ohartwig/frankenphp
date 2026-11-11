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
        parent::tearDown();
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
}
