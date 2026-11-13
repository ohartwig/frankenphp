<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Cache;

use Koh\FrankenPHP\Cache\VerifiedPayloadMemo;
use Koh\FrankenPHP\Cache\WorkerMemoVariableFrontend;
use Koh\FrankenPHP\Tests\Fixtures\ArrayCacheBackend;
use Koh\FrankenPHP\Tests\Fixtures\ConditionalArrayCacheBackend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * On a backend with conditional reads, a large remembered payload is
 * validated without being fetched; the backend is still asked on every
 * get(), and every get() answers what the backend holds at that moment.
 */
final class WorkerMemoConditionalReadTest extends TestCase
{
    private const MIN_BYTES = 1000;

    private ConditionalArrayCacheBackend $backend;

    private VerifiedPayloadMemo $memo;

    /** @var array<string, mixed>|null */
    private ?array $confVarsBackup = null;

    protected function setUp(): void
    {
        $this->confVarsBackup = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        $this->backend = new ConditionalArrayCacheBackend();
        $this->memo = new VerifiedPayloadMemo(conditionalReadMinBytes: self::MIN_BYTES);
        VerifiedPayloadMemo::setInstance($this->memo);
    }

    protected function tearDown(): void
    {
        VerifiedPayloadMemo::setInstance(null);
        if ($this->confVarsBackup === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->confVarsBackup;
        }
    }

    private function memoFrontend(): WorkerMemoVariableFrontend
    {
        return new WorkerMemoVariableFrontend('hash', $this->backend);
    }

    /** Another pod: the core frontend on the same shared backend. */
    private function otherPod(): VariableFrontend
    {
        return new VariableFrontend('hash', $this->backend);
    }

    /**
     * @return array<string, mixed> a value whose payload is well above MIN_BYTES
     */
    private static function large(string $marker): array
    {
        return ['marker' => $marker, 'tree' => array_fill(0, 100, ['uid' => 1, 'title' => 'A page title'])];
    }

    #[Test]
    public function anUnchangedLargeEntryIsFetchedOnceAndConfirmedAfterwards(): void
    {
        $value = self::large('menu');
        $this->otherPod()->set('menu', $value);
        $frontend = $this->memoFrontend();

        self::assertSame($value, $frontend->get('menu'));
        self::assertSame($value, $frontend->get('menu'));
        self::assertSame($value, $frontend->get('menu'));

        self::assertSame(1, $this->backend->payloadsSent, 'the payload crossed the wire once');
        self::assertSame(2, $this->backend->confirmations, 'the backend was still asked on every get()');
        self::assertSame(
            ['hits' => 2, 'misses' => 1, 'confirmed' => 2],
            array_intersect_key($this->memo->stats(), ['hits' => 0, 'misses' => 0, 'confirmed' => 0]),
        );
    }

    #[Test]
    public function aChangeOnAnotherPodIsSeenOnTheNextReadAndFetchedOnce(): void
    {
        $frontend = $this->memoFrontend();
        $this->otherPod()->set('menu', self::large('one'));
        $frontend->get('menu');
        $frontend->get('menu');

        $this->otherPod()->set('menu', self::large('two'));

        self::assertSame(self::large('two'), $frontend->get('menu'));
        self::assertSame(2, $this->backend->payloadsSent);
        self::assertSame(self::large('two'), $frontend->get('menu'));
        self::assertSame(2, $this->backend->payloadsSent, 'the new payload is confirmed from then on');
        self::assertSame(2, $this->backend->confirmations);
    }

    #[Test]
    public function aChangeOfTheSameLengthIsSeen(): void
    {
        $frontend = $this->memoFrontend();
        $this->otherPod()->set('menu', self::large('aaa'));
        $frontend->get('menu');
        $lengthBefore = \strlen($this->backend->stored['menu']);

        $this->otherPod()->set('menu', self::large('bbb'));

        self::assertSame($lengthBefore, \strlen($this->backend->stored['menu']));
        self::assertSame(self::large('bbb'), $frontend->get('menu'));
    }

