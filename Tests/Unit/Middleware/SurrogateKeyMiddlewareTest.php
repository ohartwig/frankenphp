<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Middleware;

use Koh\FrankenPHP\Middleware\SurrogateKeyMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * AUD-062 F01, step 2: something has to emit Surrogate-Key, or every tag purge
 * matches nothing. Souin splits the header on ", ", so that is the separator.
 */
final class SurrogateKeyMiddlewareTest extends TestCase
{
    /** @param array<string, mixed> $settings */
    private function handle(ServerRequestInterface $request, ?ResponseInterface $response = null, array $settings = []): ResponseInterface
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn($settings);
        $handler = new class ($response ?? new Response()) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return (new SurrogateKeyMiddleware($configuration))->process($request, $handler);
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
}
