<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\MessageHandler;

use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\MessageHandler\PurgeEdgeCacheHandler;
use Koh\FrankenPHP\Service\CaddyCacheService;
use Koh\FrankenPHP\Service\PurgeAudit;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The handler sends what the listener used to send inline, in the same order.
 */
#[AllowMockObjectsWithoutExpectations]
final class PurgeEdgeCacheHandlerTest extends TestCase
{
    /** @var list<array{string, mixed, mixed}> */
    private array $calls = [];

    /** @var list<array<string, mixed>|null> the audit origin in force during each call */
    private array $originDuring = [];

    protected function tearDown(): void
    {
        PurgeAudit::writeTo(null);
        putenv(PurgeAudit::ENV);
        parent::tearDown();
    }

    private function handler(): PurgeEdgeCacheHandler
    {
        $service = $this->createStub(CaddyCacheService::class);
        $service->method('invalidateUrls')->willReturnCallback(function (array $urls, bool $withQueryVariants = true): void {
            $this->calls[] = ['invalidateUrls', $urls, $withQueryVariants];
            PurgeAudit::record('purge', ['key' => $urls[0]]);
        });
        $service->method('invalidateQueryVariants')->willReturnCallback(function (array $urls): void {
            $this->calls[] = ['invalidateQueryVariants', $urls, null];
        });

        return new PurgeEdgeCacheHandler($service);
    }

    #[Test]
    public function eachPagesUrlsArePurgedWithoutVariantsThenTheVariantsOncePerHost(): void
    {
        ($this->handler())(new PurgeEdgeCache(
            [['https://a.example/x', 'https://b.example/x'], ['https://a.example/y']],
            ['https://a.example/x', 'https://b.example/x'],
        ));

        self::assertSame([
            ['invalidateUrls', ['https://a.example/x', 'https://b.example/x'], false],
            ['invalidateUrls', ['https://a.example/y'], false],
            ['invalidateQueryVariants', ['https://a.example/x', 'https://b.example/x'], null],
        ], $this->calls);
    }

    #[Test]
    public function noQueryVariantsNoVariantPurge(): void
    {
        ($this->handler())(new PurgeEdgeCache([['https://a.example/y']]));

        self::assertSame([['invalidateUrls', ['https://a.example/y'], false]], $this->calls);
    }

    /**
     * In the consumer, the audit line names the editor's request, not the
     * consumer; and the consumer's own context comes back afterwards.
     */
    #[Test]
    public function auditLinesCarryTheOriginOfTheRequest(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        $lines = [];
        PurgeAudit::writeTo(static function (string $line) use (&$lines): void {
            $lines[] = json_decode($line, true);
        });

        ($this->handler())(new PurgeEdgeCache([['https://a.example/y']], [], [
            'sapi' => 'frankenphp',
            'request' => 'POST cms.example/typo3/record/edit',
            'be_user' => 'editor',
        ]));
        PurgeAudit::record('purge', ['key' => 'after']);

        self::assertCount(2, $lines);
        self::assertSame('editor', $lines[0]['be_user']);
        self::assertSame('POST cms.example/typo3/record/edit', $lines[0]['request']);
        self::assertSame('frankenphp', $lines[0]['sapi']);
        self::assertSame(PHP_SAPI, $lines[0]['handled_by']['sapi'], 'the process that sent it');
        self::assertArrayNotHasKey('be_user', $lines[1], 'the origin ends with the message');
        self::assertArrayNotHasKey('handled_by', $lines[1]);
    }

    #[Test]
    public function theHandlerIsRegisteredByAttribute(): void
    {
        $attributes = (new \ReflectionClass(PurgeEdgeCacheHandler::class))->getAttributes(AsMessageHandler::class);

        self::assertCount(1, $attributes);
        $method = (new \ReflectionMethod(PurgeEdgeCacheHandler::class, '__invoke'))->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $method);
        self::assertSame(PurgeEdgeCache::class, $method->getName());
    }
}