    #[Test]
    public function aRemovalOrFlushOnAnotherPodIsSeenOnTheNextRead(): void
    {
        $frontend = $this->memoFrontend();
        $this->otherPod()->set('menu', self::large('menu'));
        $this->otherPod()->set('other', self::large('other'));
        $frontend->get('menu');
        $frontend->get('other');

        $this->otherPod()->remove('menu');
        self::assertFalse($frontend->get('menu'));

        $this->otherPod()->flush();
        self::assertFalse($frontend->get('other'));
        self::assertSame(0, $this->memo->count(), 'entries the backend no longer holds are dropped');
    }

    #[Test]
    public function anEntryBelowTheThresholdIsFetchedOnEveryRead(): void
    {
        $this->otherPod()->set('small', ['v' => 1]);
        $frontend = $this->memoFrontend();

        self::assertSame(['v' => 1], $frontend->get('small'));
        self::assertSame(['v' => 1], $frontend->get('small'));
        self::assertSame(['v' => 1], $frontend->get('small'));

        self::assertSame(3, $this->backend->payloadsSent);
        self::assertSame(0, $this->backend->confirmations);
        self::assertSame(2, $this->memo->stats()['hits'], 'the memo still spares the HMAC check');
        self::assertSame(0, $this->memo->stats()['confirmed']);
    }

    #[Test]
    public function aThresholdOfZeroSwitchesConditionalReadsOff(): void
    {
        VerifiedPayloadMemo::setInstance(new VerifiedPayloadMemo(conditionalReadMinBytes: 0));
        $this->otherPod()->set('menu', self::large('menu'));
        $frontend = $this->memoFrontend();

        self::assertSame(self::large('menu'), $frontend->get('menu'));
        self::assertSame(self::large('menu'), $frontend->get('menu'));

        self::assertSame(2, $this->backend->payloadsSent);
        self::assertSame(0, $this->backend->confirmations);
    }

    #[Test]
    public function aCompressedCacheIsValidatedByItsStoredBytes(): void
    {
        $this->backend = new ConditionalArrayCacheBackend(compression: true);
        $value = self::large('page');
        $this->otherPod()->set('page', $value);
        $frontend = $this->memoFrontend();

        self::assertSame($value, $frontend->get('page'));
        self::assertSame($value, $frontend->get('page'));
        self::assertSame(1, $this->backend->payloadsSent);
        self::assertSame(1, $this->backend->confirmations);
        self::assertStringStartsNotWith('a:', $this->backend->stored['page'], 'the backend holds a zlib stream');

        $this->otherPod()->set('page', self::large('next'));
        self::assertSame(self::large('next'), $frontend->get('page'));
        self::assertSame(self::large('next'), $frontend->get('page'));
        self::assertSame(2, $this->backend->payloadsSent);
        self::assertSame(2, $this->backend->confirmations);
    }

    #[Test]
    public function theSamePayloadStoredAsOtherBytesKeepsTheEntryAndTakesTheNewValidator(): void
    {
        $this->backend = new ConditionalArrayCacheBackend(compression: true);
        $value = self::large('page');
        $this->otherPod()->set('page', $value);
        $frontend = $this->memoFrontend();
        $frontend->get('page');

        // Another pod wrote the same payload with another compression level.
        $payload = (string) gzuncompress($this->backend->stored['page']);
        $this->backend->stored['page'] = (string) gzcompress($payload, 1);

        self::assertSame($value, $frontend->get('page'));
        self::assertSame(2, $this->backend->payloadsSent, 'other stored bytes: fetched');
        self::assertSame(1, $this->memo->stats()['misses'], 'but the same payload: no second HMAC check');
        self::assertSame($value, $frontend->get('page'));
        self::assertSame(2, $this->backend->payloadsSent);
        self::assertSame(1, $this->backend->confirmations, 'confirmed against the new stored bytes');
    }

    #[Test]
    public function objectsAreStillNeverSharedBetweenConfirmedReads(): void
    {
        $this->otherPod()->set('rootline', [new \ArrayObject(['title' => 'Start']), str_repeat('x', self::MIN_BYTES)]);
        $frontend = $this->memoFrontend();

        $first = $frontend->get('rootline');
        $second = $frontend->get('rootline');

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame(1, $this->backend->confirmations);
        self::assertEquals($first, $second);
        self::assertNotSame($first[0], $second[0]);
    }

