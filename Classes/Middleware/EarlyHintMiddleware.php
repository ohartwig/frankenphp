<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\SystemResource\Publishing\SystemResourcePublisherInterface;
use TYPO3\CMS\Core\SystemResource\SystemResourceFactory;

class EarlyHintMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AssetCollector $assetCollector,
        private readonly SystemResourceFactory $resourceFactory,
        private readonly SystemResourcePublisherInterface $resourcePublisher,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $response = $handler->handle($request);

        $uniqueLinks = [];

        // Add early hints for Styles
        foreach ($this->assetCollector->getStyleSheets() as $style) {
            $path = $this->resolveAssetWebPath((string) ($style['source'] ?? ''), $request);
            if (null === $path) {
                continue;
            }
            $key = 'style:' . $path;
            $uniqueLinks[$key] = '<' . $path . '>; rel=preload; as=style';
        }

        // Add early hints for Scripts
        foreach ($this->assetCollector->getJavaScripts() as $script) {
            $path = $this->resolveAssetWebPath((string) ($script['source'] ?? ''), $request);
            if (null === $path) {
                continue;
            }
            $key = 'script:' . $path;
            $uniqueLinks[$key] = '<' . $path . '>; rel=preload; as=script';
        }

        if ([] === $uniqueLinks) {
            return $response;
        }

        $linkHeader = implode(',', $uniqueLinks);

        // Echte 103 Early Hints HTTP-Response: FrankenPHP-natives `headers_send(103)`
        // sendet einen Interim-Response (RFC 8297) BEVOR der 200-Body raus geht.
        // Browser kann CSS/JS-Preloads dann parallel zum Server-Rendering anfangen.
        //
        // Die Funktion ist nur im FrankenPHP-Worker-/Server-Kontext registriert
        // (nicht in CLI, nicht in php-fpm, nicht in cgi). Der function_exists()-
        // Guard macht die Middleware dort fail-safe — fällt zurück auf den
        // klassischen `Link:`-Header in der 200-Response (von Browser ebenfalls
        // als Preload akzeptiert, aber später).
        //
        // header() schreibt in den FrankenPHP-Header-Buffer; headers_send(103)
        // flusht ihn als 103-Antwort und resetet den Buffer, sodass nachfolgende
        // 200-Headers (von TYPO3 via PSR-7-Output) konfliktfrei rausgehen.
        if (\function_exists('headers_send')) {
            \header('Link: ' . $linkHeader);
            \headers_send(103);
        }

        // Zusätzlich Link-Header in der 200-Response. Vorteile:
        //   - Fallback wenn 103 nicht gesendet wurde (z.B. Test-/CLI-Kontext)
        //   - HTTP/2-Push-fähige Reverse-Proxies können den 200-Link nutzen
        //   - Duplicate-Preload-Hints werden von Browsern dedupliziert
        return $response->withAddedHeader('Link', $linkHeader);
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
