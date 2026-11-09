<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\RequestTiming;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One JSON line per frontend request: bootstrap and application time apart,
 * and whether TYPO3 rendered the page.
 */
final class RequestTimingTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        putenv(RequestTiming::ENV);
        RequestTiming::writeTo(function (string $line): void {
            $this->lines[] = $line;
        });
        RequestTiming::begin();
        $_SERVER['HTTP_HOST'] = 'www.example.org';
        $_SERVER['REQUEST_URI'] = '/blog/post?utm_source=mail&id=7';
    }

    protected function tearDown(): void
    {
        RequestTiming::writeTo(null);
        putenv(RequestTiming::ENV);
        $_SERVER = $this->server;
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function onlyLine(): array
    {
        self::assertCount(1, $this->lines);
        $line = json_decode($this->lines[0], true);
        self::assertIsArray($line);

        return $line;
    }

    #[Test]
    public function aRequestIsOneLineWithBootAndApplicationApart(): void
    {
        RequestTiming::record(1_000_000_000, 1_031_200_000, 1_246_400_000, 200, 17);

        $line = $this->onlyLine();
        self::assertSame('frontend', $line['koh_timing']);
        self::assertSame('www.example.org', $line['host']);
        self::assertSame(200, $line['status']);
        self::assertSame(31.2, $line['boot_ms']);
        self::assertSame(215.2, $line['app_ms']);
        self::assertSame(246.4, $line['total_ms']);
        self::assertSame(17, $line['worker_request']);
        self::assertIsFloat($line['mem_peak_mb']);
    }

    #[Test]
    public function onlyThePathIsLoggedNeverTheQuery(): void
    {
        RequestTiming::record(0, 1, 2, 200, 1);

        self::assertSame('/blog/post', $this->onlyLine()['path']);
        self::assertStringNotContainsString('utm_source', $this->lines[0]);
    }

    #[Test]
    public function aPageIsNotRenderedUntilTheRenderEventSaysSo(): void
    {
        RequestTiming::record(0, 1, 2, 200, 1);
        self::assertSame('not-rendered', $this->onlyLine()['page']);

        $this->lines = [];
        RequestTiming::markRendered();
        RequestTiming::record(0, 1, 2, 200, 2);
        self::assertSame('rendered', $this->onlyLine()['page']);
    }

    #[Test]
    public function theNextRequestStartsNotRendered(): void
    {
        RequestTiming::markRendered();
        RequestTiming::begin();

        self::assertFalse(RequestTiming::wasRendered());
    }

    #[Test]
    public function aBootstrapThatNeverFinishedHasNoBootOrApplicationTime(): void
    {
        RequestTiming::record(0, null, 5_000_000, 500, 1);

        $line = $this->onlyLine();
        self::assertNull($line['boot_ms']);
        self::assertNull($line['app_ms']);
        self::assertSame(5.0, $line['total_ms']);
        self::assertSame(500, $line['status']);
    }

    #[Test]
    public function itIsOnUnlessSwitchedOff(): void
    {
        self::assertTrue(RequestTiming::isEnabled());

        foreach (['0', 'false', 'off', 'no', ' OFF '] as $value) {
            putenv(RequestTiming::ENV . '=' . $value);
            self::assertFalse(RequestTiming::isEnabled(), $value);
        }
    }

    #[Test]
    public function switchedOffItWritesNothing(): void
    {
        putenv(RequestTiming::ENV . '=0');

        RequestTiming::record(0, 1, 2, 200, 1);

        self::assertSame([], $this->lines);
    }
}
