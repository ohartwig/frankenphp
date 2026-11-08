<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Service;

use Koh\FrankenPHP\Service\PurgeAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

final class PurgeAuditTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lines = [];
        PurgeAudit::writeTo(function (string $line): void {
            $this->lines[] = $line;
        });
    }

    protected function tearDown(): void
    {
        PurgeAudit::writeTo(null);
        putenv(PurgeAudit::ENV);
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string|false, bool}>
     */
    public static function switchValues(): array
    {
        return [
            'unset' => [false, false],
            'zero' => ['0', false],
            'off' => ['off', false],
            'one' => ['1', true],
            'TRUE' => [' TRUE ', true],
            'yes' => ['yes', true],
            'on' => ['on', true],
        ];
    }

    #[Test]
    #[DataProvider('switchValues')]
    public function itWritesOnlyWhenSwitchedOn(string|false $value, bool $expected): void
    {
        putenv(false === $value ? PurgeAudit::ENV : PurgeAudit::ENV . '=' . $value);

        PurgeAudit::record('purge', ['key' => '.+']);

        self::assertSame($expected, PurgeAudit::isEnabled());
        self::assertCount($expected ? 1 : 0, $this->lines);
    }

    #[Test]
    public function aLineSaysWhatWasPurgedAndWhoAskedForIt(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://cms.example.org/mcp?x=1', 'POST'))
            ->withHeader('User-Agent', 'agent/1.0');
        $GLOBALS['BE_USER'] = new class {
            /** @var array<string, mixed> */
            public array $user = ['username' => 'editor', 'uid' => 3];
            public int $workspace = 1;
        };

        PurgeAudit::record('full-flush-scheduled', ['reason' => 'clear_cacheCmd=all']);

        self::assertCount(1, $this->lines);
        $entry = json_decode($this->lines[0], true);
        self::assertIsArray($entry);
        self::assertSame('full-flush-scheduled', $entry['koh_purge_audit']);
        self::assertSame('clear_cacheCmd=all', $entry['reason']);
        self::assertSame('cli', $entry['sapi']);
        self::assertArrayHasKey('command', $entry);
        self::assertSame('POST cms.example.org/mcp', $entry['request']);
        self::assertSame('agent/1.0', $entry['user_agent']);
        self::assertSame('editor', $entry['be_user']);
        self::assertSame(1, $entry['workspace']);
        self::assertIsArray($entry['caller']);
        self::assertContains(self::class . '::aLineSaysWhatWasPurgedAndWhoAskedForIt', $entry['caller']);
    }

    #[Test]
    public function thePurgePlumbingIsLeftOutOfTheCallers(): void
    {
        putenv(PurgeAudit::ENV . '=1');

        $context = PurgeAudit::context();

        self::assertIsArray($context['caller']);
        foreach ($context['caller'] as $frame) {
            self::assertStringStartsNotWith('Koh\\FrankenPHP\\Service\\', $frame);
        }
        self::assertLessThanOrEqual(12, count($context['caller']));
        self::assertArrayNotHasKey('request', $context);
        self::assertArrayNotHasKey('be_user', $context);
    }

    #[Test]
    public function slashesStayReadableAndBrokenUtf8DoesNotLoseTheLine(): void
    {
        putenv(PurgeAudit::ENV . '=1');

        PurgeAudit::record('purge', ['key' => 'GET-example.org-/a/b', 'ref' => "bad\xff"]);

        self::assertCount(1, $this->lines);
        self::assertStringContainsString('GET-example.org-/a/b', $this->lines[0]);
        $entry = json_decode($this->lines[0], true);
        self::assertIsArray($entry);
        self::assertSame("bad\u{FFFD}", $entry['ref']);
    }

    #[Test]
    public function theCommandIsTheFirstThreeArguments(): void
    {
        $argv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['vendor/bin/typo3', 'cache:flush', '--group', 'pages', '--verbose'];
        try {
            $context = PurgeAudit::context();
        } finally {
            $_SERVER['argv'] = $argv;
        }

        self::assertSame('cache:flush --group pages', $context['command']);
    }

    #[Test]
    public function aLongUserAgentIsCutAtEightyCharacters(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = (new ServerRequest('https://example.org/', 'GET'))
            ->withHeader('User-Agent', str_repeat('a', 79) . 'bcdef');

        self::assertSame(str_repeat('a', 79) . 'b', PurgeAudit::context()['user_agent']);
    }

    #[Test]
    public function theBackendUserIsReadDefensively(): void
    {
        $GLOBALS['BE_USER'] = new class {
            /** @var array<string, mixed> */
            public array $user = ['username' => 7];
            public string $workspace = '2';
        };
        $context = PurgeAudit::context();
        self::assertSame('7', $context['be_user']);
        self::assertSame(2, $context['workspace']);

        $GLOBALS['BE_USER'] = new class {
            /** @var array<string, mixed> */
            public array $user = [];
        };
        $context = PurgeAudit::context();
        self::assertSame('', $context['be_user']);
        self::assertSame(0, $context['workspace']);

        $GLOBALS['BE_USER'] = new class {};
        self::assertArrayNotHasKey('be_user', PurgeAudit::context(), 'an object without a user record is no user');
    }

    #[Test]
    public function atMostTwelveCallersAreKept(): void
    {
        $context = $this->deep(20);

        self::assertCount(12, $context['caller']);
        self::assertSame(self::class . '::deep', $context['caller'][0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deep(int $depth): array
    {
        return $depth > 0 ? $this->deep($depth - 1) : PurgeAudit::context();
    }
}