    #[Test]
    public function aCallerChangingItsCopyDoesNotChangeWhatAConfirmedReadReturns(): void
    {
        $this->otherPod()->set('menu', self::large('menu'));
        $frontend = $this->memoFrontend();

        $first = $frontend->get('menu');
        self::assertIsArray($first);
        $first['marker'] = 'changed by request one';

        self::assertSame(self::large('menu'), $frontend->get('menu'));
        self::assertSame(1, $this->backend->confirmations);
    }

    #[Test]
    public function storedBytesForgedToLengthAndClosingBytesOnlyKeepTheVerifiedValueAlive(): void
    {
        $value = [new \ArrayObject(['who' => 'verified']), str_repeat('x', self::MIN_BYTES)];
        $this->otherPod()->set('menu', $value);
        $frontend = $this->memoFrontend();
        self::assertEquals($value, $frontend->get('menu'));

        // Write access to the cache server: same length, same HMAC suffix, other content.
        $this->backend->stored['menu'] = str_replace('verified', 'injected', $this->backend->stored['menu']);

        // Never the unverified content: the value this worker verified.
        self::assertEquals($value, $frontend->get('menu'));
        self::assertSame(1, $this->backend->confirmations);
        // A reader without that memory hands the forgery to the core deserializer, which rejects it.
        self::assertFalse($this->otherPod()->get('menu'));
        VerifiedPayloadMemo::setInstance(new VerifiedPayloadMemo(conditionalReadMinBytes: self::MIN_BYTES));
        self::assertFalse($this->memoFrontend()->get('menu'));
    }

    #[Test]
    public function aPayloadThatFailsTheHmacCheckIsNeverACandidate(): void
    {
        $this->backend->stored['legacy'] = serialize(self::large('legacy'));
        $frontend = $this->memoFrontend();

        self::assertSame(self::large('legacy'), $frontend->get('legacy'));
        self::assertSame(self::large('legacy'), $frontend->get('legacy'));

        self::assertSame(2, $this->backend->payloadsSent);
        self::assertSame(0, $this->backend->confirmations);
        self::assertSame(0, $this->memo->count());
    }

    #[Test]
    public function removeAndFlushThroughThisFrontendForgetTheEntry(): void
    {
        $this->otherPod()->set('a', self::large('a'));
        $this->otherPod()->set('b', self::large('b'));
        $frontend = $this->memoFrontend();
        $frontend->get('a');
        $frontend->get('b');

        self::assertTrue($frontend->remove('a'));
        self::assertFalse($frontend->get('a'));
        $frontend->flush();
        self::assertFalse($frontend->get('b'));
        self::assertSame(0, $this->memo->count());
    }

    #[Test]
    public function aBackendWithoutConditionalReadsIsReadInFullEveryTime(): void
    {
        $backend = new ArrayCacheBackend();
        (new VariableFrontend('hash', $backend))->set('menu', self::large('menu'));
        $frontend = new WorkerMemoVariableFrontend('hash', $backend);

        self::assertSame(self::large('menu'), $frontend->get('menu'));
        self::assertSame(self::large('menu'), $frontend->get('menu'));
        self::assertSame(self::large('menu'), $frontend->get('menu'));

        self::assertSame(3, $backend->reads);
        self::assertSame(['hits' => 2, 'confirmed' => 0], array_intersect_key($this->memo->stats(), ['hits' => 0, 'confirmed' => 0]));
    }

    #[Test]
    public function withoutAnActiveMemoTheBackendIsReadThePlainWay(): void
    {
        VerifiedPayloadMemo::setInstance(null);
        $this->otherPod()->set('menu', self::large('menu'));

        self::assertNull(VerifiedPayloadMemo::active());
        self::assertSame(self::large('menu'), $this->memoFrontend()->get('menu'));
        self::assertSame(self::large('menu'), $this->memoFrontend()->get('menu'));
        self::assertSame(0, $this->backend->confirmations);
    }
}
