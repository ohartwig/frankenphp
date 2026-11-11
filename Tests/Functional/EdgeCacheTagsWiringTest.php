<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Functional;

use Koh\FrankenPHP\EventListener\CacheInvalidationListener;
use Koh\FrankenPHP\Middleware\SurrogateKeyMiddleware;
use Koh\FrankenPHP\Service\EdgeCacheTags;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What KOH_EDGE_PURGE_BY_TAG relies on in a real instance: where the
 * middleware sits in the frontend stack, and that the container builds the
 * classes whose constructors grew an optional argument.
 *
 * The position is the whole trick. The cache data collector is attached
 * before the site is resolved and filled behind this middleware, so the
 * middleware reads it after the inner handler returns; the routing result is
 * there before it. The page information it used to read is only set behind
 * it, which is why no pageId_<n> tag ever appeared on TYPO3 14 -- and nothing
 * but a real stack shows that.
 */
final class EdgeCacheTagsWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/frankenphp'];

    protected function tearDown(): void
    {
        putenv(EdgeCacheTags::ENV);
        parent::tearDown();
    }

    #[Test]
    public function theMiddlewareSitsBetweenTheCollectorAndTheRender(): void
    {
        // TYPO3 keeps the stack innermost first; reversed, it reads in request order.
        $stack = array_reverse(array_keys($this->get('frontend.middlewares')->getArrayCopy()));
        $position = static fn (string $name): int => (int)array_search($name, $stack, true);

        self::assertContains('frankenphp-surrogate-key', $stack);
        self::assertLessThan($position('frankenphp-surrogate-key'), $position('typo3/cms-core/cache-tags-attribute'));
        self::assertLessThan($position('frankenphp-surrogate-key'), $position('typo3/cms-frontend/page-resolver'));
        self::assertGreaterThan($position('frankenphp-surrogate-key'), $position('typo3/cms-frontend/prepare-tsfe-rendering'));
    }

    #[Test]
    public function theContainerBuildsTheMiddlewareAndTheListener(): void
    {
        self::assertInstanceOf(SurrogateKeyMiddleware::class, $this->get(SurrogateKeyMiddleware::class));
        self::assertInstanceOf(CacheInvalidationListener::class, $this->get(CacheInvalidationListener::class));
    }

    /**
     * The container-built middleware reads the switch from the environment
     * and the tags from TYPO3's own collector, filled by the render.
     */
    #[Test]
    public function switchedOnTheResponseCarriesWhatTheRenderCollected(): void
    {
        putenv(EdgeCacheTags::ENV . '=1');
        $request = (new ServerRequest('https://www.example.org/blog/seite/2', 'GET'))
            ->withAttribute('routing', new PageArguments(12, '0', []))
            ->withAttribute('frontend.cache.collector', new CacheDataCollector());
        $render = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $request->getAttribute('frontend.cache.collector')->addCacheTags(new CacheTag('menuId_1'), new CacheTag('pageId_13'));

                return new Response();
            }
        };

        $response = $this->get(SurrogateKeyMiddleware::class)->process($request, $render);

        self::assertSame('pageId_12, pageId_13, menuId_1', $response->getHeaderLine('Surrogate-Key'));
    }
}
