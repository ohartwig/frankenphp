<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Cache invalidation service for Caddy/Souin.
 *
 * Invalidation goes through Souin's admin API (caddyHost:caddyPort + apiEndpoint), which is the
 * only endpoint that intercepts it in Souin v1.7.8 — a PURGE to the public content URL is proxied
 * upstream (405) and the edge blocks external PURGE by design. In k3s the admin endpoint is
 * reachable only via the caddy-proxy :443 mTLS listener under /souin-api/*, so a client cert is
 * configured (see tlsClientCert). PURGE stays structurally internal-only.
 */
class CaddyCacheService
{
    private ClientInterface $httpClient;

    // Off means: no PURGE, no admin API request, nothing. Local development runs
    // without a caddy-proxy; before this switch every cache flush there ended in
    // a logged connection error that looked like a real failure.
    private readonly bool $enabled;
    private readonly string $caddyHost;
    private readonly string $caddyPort;
    private readonly string $protocol;
    private readonly string $apiEndpoint;
    private readonly string $apiKey;
    private readonly bool $enableDebugLogging;
    private readonly int $httpTimeout;
    private readonly bool $invalidateAllSites;
    private readonly string $cacheTagsHeader;
    // Client certificate for mTLS to the proxy. In k3s the app purges the caddy-proxy
    // Souin API over the :443 mTLS listener, so it must present a client cert (app.crt).
    // Leave empty for a plain-HTTP endpoint (e.g. the compose admin port).
    private readonly string $tlsClientCert;
    private readonly string $tlsClientKey;
    private readonly string $tlsCaBundle;
    private readonly bool $tlsVerify;

