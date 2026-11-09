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

    // --- on behalf of another request ---------------------------------------

    #[Test]
    public function theOriginContextIsNullWhileTheAuditIsOff(): void
    {
        self::assertNull(PurgeAudit::originContext());

        putenv(PurgeAudit::ENV . '=1');
        $origin = PurgeAudit::originContext();
        self::assertIsArray($origin);
        self::assertSame(PHP_SAPI, $origin['sapi']);
        self::assertSame(self::class . '::theOriginContextIsNullWhileTheAuditIsOff', $origin['caller'][0]);
    }

    #[Test]
    public function aLineOnBehalfOfAnotherProcessCarriesItsOriginAndWhoSentIt(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        $GLOBALS['BE_USER'] = new class {
            /** @var array<string, mixed> */
            public array $user = ['username' => 'consumer-side'];
            public int $workspace = 0;
        };

        PurgeAudit::onBehalfOf(['sapi' => 'frankenphp', 'be_user' => 'editor', 'caller' => ['X::y']], static function (): void {
            PurgeAudit::record('purge', ['key' => 'k']);
        });

        $line = json_decode($this->lines[0], true);
        self::assertSame(['koh_purge_audit', 'key', 'sapi', 'be_user', 'caller', 'handled_by'], array_keys($line));
        self::assertSame('editor', $line['be_user']);
        self::assertSame(['X::y'], $line['caller']);
        self::assertSame(PHP_SAPI, $line['handled_by']['sapi']);
        self::assertArrayHasKey('command', $line['handled_by'], 'under PHPUnit this process is a CLI command');
    }

    #[Test]
    public function aLineOnBehalfOfTheSameProcessIsTheOriginAlone(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        $origin = PurgeAudit::context();

        PurgeAudit::onBehalfOf($origin, static function (): void {
            PurgeAudit::record('purge', ['key' => 'k']);
        });

        self::assertSame(['koh_purge_audit' => 'purge', 'key' => 'k'] + $origin, json_decode($this->lines[0], true));
    }

    #[Test]
    public function aDifferentCommandIsAnotherProcess(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        $origin = ['sapi' => PHP_SAPI, 'command' => 'scheduler:run'];

        PurgeAudit::onBehalfOf($origin, static function (): void {
            PurgeAudit::record('purge');
        });

        self::assertArrayHasKey('handled_by', json_decode($this->lines[0], true));
    }

    #[Test]
    public function theOriginEndsWithTheCallEvenWhenItThrows(): void
    {
        putenv(PurgeAudit::ENV . '=1');
        try {
            PurgeAudit::onBehalfOf(['sapi' => 'frankenphp', 'be_user' => 'editor'], static function (): void {
                PurgeAudit::onBehalfOf(null, static function (): void {
                    PurgeAudit::record('inner');
                });
                PurgeAudit::record('outer');
                throw new \RuntimeException('boom');
            });
            self::fail('the exception must pass through');
        } catch (\RuntimeException) {
        }
        PurgeAudit::record('after');

        $lines = array_map(static fn (string $l): array => json_decode($l, true), $this->lines);
        self::assertArrayNotHasKey('be_user', $lines[0], 'a null origin means the context it runs in');
        self::assertSame('editor', $lines[1]['be_user'], 'the outer origin is back after the inner call');
        self::assertArrayNotHasKey('be_user', $lines[2]);
    }
}
