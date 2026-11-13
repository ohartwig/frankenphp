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

    #[Test]
    public function aCandidateNeedsAValidatorAndAPayloadOfTheMinimumSize(): void
    {
        $memo = new VerifiedPayloadMemo(1000, 4);
        $memo->remember('large', new VerifiedPayload('abcd', 4, false, null, 10, '4:abcd'));
        $memo->remember('small', new VerifiedPayload('abc', 3, false, null, 10, '3:abc'));
        $memo->remember('plain', self::entry('abcdef', 10));

        self::assertSame('4:abcd', $memo->candidate('large')?->validator);
        self::assertNull($memo->candidate('small'));
        self::assertNull($memo->candidate('plain'));
        self::assertNull($memo->candidate('unknown'));
        self::assertSame(['hits' => 0, 'misses' => 0], array_intersect_key($memo->stats(), ['hits' => 0, 'misses' => 0]), 'asking for a candidate is not a read');
    }

    #[Test]
    public function aMinimumOfZeroMeansNoCandidates(): void
    {
        $memo = new VerifiedPayloadMemo(1000, 0);
        $memo->remember('k', new VerifiedPayload('abcd', 4, false, null, 10, '4:abcd'));

        self::assertNull($memo->candidate('k'));
    }

    #[Test]
    public function aConfirmedEntryCountsAsAHitAndBecomesTheMostRecentlyUsed(): void
    {
        $memo = new VerifiedPayloadMemo(100, 1);
        $memo->remember('a', new VerifiedPayload('a', 1, false, null, 40, '1:a'));
        $memo->remember('b', new VerifiedPayload('b', 1, false, null, 40, '1:b'));

        $memo->confirm('a');
        $memo->confirm('unknown');
        $memo->remember('c', self::entry('c', 40));

        self::assertNotNull($memo->candidate('a'));
        self::assertNull($memo->candidate('b'), 'b was the least recently used');
        self::assertSame(['hits' => 1, 'confirmed' => 1], array_intersect_key($memo->stats(), ['hits' => 0, 'confirmed' => 0]));
    }

    #[Test]
    public function aNewValidatorChangesWhatTheEntryCounts(): void
    {
        $entry = new VerifiedPayload('abcd', 4, false, null, 10, '4:abcd');

        self::assertSame(4, $entry->withValidator(null)->bytes);
        self::assertSame(12, $entry->withValidator('4:abcdxx')->bytes);
        self::assertSame('4:abcdxx', $entry->withValidator('4:abcdxx')->validator);
    }

    #[Test]
    public function aNegativeMinimumIsRejected(): void
    {
        $this->expectExceptionCode(1794320002);
        new VerifiedPayloadMemo(1000, -1);
    }
}
