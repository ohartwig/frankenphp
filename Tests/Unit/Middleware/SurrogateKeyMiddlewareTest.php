<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Middleware;

use Koh\FrankenPHP\Middleware\SurrogateKeyMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use TYPO3\CMS\Core\Cache\CacheDataCollector;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * AUD-062 F01, step 2: something has to emit Surrogate-Key, or every tag purge
 * matches nothing. Souin splits the header on ", ", so that is the separator.
 */
final class SurrogateKeyMiddlewareTest extends TestCase
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $logged = [];

    /**
     * @param array<string, mixed> $settings
     * @param list<string>         $collectedWhileRendering tags the inner handler adds to the collector, as the render does
     */
    private function handle(
        ServerRequestInterface $request,
        ?ResponseInterface $response = null,
        array $settings = [],
        ?bool $byTag = null,
        array $collectedWhileRendering = [],
    ): ResponseInterface {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($settings);
        $handler = new class ($response ?? new Response(), $collectedWhileRendering) implements RequestHandlerInterface {
            /** @param list<string> $tags */
            public function __construct(private readonly ResponseInterface $response, private readonly array $tags) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $collector = $request->getAttribute('frontend.cache.collector');
                if ($collector instanceof CacheDataCollector) {
                    $collector->addCacheTags(...array_map(static fn (string $tag): CacheTag => new CacheTag($tag), $this->tags));
                }

                return $this->response;
            }
        };
        $logger = new class ($this->logged) extends AbstractLogger {
            /** @param list<array<string, mixed>> $records */
            public function __construct(private array &$records) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };

        return (new SurrogateKeyMiddleware($configuration, $logger, $byTag))->process($request, $handler);
    }

    private function renderedPage(int $pageId): ServerRequestInterface
    {
        return (new ServerRequest('https://www.example.org/blog/seite/2', 'GET'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.org/']))
            ->withAttribute('routing', new PageArguments($pageId, '0', []))
            ->withAttribute('frontend.cache.collector', new CacheDataCollector());
    }

    private function page(int $id): object
    {
        return new class ($id) {
            public function __construct(private readonly int $id) {}

            public function getId(): int
            {
                return $this->id;
            }
        };
    }

    #[Test]
    public function aRenderedPageCarriesItsSiteAndItsPageIdTag(): void
    {
        $request = (new ServerRequest('https://www.example.org/', 'GET'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.org/']))
            ->withAttribute('frontend.page.information', $this->page(42));

        $response = $this->handle($request);

        self::assertSame('main, pageId_42', $response->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function anExistingSurrogateKeyIsExtendedNotReplaced(): void
    {
        $request = (new ServerRequest('https://www.example.org/', 'GET'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.org/']))
            ->withAttribute('frontend.page.information', $this->page(7));

        $response = $this->handle($request, (new Response())->withHeader('Surrogate-Key', 'custom'));

        self::assertSame('custom, main, pageId_7', $response->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function aRequestWithoutSiteAndPageIsLeftAlone(): void
    {
        $response = $this->handle(new ServerRequest('https://www.example.org/', 'GET'));

        self::assertFalse($response->hasHeader('Surrogate-Key'));
    }

    #[Test]
    public function theHeaderNameFollowsTheConfiguration(): void
    {
        $request = (new ServerRequest('https://www.example.org/', 'GET'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.org/']));

        $response = $this->handle($request, null, ['cacheTagsHeader' => 'Cache-Tags']);

        self::assertSame('main', $response->getHeaderLine('Cache-Tags'));
        self::assertFalse($response->hasHeader('Surrogate-Key'));
    }

    // --- KOH_EDGE_PURGE_BY_TAG ------------------------------------------------

    /**
     * On TYPO3 14 the page information exists only behind this middleware, so
     * the switch-off path never added pageId_<n>. With the switch on, the page
     * id comes from the routing result and the rest from what TYPO3 collected
     * while rendering, read after the render.
     */
    #[Test]
    public function byTagTheResponseCarriesTheCollectedCacheTagsPageTagsFirst(): void
    {
        $response = $this->handle($this->renderedPage(2300), null, [], true, ['menuId_2', 'pageId_2303', 'pageId_2300', 'tt_content_5']);

        self::assertSame('main, pageId_2300, pageId_2303, menuId_2, tt_content_5', $response->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function byTagThePageTagIsThereEvenWhenNothingWasCollected(): void
    {
        $response = $this->handle($this->renderedPage(42), null, [], true);

        self::assertSame('main, pageId_42', $response->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function byTagAnExistingHeaderIsExtended(): void
    {
        $response = $this->handle($this->renderedPage(42), (new Response())->withHeader('Surrogate-Key', 'custom'), [], true, ['tt_content_1']);

        self::assertSame('custom, main, pageId_42, tt_content_1', $response->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function byTagAnOversizedTagSetIsCutAndLogged(): void
    {
        $many = array_map(static fn (int $i): string => 'tx_example_domain_model_item_' . $i, range(1, 1000));

        $response = $this->handle($this->renderedPage(42), null, [], true, $many);

        $header = $response->getHeaderLine('Surrogate-Key');
        self::assertStringStartsWith('main, pageId_42, ', $header);
        self::assertLessThanOrEqual(8000, strlen($header));
        self::assertCount(1, $this->logged);
        self::assertSame('Caddy Cache: Surrogate-Key truncated', $this->logged[0]['message']);
    }

    #[Test]
    public function byTagARequestThatWasNeverRoutedKeepsTheSiteTag(): void
    {
        $request = (new ServerRequest('https://www.example.org/', 'GET'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.org/']));

        self::assertSame('main', $this->handle($request, null, [], true)->getHeaderLine('Surrogate-Key'));
    }

    #[Test]
    public function withoutTheSwitchCollectedTagsStayOff(): void
    {
        $response = $this->handle($this->renderedPage(2300), null, [], false, ['pageId_2300', 'tt_content_5']);

        self::assertSame('main', $response->getHeaderLine('Surrogate-Key'));
    }

    /** The same filter as the purge: the tags of a backend-only table stay off the header. */
    #[Test]
    public function byTagTheTagsOfABackendOnlyTableStayOff(): void
    {
        $GLOBALS['TCA'] = ['tx_scheduler_task' => ['ctrl' => ['adminOnly' => true]], 'tt_content' => ['ctrl' => []]];
        try {
            $response = $this->handle($this->renderedPage(42), null, [], true, ['tx_scheduler_task_1', 'tt_content_5', 'menuId_1']);
        } finally {
            unset($GLOBALS['TCA']);
        }

        self::assertSame('main, pageId_42, tt_content_5, menuId_1', $response->getHeaderLine('Surrogate-Key'));
    }
}
