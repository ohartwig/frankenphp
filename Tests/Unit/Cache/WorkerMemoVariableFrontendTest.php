<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Cache;

use Koh\FrankenPHP\Cache\VerifiedPayloadMemo;
use Koh\FrankenPHP\Cache\WorkerMemoVariableFrontend;
use Koh\FrankenPHP\Tests\Fixtures\ArrayCacheBackend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * The memo changes the cost of a read, never its result: every get() answers
 * what the core VariableFrontend answers for the bytes the backend holds at
 * that moment.
 */
final class WorkerMemoVariableFrontendTest extends TestCase
{
    private ArrayCacheBackend $backend;

    private VerifiedPayloadMemo $memo;

    /** @var array<string, mixed>|null */
    private ?array $confVarsBackup = null;

    protected function setUp(): void
    {
        $this->confVarsBackup = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
        $this->backend = new ArrayCacheBackend();
        $this->memo = new VerifiedPayloadMemo();
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

    private function memoFrontend(string $identifier = 'hash'): WorkerMemoVariableFrontend
    {
        return new WorkerMemoVariableFrontend($identifier, $this->backend);
    }

    /** Another pod: the core frontend on the same shared backend. */
    private function coreFrontend(string $identifier = 'hash'): VariableFrontend
    {
        return new VariableFrontend($identifier, $this->backend);
    }

    #[Test]
    public function anObjectFreePayloadIsDecodedOnceAndReadFromTheBackendEveryTime(): void
    {
        $value = ['tree' => [['uid' => 1, 'title' => 'Start'], ['uid' => 2, 'title' => 'Blog']], 'n' => null];
        $this->coreFrontend()->set('menu', $value);
        $frontend = $this->memoFrontend();

        self::assertSame($value, $frontend->get('menu'));
        self::assertSame($value, $frontend->get('menu'));
        self::assertSame($value, $frontend->get('menu'));

        self::assertSame(3, $this->backend->reads, 'every get() still reads the backend');
        self::assertSame(['hits' => 2, 'misses' => 1], array_intersect_key($this->memo->stats(), ['hits' => 0, 'misses' => 0]));
    }

    #[Test]
    public function aCallerChangingItsCopyDoesNotChangeWhatTheNextRequestGets(): void
    {
        $this->coreFrontend()->set('menu', ['a' => ['b' => 1]]);
        $frontend = $this->memoFrontend();

        $first = $frontend->get('menu');
        self::assertIsArray($first);
        $first['a']['b'] = 2;
        $first['injected'] = true;

        self::assertSame(['a' => ['b' => 1]], $frontend->get('menu'));
    }

    #[Test]
    public function objectsAreNeverSharedBetweenReads(): void
    {
        $object = new \ArrayObject(['title' => 'Start']);
        $this->coreFrontend()->set('rootline', [$object]);
        $frontend = $this->memoFrontend('hash');

        $first = $frontend->get('rootline');
        $second = $frontend->get('rootline');

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertEquals($first, $second);
        self::assertNotSame($first[0], $second[0]);
        $first[0]['title'] = 'changed by request one';
        self::assertSame('Start', $second[0]['title']);
        self::assertSame('Start', $frontend->get('rootline')[0]['title']);
        self::assertSame(1, $this->memo->stats()['misses'], 'the HMAC is still checked only once');
    }

    #[Test]
    public function phpReferencesInsideAPayloadAreNotShared(): void
    {
        $inner = ['x' => 1];
        $value = ['a' => &$inner, 'b' => &$inner];
        $this->coreFrontend()->set('refs', $value);
        $frontend = $this->memoFrontend();

        $first = $frontend->get('refs');
        self::assertIsArray($first);
        $first['a']['x'] = 99;

        $second = $frontend->get('refs');
        self::assertIsArray($second);
        self::assertSame(1, $second['a']['x']);
        self::assertSame(1, $second['b']['x']);
    }

    #[Test]
    public function aChangeOnAnotherPodIsSeenOnTheNextRead(): void
    {
        $frontend = $this->memoFrontend();
        $this->coreFrontend()->set('menu', ['v' => 1]);
        self::assertSame(['v' => 1], $frontend->get('menu'));
        self::assertSame(['v' => 1], $frontend->get('menu'));

        $this->coreFrontend()->set('menu', ['v' => 2]);

        self::assertSame(['v' => 2], $frontend->get('menu'));
    }

    #[Test]
    public function aFlushOrRemovalOnAnotherPodIsSeenOnTheNextRead(): void
    {
        $frontend = $this->memoFrontend();
        $this->coreFrontend()->set('menu', ['v' => 1]);
        $this->coreFrontend()->set('other', ['v' => 2]);
        self::assertSame(['v' => 1], $frontend->get('menu'));
        self::assertSame(['v' => 2], $frontend->get('other'));

        $this->coreFrontend()->remove('menu');
        self::assertFalse($frontend->get('menu'));

        $this->coreFrontend()->flush();
        self::assertFalse($frontend->get('other'));
        self::assertSame(0, $this->memo->count(), 'entries the backend no longer holds are dropped');
    }

    #[Test]
    public function aTamperedPayloadIsRejectedAsByTheCoreAndNotRemembered(): void
    {
        $this->coreFrontend()->set('menu', [new \ArrayObject(['v' => 1])]);
        $raw = $this->backend->data['menu'];
        // Same length, different content, HMAC unchanged.
        $this->backend->data['menu'] = str_replace('"v";i:1', '"v";i:7', $raw);

        self::assertFalse($this->coreFrontend()->get('menu'));
        self::assertFalse($this->memoFrontend()->get('menu'));
        self::assertSame(0, $this->memo->count());
    }

    #[Test]
    public function anUnauthenticatedClassFreePayloadGetsTheCoreFallback(): void
    {
        // Entries written before payloads were authenticated: the core
        // unserialises them without classes, so must this frontend.
        $this->backend->data['legacy'] = serialize(['v' => 1]);

        self::assertSame($this->coreFrontend()->get('legacy'), $this->memoFrontend()->get('legacy'));
        self::assertSame(0, $this->memo->count());
    }

    #[Test]
    public function aPayloadVerifiedForOneEntryIsNotUsedForAnother(): void
    {
        $this->coreFrontend()->set('one', ['v' => 1]);
        $this->coreFrontend()->set('two', ['v' => 1]);
        $frontend = $this->memoFrontend();

        self::assertSame(['v' => 1], $frontend->get('one'));
        self::assertSame(['v' => 1], $frontend->get('two'));
        self::assertSame(2, $this->memo->stats()['misses']);
    }

    #[Test]
    public function cachesWithTheSameEntryIdentifierAreKeptApart(): void
    {
        $other = new ArrayCacheBackend();
        (new VariableFrontend('rootline', $other))->set('x', ['cache' => 'rootline']);
        $this->coreFrontend('hash')->set('x', ['cache' => 'hash']);

        self::assertSame(['cache' => 'hash'], $this->memoFrontend('hash')->get('x'));
        self::assertSame(['cache' => 'rootline'], (new WorkerMemoVariableFrontend('rootline', $other))->get('x'));
        self::assertSame(['cache' => 'hash'], $this->memoFrontend('hash')->get('x'));
    }

    #[Test]
    public function flushDropsTheEntriesOfThisCacheOnly(): void
    {
        $other = new ArrayCacheBackend();
        (new VariableFrontend('rootline', $other))->set('x', ['v' => 1]);
        $this->coreFrontend('hash')->set('x', ['v' => 1]);
        $this->memoFrontend('hash')->get('x');
        (new WorkerMemoVariableFrontend('rootline', $other))->get('x');
        self::assertSame(2, $this->memo->count());

        $this->memoFrontend('hash')->flush();

        self::assertSame(1, $this->memo->count());
        self::assertFalse($this->memoFrontend('hash')->get('x'));
    }

    #[Test]
    public function aSharedValueCountsWithItsSizeAnObjectPayloadOnlyWithItsBytes(): void
    {
        $this->coreFrontend()->set('arrays', array_fill(0, 200, str_repeat('x', 50)));
        $this->coreFrontend()->set('objects', [new \ArrayObject(['x' => 1])]);
        $frontend = $this->memoFrontend();

        $frontend->get('objects');
        self::assertSame(\strlen($this->backend->data['objects']), $this->memo->bytes());

        $frontend->get('arrays');
        self::assertGreaterThan(
            \strlen($this->backend->data['objects']) + \strlen($this->backend->data['arrays']),
            $this->memo->bytes(),
            'the decoded array is counted too',
        );
    }

    #[Test]
    public function removeForgetsTheEntry(): void
    {
        $this->coreFrontend()->set('x', ['v' => 1]);
        $frontend = $this->memoFrontend();
        $frontend->get('x');
        self::assertSame(1, $this->memo->count());

        self::assertTrue($frontend->remove('x'));

        self::assertSame(0, $this->memo->count());
        self::assertFalse($frontend->get('x'));
    }

    #[Test]
    public function flushDoesNotTouchACacheWhoseNameStartsTheSame(): void
    {
        $other = new ArrayCacheBackend();
        (new VariableFrontend('hashes', $other))->set('x', ['v' => 2]);
        $this->coreFrontend('hash')->set('x', ['v' => 1]);
        $this->memoFrontend('hash')->get('x');
        (new WorkerMemoVariableFrontend('hashes', $other))->get('x');

        $this->memoFrontend('hash')->flush();

        self::assertSame(1, $this->memo->count());
    }

    #[Test]
    public function aMissingEntryIsFalse(): void
    {
        self::assertFalse($this->memoFrontend()->get('nothing'));
    }

    #[Test]
    public function anInvalidIdentifierIsRejectedAsByTheCore(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1233058294);
        $this->memoFrontend()->get('not valid!');
    }

    #[Test]
    public function withoutAnActiveMemoItIsTheCoreFrontend(): void
    {
        VerifiedPayloadMemo::setInstance(null);
        $this->coreFrontend()->set('menu', ['v' => 1]);

        // Not a FrankenPHP worker here (and the switch is off): no memo.
        self::assertNull(VerifiedPayloadMemo::active());
        self::assertSame(['v' => 1], $this->memoFrontend()->get('menu'));
    }

    #[Test]
    public function aTransientBackendIsPassedThrough(): void
    {
        $frontend = new WorkerMemoVariableFrontend('runtime', new TransientMemoryBackend());
        $object = new \stdClass();
        $frontend->set('o', $object);

        self::assertSame($object, $frontend->get('o'));
        self::assertSame(0, $this->memo->count());
    }
}
