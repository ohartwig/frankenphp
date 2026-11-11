<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Middleware;

use Koh\FrankenPHP\Service\EdgeCacheTags;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;

/**
 * Emits the Surrogate-Key header that makes Souin's tag purge match something.
 *
 * Souin files every cached response under the keys of its Surrogate-Key header and
 * purges by them; without the header a tag purge matches nothing (AUD-062 F01).
 * Each rendered page carries the identifier of its site, so "flush site" has
 * entries to find.
 *
 * With KOH_EDGE_PURGE_BY_TAG on, the response also carries the cache tags TYPO3
 * collected while rendering it (or read from the page cache with it), page tags
 * first, so that a purge by pageId_<n> reaches every URL the page renders under.
 * See EdgeCacheTags. The tags are read after the inner handler returns: the
 * collector is attached before this middleware and filled behind it. The page
 * information the switch-off path reads (frontend.page.information) is only set
 * behind this middleware on TYPO3 14, so the pageId_<n> it was meant to add does
 * not appear there; with the switch on, the page id comes from the routing
 * result instead.
 *
 * Souin splits the header on ", " (comma and space), so that is the separator here.
 * Files are served by the web server, not by TYPO3, and carry no key; they are
 * purged by their Souin key instead.
 */
class SurrogateKeyMiddleware implements MiddlewareInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        ?LoggerInterface $logger = null,
        private readonly ?bool $tagsFromCollector = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $header = $this->headerName();
        $tagsFromCollector = $this->tagsFromCollector ?? EdgeCacheTags::enabled();
        $tags = $tagsFromCollector
            ? $this->collectedTagsFor($request, $response->getHeaderLine($header))
            : $this->tagsFor($request);
        if ($tags === []) {
            return $response;
        }

        return $response->withHeader($header, EdgeCacheTags::join($response->getHeaderLine($header), ...$tags));
    }

    /**
     * @return list<string>
     */
    private function tagsFor(ServerRequestInterface $request): array
    {
        $tags = $this->siteTag($request);

        $pageInformation = $request->getAttribute('frontend.page.information');
        // Duck-typed: cms-frontend's PageInformation is not a declared dependency of this package.
        if (is_object($pageInformation) && method_exists($pageInformation, 'getId')) {
            $tags[] = 'pageId_' . (int)$pageInformation->getId();
        }

        // A site tag alone would let a purge by tag drop pages of a site no editor touched;
        // a response with neither is not a rendered page and is left alone.
        return $tags;
    }

    /**
     * The site identifier, the page's own pageId_<n> and the cache tags TYPO3
     * collected for this response. A response that never reached the page
     * resolver (no routing result) gets what tagsFor() gives it.
     *
     * @return list<string>
     */
    private function collectedTagsFor(ServerRequestInterface $request, string $existing): array
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return $this->tagsFor($request);
        }
        $pageId = $routing->getPageId();

        $collected = [EdgeCacheTags::pageTag($pageId)];
        $collector = $request->getAttribute('frontend.cache.collector');
        // Duck-typed: CacheDataCollector is @internal in TYPO3 13.4 and 14.
        if (is_object($collector) && method_exists($collector, 'getCacheTags')) {
            foreach ((array)$collector->getCacheTags() as $cacheTag) {
                if (is_object($cacheTag) && isset($cacheTag->name) && is_string($cacheTag->name)) {
                    $collected[] = $cacheTag->name;
                }
            }
        }

        $site = $this->siteTag($request);
        [$tags, $dropped] = EdgeCacheTags::forResponse($collected, $pageId, strlen(EdgeCacheTags::join($existing, ...$site)));
        if ($dropped > 0) {
            // A purge by one of the dropped tags misses this response; the
            // page tags come first and stay, so an edit of the page does not.
            $this->logger->warning('Caddy Cache: Surrogate-Key truncated', [
                'pageId' => $pageId,
                'dropped' => $dropped,
                'kept' => count($tags),
            ]);
        }

        return [...$site, ...$tags];
    }

    /**
     * @return list<string>
     */
    private function siteTag(ServerRequestInterface $request): array
    {
        $site = $request->getAttribute('site');

        return $site instanceof SiteInterface && $site->getIdentifier() !== '' ? [$site->getIdentifier()] : [];
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
