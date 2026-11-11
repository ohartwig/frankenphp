<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Message;

use Koh\FrankenPHP\Message\PurgeEdgeCache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A queued message is serialized into the database and decoded by the
 * consumer, which refuses any class not on koh_async's allowlist. So the
 * message must survive the trip and contain no object but itself.
 */
final class PurgeEdgeCacheTest extends TestCase
{
    #[Test]
    public function itSurvivesSerializationWithNoOtherClassInside(): void
    {
        $message = new PurgeEdgeCache(
            [['https://a.example/x', 'https://b.example/x'], ['https://a.example/y']],
            ['https://a.example/x'],
            ['sapi' => 'frankenphp', 'be_user' => 'editor', 'workspace' => 0, 'caller' => ['A::b']],
            ['pageId_12', 'tt_content_5'],
        );
        $serialized = serialize($message);

        self::assertSame(1, preg_match_all('/O:\d+:"([^"]+)"/', $serialized, $classes));
        self::assertSame([PurgeEdgeCache::class], $classes[1]);
        self::assertEquals($message, unserialize($serialized, ['allowed_classes' => [PurgeEdgeCache::class]]));
    }

    #[Test]
    public function variantsOriginAndTagsAreOptional(): void
    {
        $message = new PurgeEdgeCache([['https://a.example/x']]);

        self::assertSame([], $message->queryVariantUrls);
        self::assertNull($message->origin);
        self::assertSame([], $message->tags);
    }
}
