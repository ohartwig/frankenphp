<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Middleware;

use Koh\FrankenPHP\Middleware\EarlyHintMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
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
}
