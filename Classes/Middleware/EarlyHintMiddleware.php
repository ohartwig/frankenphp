<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Middleware;

use Koh\FrankenPHP\Http\EarlyHintMemo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;

/**
 * Preload hints for the render-critical resources of a page: as a 103 Early
 * Hints response before TYPO3 renders, and as a `Link` header on the final
 * response.
 *
 * What a page needs is known only after the render: the stylesheets and the
 * parser-blocking scripts from the AssetCollector, and the stylesheets, font
 * and image preloads in the rendered `<head>` (page.includeCSS and headerData
 * do not go through the AssetCollector). The 103 therefore announces what the
 * last render of the same page needed, or what all pages of the host had in
 * common, from {@see EarlyHintMemo}. While TYPO3 renders - a few milliseconds
 * from the page cache, a few hundred without it - the browser already loads
 * the stylesheets and the font.
 *
 * Until 5.11 the 103 went out after the render, together with the final
 * response, which gave the browser no head start.
 *
 * The Link header on the final response carries the full list. It reaches the
 * browser with the response headers, before the body is parsed, and an edge
 * cache keeps it with the page.
 */
class EarlyHintMiddleware implements MiddlewareInterface
{
    /** Bytes of the response body searched for the end of `<head>`. */
    private const HEAD_SCAN_BYTES = 65536;

    /** `as` values a preload in the rendered head may carry over into a hint. */
    private const PRELOAD_TYPES = ['style', 'font', 'image', 'script'];

    /** Link parameters copied from a `<link rel="preload">` element, in this order. */
    private const PRELOAD_PARAMETERS = ['type', 'media', 'imagesrcset', 'imagesizes', 'fetchpriority'];

    public function __construct(
        private readonly AssetCollector $assetCollector,
        private readonly SystemResourceFactory $resourceFactory,
        private readonly SystemResourcePublisherInterface $resourcePublisher,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $memo = EarlyHintMemo::active();
        $host = $request->getUri()->getHost();
        $path = $request->getUri()->getPath();
        $isNavigation = $this->isDocumentNavigation($request);

        if (null !== $memo && $isNavigation) {
            $this->sendEarlyHints($memo->lookup($host, $path));
        }

        $response = $handler->handle($request);

        $links = $this->assetCollectorLinks($request);
        if ($this->isHtmlPage($request, $response)) {
            // The head first: it is in document order, the base stylesheet
            // before those of the content elements.
            $links = $this->headLinks($response) + $links;
            if (null !== $memo && $isNavigation) {
                $memo->record($host, $path, array_values($links));
            }
        }

        if ([] === $links) {
            return $response;
        }

        return $response->withAddedHeader('Link', implode(',', $links));
    }

    /**
     * Sends a 103 response (RFC 8297) with the given hints, ahead of the final
     * response.
     *
     * `headers_send()` is FrankenPHP's and only exists in its server and
     * worker context, not in the CLI or php-fpm; there nothing is sent. It
     * flushes PHP's header buffer as the 103. Anything already in that buffer
     * belongs to the final response, so it is taken out before and put back
     * after.
     *
     * @param list<string> $links
     */
    private function sendEarlyHints(array $links): void
    {
        if ([] === $links || !\function_exists('headers_send') || \headers_sent()) {
            return;
        }

        $pending = \headers_list();
        \header_remove();
        \header('Link: ' . implode(',', $links));
        \headers_send(103);
        \header_remove();
        foreach ($pending as $line) {
            \header($line, false);
        }
    }

    /**
     * A browser loading a document. Early hints are used for navigations only;
     * a fetch() of the same URL, an API client or a crawler gains nothing from
     * a 103. Sec-Fetch-Dest is absent in older clients, Accept is not.
     */
    private function isDocumentNavigation(ServerRequestInterface $request): bool
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        $destination = strtolower($request->getHeaderLine('Sec-Fetch-Dest'));
        if ('' !== $destination && 'document' !== $destination && 'iframe' !== $destination) {
            return false;
        }

