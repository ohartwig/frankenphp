<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Cache;

use Koh\FrankenPHP\Cache\VerifiedPayload;
use Koh\FrankenPHP\Cache\VerifiedPayloadMemo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerifiedPayloadMemoTest extends TestCase
{
    private static function entry(string $raw, int $bytes): VerifiedPayload
    {
        return new VerifiedPayload($raw, \strlen($raw), false, null, $bytes);
    }

    #[Test]
    public function anEntryAnswersOnlyForIdenticalBytes(): void
    {
        $memo = new VerifiedPayloadMemo(1000);
        $memo->remember('k', self::entry('abc', 10));

        self::assertNull($memo->find('k', 'abd'));
        // The mismatch dropped it: the backend holds something else now.
        self::assertSame(0, $memo->count());
        self::assertSame(0, $memo->bytes());
        self::assertNull($memo->find('k', 'abc'));
    }

    #[Test]
    public function theLeastRecentlyUsedEntryGoesFirst(): void
    {
        $memo = new VerifiedPayloadMemo(100);
        $memo->remember('a', self::entry('a', 40));
        $memo->remember('b', self::entry('b', 40));
        self::assertNotNull($memo->find('a', 'a'));

        $memo->remember('c', self::entry('c', 40));

        self::assertNotNull($memo->find('a', 'a'));
        self::assertNull($memo->find('b', 'b'));
        self::assertNotNull($memo->find('c', 'c'));
        self::assertSame(80, $memo->bytes());
        self::assertSame(1, $memo->stats()['evictions']);
    }

    #[Test]
    public function anEntryLargerThanHalfTheBudgetIsNotKept(): void
    {
        $memo = new VerifiedPayloadMemo(100);
        $memo->remember('a', self::entry('a', 40));
        $memo->remember('big', self::entry('big', 51));

        self::assertSame(1, $memo->count());
        self::assertNull($memo->find('big', 'big'));
        self::assertNotNull($memo->find('a', 'a'));
    }

    #[Test]
    public function rememberingAgainReplacesTheEntryAndItsBytes(): void
    {
        $memo = new VerifiedPayloadMemo(100);
        $memo->remember('a', self::entry('one', 30));
        $memo->remember('a', self::entry('two', 20));

        self::assertSame(20, $memo->bytes());
        self::assertNotNull($memo->find('a', 'two'));
    }

    #[Test]
    public function forgetPrefixDropsOneCache(): void
    {
        $memo = new VerifiedPayloadMemo(1000);
        $memo->remember("hash\0x", self::entry('1', 1));
        $memo->remember("hash\0y", self::entry('2', 1));
        $memo->remember("hashes\0x", self::entry('3', 1));

        $memo->forgetPrefix("hash\0");

        self::assertSame(1, $memo->count());
        self::assertNotNull($memo->find("hashes\0x", '3'));
    }

    #[Test]
    public function aBudgetBelowOneByteIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VerifiedPayloadMemo(0);
    }
}
