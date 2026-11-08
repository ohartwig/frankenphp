<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Middleware;

use Koh\FrankenPHP\Middleware\CacheHashRedirectMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\CMS\Frontend\Page\CacheHashConfiguration;

/**
 * A page URL with a parameter the cHash would have to cover, but no cHash, is a 404
 * under enforceValidation. The middleware answers those, and only those, with a 301
 * to the same URL without the uncovered parameters.
 */
final class CacheHashRedirectMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $backupConf = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupConf = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = true;
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['enforceValidation'] = true;
        putenv(CacheHashRedirectMiddleware::ENV);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = $this->backupConf;
        putenv(CacheHashRedirectMiddleware::ENV);
        parent::tearDown();
    }

    private function middleware(): CacheHashRedirectMiddleware
    {
        $configuration = new CacheHashConfiguration([
            'excludedParameters' => ['L', 'utm_source', 'utm_medium', 'gclid'],
            'excludedParametersIfEmpty' => [],
            'excludeAllEmptyParameters' => false,
        ]);

        return new CacheHashRedirectMiddleware(new CacheHashCalculator($configuration, new HashService()));
    }

    private function process(ServerRequestInterface $request): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 200);
            }
        };

        return $this->middleware()->process($request, $handler);
    }

    private function request(string $url, string $method = 'GET'): ServerRequestInterface
    {
        $request = new ServerRequest($url, $method);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $request = $request->withQueryParams($query);

        return $request->withAttribute('routing', new PageArguments(42, '0', [], [], $query));
    }

    #[Test]
    public function anUncoveredParameterWithoutCacheHashIsRedirectedAway(): void
    {
        $response = $this->process($this->request('https://www.example.org/blog/?foo=bar'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://www.example.org/blog/', $response->getHeaderLine('Location'));
        self::assertSame('public, max-age=3600', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function excludedParametersStayInTheTarget(): void
    {
        $response = $this->process($this->request('https://www.example.org/blog/?utm_source=news&srsltid=abc&gclid=x'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://www.example.org/blog/?utm_source=news&gclid=x', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function nestedPluginArgumentsAreDroppedAsAWhole(): void
    {
        $response = $this->process($this->request('https://www.example.org/news/?tx_news_pi1%5Bnews%5D=7&tx_news_pi1%5Baction%5D=detail&L=1'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://www.example.org/news/?L=1', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function anEmptyCacheHashCountsAsMissingAndIsDroppedToo(): void
    {
        $response = $this->process($this->request('https://www.example.org/?cHash=&foo=1'));

        self::assertSame(301, $response->getStatusCode());
        self::assertSame('https://www.example.org/', $response->getHeaderLine('Location'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function requestsLeftToTypo3(): iterable
    {
        yield 'no query' => ['https://www.example.org/blog/', 'GET'];
        yield 'only excluded parameters' => ['https://www.example.org/blog/?utm_source=a&utm_medium=b', 'GET'];
        yield 'a cHash is present (wrong ones stay a 404)' => ['https://www.example.org/blog/?foo=bar&cHash=deadbeef', 'GET'];
        yield 'POST' => ['https://www.example.org/blog/?foo=bar', 'POST'];
    }

    #[Test]
    #[DataProvider('requestsLeftToTypo3')]
    public function everythingElseGoesOnToTypo3(string $url, string $method): void
    {
        self::assertSame(200, $this->process($this->request($url, $method))->getStatusCode());
    }

    #[Test]
    public function headIsRedirectedLikeGet(): void
    {
        self::assertSame(301, $this->process($this->request('https://www.example.org/?foo=1', 'HEAD'))->getStatusCode());
    }

    #[Test]
    public function withoutEnforcedValidationTypo3KeepsItsOwnBehaviour(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['cacheHash']['enforceValidation'] = false;

        self::assertSame(200, $this->process($this->request('https://www.example.org/?foo=1'))->getStatusCode());
    }

    #[Test]
    public function whenAMissingCacheHashIsNotA404NothingIsRedirected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['FE']['pageNotFoundOnCHashError'] = false;

        self::assertSame(200, $this->process($this->request('https://www.example.org/?foo=1'))->getStatusCode());
    }

    #[Test]
    public function theSwitchTurnsItOff(): void
    {
        putenv(CacheHashRedirectMiddleware::ENV . '=0');

        self::assertSame(200, $this->process($this->request('https://www.example.org/?foo=1'))->getStatusCode());
    }

    #[Test]
    public function withoutRoutingAttributeNothingHappens(): void
    {
        $request = new ServerRequest('https://www.example.org/?foo=1', 'GET');

        self::assertSame(200, $this->process($request->withQueryParams(['foo' => '1']))->getStatusCode());
    }

    #[Test]
    public function aFragmentNeverEndsUpInTheTarget(): void
    {
        $response = $this->process($this->request('https://www.example.org/a?foo=1#top'));

        self::assertSame('https://www.example.org/a', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function theTargetStaysOnTheRequestedHost(): void
    {
        $response = $this->process($this->request('https://www.example.org//evil.example/?foo=1'));

        self::assertStringStartsWith('https://www.example.org/', $response->getHeaderLine('Location'));
    }
}
