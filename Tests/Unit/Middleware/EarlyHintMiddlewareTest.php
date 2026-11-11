<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Middleware;

use Koh\FrankenPHP\Http\EarlyHintMemo;
use Koh\FrankenPHP\Middleware\EarlyHintMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;
use TYPO3\CMS\Core\SystemResource\Type\PublicResourceInterface;

/**
 * The Link header this middleware adds, and the URLs in it.
 *
 * A preload hint is only useful if its URL is byte-identical to the one the
 * page later requests. Get it wrong and the browser fetches the asset twice —
 * the failure is a slower page and a Lighthouse warning, never an error, so
 * nothing else would report it.
 *
 * That is why the EXT: branch exists at all. The original code called
 * getAbsoluteWebPath() for everything, which does no EXT: resolution, and the
 * header went out with raw `EXT:...` URLs that 404. And it is why the version
 * string is applied exactly once: the resource API already returns a versioned
 * URI, so running createVersionNumberedFilename() over it again appends a
 * second one and the preload stops matching.
 *
 * The resolver is injected rather than static since the middleware moved off
 * PathUtility::getSystemResourceUri(), which is what makes any of this
 * reachable from a unit test.
 */
final class EarlyHintMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $resolved = [];

    protected function setUp(): void
    {
        // Outside a worker the memo is off; tests that need it install one.
        EarlyHintMemo::setInstance(null);
    }

    protected function tearDown(): void
    {
        EarlyHintMemo::setInstance(null, false);
    }

    private function middleware(AssetCollector $collector): EarlyHintMiddleware
    {
        // The double is identity-preserving: the same identifier yields the
        // same resource object and the same URI, which is what the real API
        // does. A counter-based stub would hand out a fresh URL per call and
        // make the deduplication below look broken when it is not.
        $urls = new \SplObjectStorage();

        $factory = $this->createStub(SystemResourceFactory::class);
        $factory->method('createPublicResource')->willReturnCallback(
            function (string $identifier) use ($urls): PublicResourceInterface {
                $this->resolved[] = $identifier;
                $resource = $this->createStub(PublicResourceInterface::class);
                $urls[$resource] = '/_assets/hash/' . md5($identifier) . '.file?v=1';

                return $resource;
            }
        );

        $publisher = $this->createStub(SystemResourcePublisherInterface::class);
        $publisher->method('generateUri')->willReturnCallback(
            fn (PublicResourceInterface $resource): UriInterface => new Uri((string)$urls[$resource]),
        );

        return new EarlyHintMiddleware($collector, $factory, $publisher);
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        return $handler;
    }

    private function process(AssetCollector $collector): ResponseInterface
    {
        return $this->middleware($collector)->process(new ServerRequest('/a-page', 'GET'), $this->handler());
    }

    #[Test]
    public function aResponseWithoutAssetsCarriesNoLinkHeader(): void
    {
        $response = $this->process(new AssetCollector());

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function anExtensionAssetIsResolvedThroughTheResourceApi(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', 'EXT:frankenphp/Resources/Public/Css/a.css');

        $response = $this->process($collector);

        self::assertSame(['EXT:frankenphp/Resources/Public/Css/a.css'], $this->resolved);
        self::assertSame(
            '</_assets/hash/' . md5('EXT:frankenphp/Resources/Public/Css/a.css') . '.file?v=1>; rel=preload; as=style',
            $response->getHeaderLine('Link'),
        );
    }

    /**
     * The URI from the resource API already carries a version string. The
     * middleware must not add a second one — the preload URL would then differ
     * from the one the page requests, and the asset is fetched twice.
     */
    #[Test]
    public function anExtensionAssetIsVersionedExactlyOnce(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', 'EXT:frankenphp/Resources/Public/Css/a.css');

        $link = $this->process($collector)->getHeaderLine('Link');

        self::assertSame(1, substr_count($link, 'v=1'));
        self::assertStringNotContainsString('&', $link);
    }

    #[Test]
    public function stylesAndScriptsAreAnnouncedWithTheirOwnAsType(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', 'EXT:frankenphp/Resources/Public/Css/a.css');
        $collector->addJavaScript('b', 'EXT:frankenphp/Resources/Public/JavaScript/b.js');

        $link = $this->process($collector)->getHeaderLine('Link');

        self::assertStringContainsString('; as=style', $link);
        self::assertStringContainsString('; as=script', $link);
        self::assertSame(2, substr_count($link, 'rel=preload'));
    }

    /**
     * The same file registered twice is one hint. Browsers deduplicate, but the
     * header is sent on every response and a duplicate costs bytes on each.
     */
    #[Test]
    public function theSameAssetIsAnnouncedOnce(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', 'EXT:frankenphp/Resources/Public/Css/a.css');
        $collector->addStyleSheet('a-again', 'EXT:frankenphp/Resources/Public/Css/a.css');

        $link = $this->process($collector)->getHeaderLine('Link');

        self::assertSame(1, substr_count($link, 'rel=preload'));
    }

    /**
     * An asset that cannot be resolved is left out rather than announced with a
     * broken URL: a 404 preload is worse than no preload, and it must not take
     * the response down with it.
     */
    #[Test]
    public function anAssetThatCannotBeResolvedIsSkippedRatherThanAnnounced(): void
    {
        $factory = $this->createStub(SystemResourceFactory::class);
        $factory->method('createPublicResource')->willThrowException(new \RuntimeException('gone'));

        $collector = new AssetCollector();
        $collector->addStyleSheet('a', 'EXT:frankenphp/Resources/Public/Css/missing.css');

        $middleware = new EarlyHintMiddleware(
            $collector,
            $factory,
            $this->createStub(SystemResourcePublisherInterface::class),
        );

        $response = $middleware->process(new ServerRequest('/a-page', 'GET'), $this->handler());

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function anEmptySourceIsIgnored(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', '');

        $response = $this->process($collector);

        self::assertFalse($response->hasHeader('Link'));
        self::assertSame([], $this->resolved);
    }

    /**
     * A site-relative path is a resource this installation publishes, so it goes
     * through the same resolver as an EXT: path. It used to take a separate
     * branch through getAbsoluteWebPath() plus createVersionNumberedFilename(),
     * both deprecated for removal in v15.
     */
    #[Test]
    public function aSiteRelativeSourceIsResolvedThroughTheResourceApiToo(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('a', '/fileadmin/theme/a.css');

        $this->process($collector);

        self::assertSame(['/fileadmin/theme/a.css'], $this->resolved);
    }

    /**
     * An external URL is already the URL the browser will request. Handing it to
     * the resource API would be asking this installation to publish someone
     * else's file, and the hint would be dropped — losing the preload for every
     * CDN-hosted asset.
     */
    #[Test]
    public function anExternalUrlIsAnnouncedAsItIs(): void
    {
        $collector = new AssetCollector();
        $collector->addJavaScript('b', 'https://cdn.example.org/b.js');
        $collector->addStyleSheet('c', '//cdn.example.org/c.css');

        $link = $this->process($collector)->getHeaderLine('Link');

        self::assertSame([], $this->resolved);
        self::assertStringContainsString('<https://cdn.example.org/b.js>; rel=preload; as=script', $link);
        self::assertStringContainsString('<//cdn.example.org/c.css>; rel=preload; as=style', $link);
    }

    #[Test]
    public function anEmptySourceIsSkippedBeforeAnythingIsResolved(): void
    {
        $collector = new AssetCollector();
        $collector->addStyleSheet('empty', '');
        $collector->addJavaScript('also-empty', '');

        $response = $this->process($collector);

        self::assertFalse($response->hasHeader('Link'));
        self::assertSame([], $this->resolved);
    }

    /**
     * A deferred script waits for the parser anyway. Preloading it would fetch
     * it at high priority, ahead of what the first paint needs.
     */
    #[Test]
    public function aDeferredOrAsyncScriptIsNotPreloaded(): void
    {
        $collector = new AssetCollector();
        $collector->addJavaScript('d', 'EXT:frankenphp/Resources/Public/JavaScript/d.js', ['defer' => 'defer']);
        $collector->addJavaScript('a', 'EXT:frankenphp/Resources/Public/JavaScript/a.js', ['async' => 'async']);
        $collector->addJavaScript('m', 'EXT:frankenphp/Resources/Public/JavaScript/m.js', ['type' => 'module']);

        $response = $this->process($collector);

        self::assertFalse($response->hasHeader('Link'));
    }

    private function htmlHandler(string $head, string $body = '<p>content</p>'): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new HtmlResponse('<!DOCTYPE html><html><head>' . $head . '</head><body>' . $body . '</body></html>')
        );

        return $handler;
    }

    private function navigation(string $uri = 'https://example.com/a-page'): ServerRequest
    {
        return (new ServerRequest($uri, 'GET'))
            ->withHeader('Accept', 'text/html,application/xhtml+xml')
            ->withHeader('Sec-Fetch-Dest', 'document');
    }

    /**
     * page.includeCSS and headerData never pass the AssetCollector, so the base
     * stylesheet and the font preload are only found in the rendered head.
     */
    #[Test]
    public function stylesheetsAndPreloadsOfTheRenderedHeadAreAnnounced(): void
    {
        $head = '<link rel="stylesheet" href="/_assets/a/Css/base.css?1" media="all" integrity="sha256-abc=">'
            . '<link rel="preload" as="font" type="font/woff2" crossorigin href="/_assets/a/Fonts/inter.woff2">'
            . '<link rel="preload" as="image" href="/fileadmin/hero.avif" fetchpriority="high"'
            . ' imagesrcset="/fileadmin/hero-640.avif 640w, /fileadmin/hero-1280.avif 1280w" imagesizes="100vw">'
            . '<link rel="stylesheet" href="/_assets/b/cb.css?2">';

        $response = $this->middleware(new AssetCollector())->process($this->navigation(), $this->htmlHandler($head));

        self::assertSame(
            '</_assets/a/Css/base.css?1>; rel=preload; as=style; integrity="sha256-abc=",'
            . '</_assets/b/cb.css?2>; rel=preload; as=style,'
            . '</_assets/a/Fonts/inter.woff2>; rel=preload; as=font; type="font/woff2"; crossorigin,'
            . '</fileadmin/hero.avif>; rel=preload; as=image; imagesrcset="/fileadmin/hero-640.avif 640w, /fileadmin/hero-1280.avif 1280w"; imagesizes="100vw"; fetchpriority="high"',
            $response->getHeaderLine('Link'),
        );
    }

    /**
     * A hint is fetched before the page's CSP is known, so only this origin's
     * own files are announced. A print stylesheet does not block rendering.
     */
    #[Test]
    public function thirdPartyPrintAndOtherLinksOfTheHeadAreLeftOut(): void
    {
        $head = '<link rel="stylesheet" href="https://cdn.example.org/x.css">'
            . '<link rel="stylesheet" href="//cdn.example.org/y.css">'
            . '<link rel="stylesheet" href="/print.css" media="print">'
            . '<link rel="preconnect" href="https://cdn.example.org">'
            . '<link rel="canonical" href="/a-page">'
            . '<link rel="preload" as="fetch" href="/api">'
            . '<link rel="stylesheet" href="/bad>.css">';

        $response = $this->middleware(new AssetCollector())->process($this->navigation(), $this->htmlHandler($head));

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function linksAfterTheHeadAreNotAnnounced(): void
    {
        $response = $this->middleware(new AssetCollector())->process(
            $this->navigation(),
            $this->htmlHandler('', '<link rel="stylesheet" href="/late.css">'),
        );

        self::assertFalse($response->hasHeader('Link'));
    }

    #[Test]
    public function theBodyIsStillReadableAfterTheHeadWasScanned(): void
    {
        $response = $this->middleware(new AssetCollector())->process(
            $this->navigation(),
            $this->htmlHandler('<link rel="stylesheet" href="/a.css">'),
        );

        self::assertStringStartsWith('<!DOCTYPE html>', $response->getBody()->getContents());
    }

    #[Test]
    public function aResponseOtherThanAnHtmlPageIsNotScanned(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(
            new HtmlResponse('<head><link rel="stylesheet" href="/a.css"></head>', 404)
        );

        $response = $this->middleware(new AssetCollector())->process($this->navigation(), $handler);

        self::assertFalse($response->hasHeader('Link'));
    }

    /**
     * What the next request of the same page will announce before its render.
     */
    #[Test]
    public function theHintsOfANavigationAreRememberedForTheNextRequest(): void
    {
        $memo = new EarlyHintMemo();
        EarlyHintMemo::setInstance($memo);

        $this->middleware(new AssetCollector())->process(
            $this->navigation('https://Example.com/a-page?x=1'),
            $this->htmlHandler('<link rel="stylesheet" href="/a.css">'),
        );

        self::assertSame(['</a.css>; rel=preload; as=style'], $memo->lookup('example.com', '/a-page'));
    }

    #[Test]
    public function aRequestThatIsNotANavigationIsNotRemembered(): void
    {
        $memo = new EarlyHintMemo();
        EarlyHintMemo::setInstance($memo);

        $this->middleware(new AssetCollector())->process(
            (new ServerRequest('https://example.com/a-page', 'GET'))->withHeader('Accept', 'application/json'),
            $this->htmlHandler('<link rel="stylesheet" href="/a.css">'),
        );

        self::assertSame([], $memo->lookup('example.com', '/a-page'));
    }
}