    public function __construct(
        private readonly LoggerInterface $logger,
        ClientInterface $sharedHttpClient,
        private readonly SiteFinder $siteFinder,
        private readonly RequestFactoryInterface $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        // Extension configuration is stored as strings ('0'/'1' for the boolean options), so
        // every setting is normalised to the type this service actually uses instead of being
        // kept in an untyped bag and coerced again at each read site.
        $settings = $extensionConfiguration->get('frankenphp');
        $settings = is_array($settings) ? $settings : [];

        $this->enabled = self::readBool($settings, 'enabled', true);
        $this->caddyHost = self::readString($settings, 'caddyHost', 'localhost');
        $this->caddyPort = self::readString($settings, 'caddyPort', '2019');
        $this->protocol = self::readString($settings, 'protocol', 'http');
        $this->apiEndpoint = self::readString($settings, 'apiEndpoint', '/souin-api/souin');
        $this->apiKey = self::readString($settings, 'apiKey', '');
        $this->enableDebugLogging = self::readBool($settings, 'enableDebugLogging', false);
        $this->httpTimeout = self::readInt($settings, 'httpTimeout', 5);
        $this->invalidateAllSites = self::readBool($settings, 'invalidateAllSites', true);
        $this->cacheTagsHeader = self::readString($settings, 'cacheTagsHeader', 'Surrogate-Key');
        $this->tlsClientCert = self::readString($settings, 'tlsClientCert', '');
        $this->tlsClientKey = self::readString($settings, 'tlsClientKey', '');
        $this->tlsCaBundle = self::readString($settings, 'tlsCaBundle', '');
        $this->tlsVerify = self::readBool($settings, 'tlsVerify', true);

        $this->httpClient = $this->createHttpClient($sharedHttpClient);
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function readString(array $settings, string $key, string $default): string
    {
        $value = $settings[$key] ?? null;

        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * @param array<array-key, mixed> $settings
     */
    private static function readInt(array $settings, string $key, int $default): int
    {
        $value = $settings[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * Booleans arrive as the strings '0' and '1' from the extension configuration; anything
     * unset or non-scalar keeps the default rather than silently reading as off.
     *
     * @param array<array-key, mixed> $settings
     */
    private static function readBool(array $settings, string $key, bool $default): bool
    {
        $value = $settings[$key] ?? null;

        return is_scalar($value) ? (bool)$value : $default;
    }

    /**
     * When a client cert is configured, build a dedicated Guzzle client that presents it (mTLS to
     * caddy-proxy:443). Otherwise use the shared TYPO3 client (plain-HTTP endpoints).
     *
     * The shared client is injected as PSR-18 rather than built from GuzzleClientFactory: the
     * factory is core-internal and declares GuzzleHttp\ClientInterface, which does not extend
     * PSR-18 — while everything here only ever calls sendRequest(). TYPO3 aliases
     * Psr\Http\Client\ClientInterface to the very client that factory produces.
     */
    private function createHttpClient(ClientInterface $sharedHttpClient): ClientInterface
    {
        if ($this->tlsClientCert === '') {
            return $sharedHttpClient;
        }

        $options = [
            'timeout' => $this->httpTimeout,
            'http_errors' => false,
            'cert' => $this->tlsClientCert,
        ];
        if ($this->tlsClientKey !== '') {
            $options['ssl_key'] = $this->tlsClientKey;
        }
        $options['verify'] = $this->tlsCaBundle !== '' ? $this->tlsCaBundle : $this->tlsVerify;

        return new \GuzzleHttp\Client($options);
    }

    /**
     * Whether invalidations reach the proxy at all. Callers that do work before
     * asking this service (URL resolution, database lookups) check it first.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param list<string> $urls
     */
    public function invalidateUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->sendPurgeToProxy($url);
        }
    }

    /**
     * @param list<string> $tags
     */
    public function invalidateByTags(array $tags): void
    {
        $sites = $this->siteFinder->getAllSites();
        $firstSite = reset($sites);
        if ($firstSite === false) {
            return;
        }
        $proxyUrl = rtrim((string)$firstSite->getBase(), '/') . '/';
        $this->sendPurgeToProxy($proxyUrl, $tags);
    }

    public function invalidateAllSites(): void
    {
        if (!$this->invalidateAllSites) {
            return;
        }

        // One caddy-proxy/Souin store serves the whole multisite system, so a single flush of
        // all keys is enough (and more reliable than enumerating individual site bases).
        $this->flushAll();
    }

    /**
     * Flush the entire Souin cache of this proxy (all keys) via the admin API.
     */
    public function flushAll(): void
    {
        $this->purgeViaApi(rtrim($this->buildApiUrl(), '/') . '/.+', [], '*');
    }

    public function invalidateSite(Site $site): void
    {
        $this->invalidateByTags([$site->getIdentifier()]);
    }

    /**
     * @return list<string>
     */
    public function getPageUrls(int $pageId): array
    {
        $urls = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                foreach ($site->getAllLanguages() as $language) {
                    $uri = $site->getRouter()->generateUri($pageId, ['_language' => $language]);
                    $urls[] = (string)$uri;
                }
            // \Throwable, not \Exception. Route generation reaches deep into
            // core and fails with Errors as readily as with Exceptions — an
            // ArgumentCountError out of RequestContextFactory is what surfaced
            // this. The caller is processDatamap_afterDatabaseOperations, so
            // anything escaping here stops an editor saving a page; catching
            // half the failure modes made the guard look present while leaving
            // the other half to take the request down.
            } catch (\Throwable $e) {
                if ($this->enableDebugLogging) {
                    $this->logger->warning('Caddy Cache: Could not generate URL', [
                        'pageId' => $pageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        return $urls;
    }

    /**
     * @return list<string>
     */
    public function getKeys(): array
    {
        return $this->fetchFromAdminApi('/');
    }

    /**
     * @return list<string>
     */
    public function getSurrogateKeys(): array
    {
        return $this->fetchFromAdminApi('/surrogate_keys');
    }

    /**
     * Invalidate via Souin's **admin API** (caddyHost:caddyPort — in k3s the internal, not
     * publicly exposed admin port :2019), NOT via the public content URL: this Souin build
     * (v1.7.8) intercepts invalidation only on the admin endpoint — a PURGE to the content URL
     * is proxied upstream (405), and the public edge blocks external PURGE by design
     * (@purgeExternal -> 403). PURGE is therefore structurally internal-only.
     *
     * Per-URL purge uses the Souin key (METHOD-host-path; `key { disable_scheme }`),
     * tag purge uses the Surrogate-Key header.
     *
     * @param list<string> $tags
     */
    private function sendPurgeToProxy(string $url, array $tags = []): void
    {
        $target = rtrim($this->buildApiUrl(), '/');
        if ($tags === []) {
            $target .= '/' . $this->souinKeyForUrl($url);
        }
        $this->purgeViaApi($target, $tags, $url);
    }

    /**
     * Souin cache key of a URL: "GET-<host>-<path>", scheme dropped (`disable_scheme`
     * in the Caddyfile) and a dash between method, host and path -- that is what the
     * edge reports in its own Cache-Status header:
     *
     *   cache-status: HTTPCache; hit; key=GET-www.example.org-/
     *
     * Until 2026-09-09 the dash before the path was missing. Souin answered every
     * such PURGE with 204 and invalidated nothing, so the purge looked like it
     * worked in every log while the stale page stayed at the edge. Measured on
     * a customer site: "GET-host/" left the entry's Age untouched, "GET-host-/"
     * dropped it. The path part is evaluated as a regex by the Souin API.
     */
    private function souinKeyForUrl(string $url): string
    {
        $parts = parse_url($url);
        return 'GET-' . ($parts['host'] ?? '') . '-' . ($parts['path'] ?? '/');
    }

    /**
     * @param list<string> $tags
     */
    private function purgeViaApi(string $target, array $tags, string $ref): void
    {
        if (!$this->enabled) {
            return;
        }

        try {
            $request = $this->requestFactory->createRequest('PURGE', $target);

            if ($this->apiKey !== '') {
                $request = $request->withHeader('X-API-Key', $this->apiKey);
            }

            if ($tags !== []) {
                $request = $request->withHeader(
                    $this->cacheTagsHeader,
                    implode(' ', $tags),
                );
            }

            $response = $this->httpClient->sendRequest($request);

            // An answer is not an acknowledgement. A wrong API key (401), a
            // proxy in the way (405) or a Souin without the API (404) all come
            // back as responses, not exceptions -- and were logged nowhere
            // outside debug mode, so the page stayed stale with no trace.
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                $this->logger->error('Caddy Cache: PURGE failed', [
                    'target' => $target,
                    'ref' => $ref,
                    'statusCode' => $status,
                ]);
                return;
            }

            if ($this->enableDebugLogging) {
                $this->logger->debug('Caddy Cache: PURGE sent', [
                    'target' => $target,
                    'ref' => $ref,
                    'tags' => $tags,
                    'statusCode' => $response->getStatusCode(),
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Caddy Cache: PURGE failed', [
                'target' => $target,
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function fetchFromAdminApi(string $path): array
    {
        if (!$this->enabled) {
            return [];
        }

        $apiUrl = $this->buildApiUrl() . $path;

        try {
            $request = $this->requestFactory->createRequest('GET', $apiUrl);
            if ($this->apiKey !== '') {
                $request = $request->withHeader('X-API-Key', $this->apiKey);
            }

            $response = $this->httpClient->sendRequest($request);
            $stream = $response->getBody();
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $body = $stream->getContents();

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $decoded = json_decode($body, true);
                return is_array($decoded) ? $this->normalizeSouinKeys($decoded) : [];
            }
        } catch (\Exception $e) {
            $this->logger->error('Caddy Cache: Admin API unavailable', [
                'url' => $apiUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * @param array<array-key, mixed> $raw Decoded Souin admin API payload
     * @return list<string>
     */
    private function normalizeSouinKeys(array $raw): array
    {
        $result = [];
        $append = static function (?string $val) use (&$result) {
            if ($val === null) {
                return;
            }
            foreach (explode(',', $val) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $result[] = urldecode($part);
                }
            }
        };

        if (array_keys($raw) === range(0, count($raw) - 1)) {
            foreach ($raw as $v) {
                if (is_string($v)) {
                    $append($v);
                }
            }
        } else {
            foreach ($raw as $k => $v) {
                if (is_string($k)) {
                    $append($k);
                }
                if (is_string($v)) {
                    $append($v);
                } elseif (is_array($v)) {
                    foreach ($v as $inner) {
                        if (is_string($inner)) {
                            $append($inner);
                        }
                    }
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function buildApiUrl(): string
    {
        return sprintf(
            '%s://%s:%s%s',
            $this->protocol,
            $this->caddyHost,
            $this->caddyPort,
            $this->apiEndpoint,
        );
    }
}
