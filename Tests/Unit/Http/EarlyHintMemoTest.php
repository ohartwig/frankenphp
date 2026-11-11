<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Http;

use Koh\FrankenPHP\Http\EarlyHintMemo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EarlyHintMemoTest extends TestCase
{
    protected function tearDown(): void
    {
        EarlyHintMemo::setInstance(null, false);
    }

    #[Test]
    public function aPageNeverSeenOnAnUnknownHostHasNoHints(): void
    {
        self::assertSame([], (new EarlyHintMemo())->lookup('example.com', '/'));
    }

    #[Test]
    public function aPageGetsTheHintsOfItsLastRender(): void
    {
        $memo = new EarlyHintMemo();
        $memo->record('example.com', '/a', ['<a.css>', '<old.css>']);
        $memo->record('example.com', '/a', ['<a.css>', '<new.css>']);

        self::assertSame(['<a.css>', '<new.css>'], $memo->lookup('EXAMPLE.com', '/a'));
    }

    /**
     * The base stylesheet and the font are on every page; what only one page
     * needs is not announced for a page that has not been rendered yet.
     */
    #[Test]
    public function anUnseenPageOfAKnownHostGetsWhatAllItsPagesShared(): void
    {
        $memo = new EarlyHintMemo();
        $memo->record('example.com', '/a', ['<base.css>', '<a.css>', '<font>']);
        $memo->record('example.com', '/b', ['<base.css>', '<b.css>', '<font>']);

        self::assertSame(['<base.css>', '<font>'], $memo->lookup('example.com', '/c'));
        self::assertSame([], $memo->lookup('other.example', '/c'));
    }

    #[Test]
    public function theOldestPageIsForgottenWhenTheMemoIsFull(): void
    {
        $memo = new EarlyHintMemo(2);
        $memo->record('example.com', '/a', ['<a.css>']);
        $memo->record('example.com', '/b', ['<b.css>']);
        $memo->record('example.com', '/c', ['<c.css>']);

        // /a falls back to the common hints of the host, which nothing shares.
        self::assertSame([], $memo->lookup('example.com', '/a'));
        self::assertSame(['<c.css>'], $memo->lookup('example.com', '/c'));
    }

    #[Test]
    public function outsideAFrankenPhpWorkerThereIsNoMemo(): void
    {
        EarlyHintMemo::setInstance(null, false);

        self::assertNull(EarlyHintMemo::active());
    }
}
