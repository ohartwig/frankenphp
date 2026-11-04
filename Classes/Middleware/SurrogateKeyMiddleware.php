<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Emits the Surrogate-Key header that makes Souin's tag purge match something.
 *
 * Souin files every cached response under the keys of its Surrogate-Key header and
 * purges by them; without the header a tag purge matches nothing (AUD-062 F01).
 * Each rendered page carries the identifier of its site and TYPO3's own
 * "pageId_<n>" tag, so "flush site" and the pageId_<n> purges have entries to find.
 *
 * Souin splits the header on ", " (comma and space), so that is the separator here.
 * Files are served by the web server, not by TYPO3, and carry no key; they are
 * purged by their Souin key instead.
 */
class SurrogateKeyMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ExtensionConfiguration $extensionConfiguration) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $tags = $this->tagsFor($request);
        if ($tags === []) {
            return $response;
        }

        $header = $this->headerName();
        $existing = $response->getHeaderLine($header);
        $value = $existing === '' ? implode(', ', $tags) : $existing . ', ' . implode(', ', $tags);

        return $response->withHeader($header, $value);
    }

    /**
     * @return list<string>
     */
    private function tagsFor(ServerRequestInterface $request): array
    {
        $tags = [];

        $site = $request->getAttribute('site');
        if ($site instanceof SiteInterface && $site->getIdentifier() !== '') {
            $tags[] = $site->getIdentifier();
        }

        $pageInformation = $request->getAttribute('frontend.page.information');
        // Duck-typed: cms-frontend's PageInformation is not a declared dependency of this package.
        if (is_object($pageInformation) && method_exists($pageInformation, 'getId')) {
            $tags[] = 'pageId_' . (int)$pageInformation->getId();
        }

        // A site tag alone would let a purge by tag drop pages of a site no editor touched;
        // a response with neither is not a rendered page and is left alone.
        return $tags;
    }

    private function headerName(): string
    {
        try {
            $settings = $this->extensionConfiguration->get('frankenphp');
        } catch (\Throwable) {
            $settings = [];
        }
        $name = is_array($settings) ? ($settings['cacheTagsHeader'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'Surrogate-Key';
    }
}