        return str_contains(strtolower($request->getHeaderLine('Accept')), 'text/html');
    }

    private function isHtmlPage(ServerRequestInterface $request, ResponseInterface $response): bool
    {
        return 200 === $response->getStatusCode()
            && \in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/html');
    }

    /**
     * Stylesheets, and scripts that block the parser, registered with the
     * AssetCollector.
     *
     * A deferred, async or module script is not preloaded: a preload is
     * fetched at high priority, such a script at low, and announcing it would
     * make it compete with the stylesheets and the LCP image it should wait
     * behind.
     *
     * @return array<string, string> URL => link value
     */
    private function assetCollectorLinks(ServerRequestInterface $request): array
    {
        $links = [];

        foreach ($this->assetCollector->getStyleSheets() as $style) {
            $path = $this->resolveAssetWebPath((string) ($style['source'] ?? ''), $request);
            if (null === $path) {
                continue;
            }
            $links[$path] = '<' . $path . '>; rel=preload; as=style';
        }

        foreach ($this->assetCollector->getJavaScripts() as $script) {
            $attributes = (array) ($script['attributes'] ?? []);
            if ($this->isSet($attributes, 'defer') || $this->isSet($attributes, 'async')
                || 'module' === ($attributes['type'] ?? '')
            ) {
                continue;
            }
            $path = $this->resolveAssetWebPath((string) ($script['source'] ?? ''), $request);
            if (null === $path) {
                continue;
            }
            $links[$path] = '<' . $path . '>; rel=preload; as=script';
        }

        return $links;
    }

    /**
     * A boolean script attribute as the AssetCollector holds it: `true`,
     * `'defer'`, `'1'`; absent, `false`, `''` and `'0'` mean not set.
     *
     * @param array<mixed> $attributes
     */
    private function isSet(array $attributes, string $name): bool
    {
        $value = $attributes[$name] ?? false;

        return true === $value || (\is_string($value) && '' !== $value && '0' !== $value) || (\is_int($value) && 0 !== $value);
    }

    /**
     * Stylesheets and preloads in the rendered `<head>`, same-origin only.
     *
     * A third-party URL is left out: it would be fetched before the page's
     * Content-Security-Policy has been seen, and a hint is not the place to
     * decide that. A stylesheet with an `integrity` attribute passes it on, so
     * that the preloaded response can serve the element that checks it.
     *
     * @return array<string, string> URL => link value
     */
    private function headLinks(ResponseInterface $response): array
    {
        $body = $response->getBody();
        if (!$body->isSeekable() || !$body->isReadable()) {
            return [];
        }
        $body->rewind();
        $html = '';
        while (!$body->eof() && \strlen($html) < self::HEAD_SCAN_BYTES) {
            $chunk = $body->read(self::HEAD_SCAN_BYTES - \strlen($html));
            if ('' === $chunk) {
                break;
            }
            $html .= $chunk;
            if (false !== stripos($html, '</head>')) {
                break;
            }
        }
        $body->rewind();

        $end = stripos($html, '</head>');
        if (false !== $end) {
            $html = substr($html, 0, $end);
        }

        if (false === preg_match_all('/<link\b[^>]*>/i', $html, $matches)) {
            return [];
        }

        $styles = [];
        $preloads = [];
        foreach ($matches[0] as $tag) {
            $attributes = $this->attributes($tag);
            $href = $attributes['href'] ?? '';
            if (!$this->isSameOriginPath($href)) {
                continue;
            }
            $rel = ' ' . strtolower($attributes['rel'] ?? '') . ' ';
            if (str_contains($rel, ' stylesheet ')) {
                $media = strtolower(trim($attributes['media'] ?? ''));
                if ('' !== $media && 'all' !== $media && 'screen' !== $media) {
                    continue;
                }
                $link = '<' . $href . '>; rel=preload; as=style';
                if (isset($attributes['integrity']) && '' !== $attributes['integrity']) {
                    $link .= '; integrity="' . $this->quoted($attributes['integrity']) . '"';
                }
                $styles[$href] = $link;
                continue;
            }
            if (!str_contains($rel, ' preload ')) {
                continue;
            }
            $as = strtolower($attributes['as'] ?? '');
            if (!\in_array($as, self::PRELOAD_TYPES, true)) {
                continue;
            }
            $link = '<' . $href . '>; rel=preload; as=' . $as;
            foreach (self::PRELOAD_PARAMETERS as $parameter) {
                if (isset($attributes[$parameter]) && '' !== $attributes[$parameter]) {
                    $link .= '; ' . $parameter . '="' . $this->quoted($attributes[$parameter]) . '"';
                }
            }
            if (\array_key_exists('crossorigin', $attributes)) {
                $link .= 'use-credentials' === strtolower($attributes['crossorigin'])
                    ? '; crossorigin=use-credentials'
                    : '; crossorigin';
            }
            $preloads[$href] = $link;
        }

        // Stylesheets first: they block rendering, a font or an image does not.
        return $styles + $preloads;
    }

    /**
     * @return array<string, string> lower-case attribute name => decoded value ('' for a bare attribute)
     */
    private function attributes(string $tag): array
    {
        $attributes = [];
        preg_match_all(
            '/\s([a-zA-Z][a-zA-Z0-9:_-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>=`]+)))?/',
            substr($tag, 5),
            $matches,
            PREG_SET_ORDER,
        );
        foreach ($matches as $match) {
            $value = '';
            foreach ([2, 3, 4] as $group) {
                if (isset($match[$group]) && '' !== $match[$group]) {
                    $value = $match[$group];
                    break;
                }
            }
            $attributes[strtolower($match[1])] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    /**
     * A path on this origin that cannot end the `<...>` of a Link value or the
     * header line.
     */
    private function isSameOriginPath(string $href): bool
    {
        return str_starts_with($href, '/')
            && !str_starts_with($href, '//')
            && 1 !== preg_match('/[\s<>"\\\\,;]|[\x00-\x1f\x7f]/', $href);
    }

    private function quoted(string $value): string
    {
        return addcslashes((string) preg_replace('/[\x00-\x1f\x7f]/', '', $value), '"\\');
    }

    /**
     * Resolves an AssetCollector `source` to a browser-loadable URL.
     *
     * `$source` arrives in three shapes, and only one of them is a resource
     * this installation publishes:
     *   - an external URL, which is already what the browser will request
     *   - `EXT:<extkey>/Resources/Public/...`
     *   - a path relative to the site root, including fileadmin/ FAL paths
     *
     * The last two both go through the system resource API, which resolves each
     * of them and returns a URI that already carries a cache-busting version.
     * That replaced two deprecated calls at once: PathUtility::getAbsoluteWebPath()
     * plus GeneralUtility::createVersionNumberedFilename(), the second of which
     * is slated for removal in v15 with the same "use the system resource API
     * instead" note as the first. isExtensionPath() is gone too — the factory
     * recognises an EXT: identifier itself, so the branch was only ever routing
     * to the resolver that could handle it.
     *
     * ONE CASE CHANGES: an absolute filesystem path. getAbsoluteWebPath()
     * translated some of those into web paths; the resource API rejects them
     * outright ("Absolute paths are not allowed as resource identifiers"), so
     * such an asset now gets no early hint. It still loads normally from the
     * page — an early hint is an optimisation, and the alternative was keeping
     * a deprecated call alive for a shape AssetCollector is not meant to carry.
     *
     * Returns `null` when resolution fails, so the asset is left out rather
     * than announced with a URL that 404s.
     */
    private function resolveAssetWebPath(string $source, ServerRequestInterface $request): ?string
    {
        if ('' === $source) {
            return null;
        }

        // Protocol-relative or absolute URL. Inlined rather than calling
        // PathUtility::hasProtocolAndScheme(), which is @internal — it is these
        // two comparisons, and depending on an internal helper to make them is
        // how the previous deprecation got here.
        if (str_starts_with($source, '//') || strpos($source, '://') > 0) {
            return $source;
        }

        try {
            $resource = $this->resourceFactory->createPublicResource($source);

            return (string) $this->resourcePublisher->generateUri($resource, $request);
        } catch (\Throwable) {
            return null;
        }
    }
}
