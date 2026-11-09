<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\LeakGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * What the leak guard treats as state from another request, and what it must
 * leave alone: a finding costs the request a 503 and the worker its life.
 */
final class LeakGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $server = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->server = $_SERVER;
        GeneralUtility::flushInternalRuntimeCaches();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        GeneralUtility::flushInternalRuntimeCaches();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private static function server(string $host, string $uri): array
    {
        return ['HTTP_HOST' => $host, 'REQUEST_URI' => $uri];
    }

    private static function request(string $url, ?string $languageBase = null): ServerRequest
    {
        $request = new ServerRequest($url, 'GET');
        if ($languageBase !== null) {
            $request = $request->withAttribute('language', new SiteLanguage(1, 'en_GB.UTF-8', new Uri($languageBase), []));
        }

        return $request;
    }

    #[Test]
    public function aConsistentRequestIsNoFinding(): void
    {
        self::assertNull(LeakGuard::check(
            self::server('Site-B.test:8080', '/en/second-page?x=1'),
            'site-b.test:8080',
            self::request('http://site-b.test:8080/en/second-page', 'http://site-b.test:8080/en/'),
        ));
    }

    #[Test]
    public function aLanguageBaseWithoutHostOrTrailingSlashMatches(): void
    {
        self::assertNull(LeakGuard::check(self::server('example.org', '/en'), 'example.org', self::request('https://example.org/en', '/en/')));
        self::assertNull(LeakGuard::check(self::server('example.org', '/EN/page'), 'example.org', self::request('https://example.org/EN/page', '/en/')));
    }

    #[Test]
    public function noGlobalRequestIsNoFinding(): void
    {
        self::assertNull(LeakGuard::check(self::server('example.org', '/'), 'example.org', null));
    }

    #[Test]
    public function anotherHostInGetIndpEnvIsAFinding(): void
    {
        $finding = LeakGuard::check(self::server('site-b.test:8080', '/'), 'site-a.test:8080', null);

        self::assertSame(['check' => 'indpenv_host', 'expected' => 'site-b.test:8080', 'actual' => 'site-a.test:8080'], $finding);
    }

    #[Test]
    public function anotherHostInTheGlobalRequestIsAFinding(): void
    {
        $finding = LeakGuard::check(self::server('site-b.test', '/'), 'site-b.test', self::request('http://site-a.test/'));

        self::assertSame('global_request_host', $finding['check'] ?? null);
    }

    #[Test]
    public function aLanguageOfAnotherHostIsAFinding(): void
    {
        $finding = LeakGuard::check(self::server('site-b.test', '/en/'), 'site-b.test', self::request('http://site-b.test/en/', 'http://site-a.test/en/'));

        self::assertSame('language_host', $finding['check'] ?? null);
    }

    #[Test]
    public function aLanguageWhoseBaseIsNotInThePathIsAFinding(): void
    {
        $finding = LeakGuard::check(self::server('example.org', '/zweite-seite'), 'example.org', self::request('https://example.org/zweite-seite', '/en/'));

        self::assertSame('language_path', $finding['check'] ?? null);
    }

    #[Test]
    public function aMemoisedValueOfAnotherRequestIsAFinding(): void
    {
        $_SERVER['HTTP_HOST'] = 'site-b.test';
        $_SERVER['REQUEST_URI'] = '/zweite-seite';
        GeneralUtility::setIndpEnv('REQUEST_URI', '/en/');

        $finding = LeakGuard::staleIndpEnv();

        self::assertSame(['check' => 'indpenv:REQUEST_URI', 'expected' => '/zweite-seite', 'actual' => '/en/'], $finding);
    }

    #[Test]
    public function freshlyMemoisedValuesAreNoFindingAndTheMemoIsEmptiedAfterwards(): void
    {
        $_SERVER['HTTP_HOST'] = 'site-b.test';
        $_SERVER['REQUEST_URI'] = '/zweite-seite';
        LeakGuard::indpEnv('HTTP_HOST');
        LeakGuard::indpEnv('REQUEST_URI');

        self::assertNull(LeakGuard::staleIndpEnv());

        $_SERVER['HTTP_HOST'] = 'site-a.test';
        self::assertSame('site-a.test', LeakGuard::indpEnv('HTTP_HOST'));
    }

    #[Test]
    public function aFindingIsOneJsonLine(): void
    {
        $line = LeakGuard::logLine(['check' => 'indpenv_host', 'expected' => 'b', 'actual' => 'a'], 7);

        self::assertSame('{"koh_worker_leak":"frontend","check":"indpenv_host","expected":"b","actual":"a","worker_request":7}', $line);
    }

    #[Test]
    public function aGlobalRequestWithoutLanguageIsNoFinding(): void
    {
        self::assertNull(LeakGuard::check(self::server('example.org', '/'), 'example.org', self::request('https://example.org/')));
    }

    #[Test]
    public function aValueThatCannotBeComputedAgainIsNoFinding(): void
    {
        // _ARRAY needs TYPO3's Environment, which a unit test does not set up.
        GeneralUtility::setIndpEnv('_ARRAY', ['HTTP_HOST' => 'site-a.test']);

        self::assertNull(LeakGuard::staleIndpEnv());
    }

    #[Test]
    public function aStaleBooleanIsReportedAsJson(): void
    {
        $_SERVER['HTTPS'] = 'on';
        GeneralUtility::setIndpEnv('TYPO3_SSL', false);

        $finding = LeakGuard::staleIndpEnv();

        self::assertSame(['check' => 'indpenv:TYPO3_SSL', 'expected' => 'true', 'actual' => 'false'], $finding);
    }
}
