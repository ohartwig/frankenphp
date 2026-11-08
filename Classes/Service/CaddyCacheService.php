<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

    private readonly ConnectionPool $connectionPool;

    public function __construct(
        private readonly LoggerInterface $logger,
        ClientInterface $sharedHttpClient,
        private readonly SiteFinder $siteFinder,
        private readonly RequestFactoryInterface $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
        ?ConnectionPool $connectionPool = null,
    ) {
        $this->connectionPool = $connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class);
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
     * Purge each URL by its exact Souin key, then the query-string variants
     * ("/?utm_source=…") of every host involved, once per host and call. See
     * souinKeyForUrl() for why both are needed and why they cannot be one key.
     *
     * @param list<string> $urls
     */
    public function invalidateUrls(array $urls): void
    {
        $hosts = [];
        foreach (array_unique($urls) as $url) {
            $this->sendPurgeToProxy($url);
            $host = self::hostOf($url);
            if ($host !== '') {
                $hosts[$host] = $url;
            }
        }
        $target = rtrim($this->buildApiUrl(), '/');
        foreach ($hosts as $host => $url) {
            $this->purgeViaApi($target . '/GET-' . self::souinLiteral($host) . '-/?.+=', [], $url . ' (query variants)');
        }
    }

    /**
     * @param list<string> $tags
     */
    public function invalidateByTags(array $tags): void
    {
        $this->purgeTags($tags);
    }

    /**
     * Purge by Surrogate-Key and say what happened. Souin answers 204 to every PURGE,
     * matching or not, so a tag is only purged when Souin lists it as a key it holds;
     * every other tag is logged as "no Surrogate-Key emitted" and reported unmatched.
     *
     * @param list<string> $tags
     */
    public function purgeTags(array $tags): PurgeResult
    {
        if (!$this->enabled) {
            return PurgeResult::disabled();
        }
        $sites = $this->siteFinder->getAllSites();
        if (reset($sites) === false || $tags === []) {
            return PurgeResult::done([], []);
        }

        $known = $this->fetchSurrogateTags();
        if ($known === null) {
            return PurgeResult::failed($tags);
        }

        $purgeable = [];
        $unmatched = [];
        foreach ($tags as $tag) {
            if (in_array($tag, $known, true)) {
                $purgeable[] = $tag;
                continue;
            }
            $unmatched[] = $tag;
            $this->logger->warning('Caddy Cache: no Surrogate-Key emitted for tag', ['tag' => $tag]);
        }

        if ($purgeable === []) {
            return PurgeResult::done([], $unmatched);
        }
        if (!$this->purgeViaApi(rtrim($this->buildApiUrl(), '/'), $purgeable, implode(', ', $purgeable))) {
            return PurgeResult::failed($tags);
        }

        return PurgeResult::done($purgeable, $unmatched);
    }

    /**
     * Tags Souin holds entries for, without its "SURROGATE_" storage prefix.
     * Null when the admin API could not be read at all.
     *
     * @return list<string>|null
     */
    private function fetchSurrogateTags(): ?array
    {
        $keys = $this->fetchFromAdminApi('/surrogate_keys', true);
        if ($keys === null) {
            return null;
        }

        return array_values(array_unique(array_map(
            static fn(string $key): string => str_starts_with($key, 'SURROGATE_') ? substr($key, 10) : $key,
            $keys,
        )));
    }

    /**
     * Purge the public paths of fileadmin files (and their processed variants) on every
     * site host. Files cannot be purged by tag -- nothing emits Surrogate-Key -- so each
     * path is purged by its Souin key "GET-<host>-<path>", anchored at the end so that
     * a.pdf does not also take a.pdf.zip with it (see souinKeyForUrl()).
     *
     * Until 2026-11-08 the path went through preg_quote(). The HTTP client
     * percent-encodes the backslashes that adds, Souin does not decode them,
     * and the key matched nothing: every file purge answered 204 and left the
     * file at the edge.
     *
     * @param list<string> $paths Absolute public paths, e.g. "/fileadmin/a/b.pdf"
     */
    public function invalidateFilePaths(array $paths): void
    {
        $hosts = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $bases = [$site->getBase()];
            foreach ($site->getAllLanguages() as $language) {
                $bases[] = $language->getBase();
            }
            foreach ($bases as $base) {
                $host = $base->getHost();
                if ($host !== '') {
                    $hosts[$host] = true;
                }
            }
        }

        $target = rtrim($this->buildApiUrl(), '/');
        foreach (array_keys($hosts) as $host) {
            foreach (array_unique($paths) as $path) {
                $key = 'GET-' . self::souinLiteral($host) . '-' . self::souinLiteral(rawurldecode($path)) . '$';
                $this->purgeViaApi($target . '/' . $key, [], $path);
            }
        }
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
        $this->flushSite($site);
    }

    /**
     * Purge everything cached under the site's identifier tag (emitted by
     * SurrogateKeyMiddleware) and report whether anything was there to purge.
     */
    public function flushSite(Site $site): PurgeResult
    {
        return $this->purgeTags([$site->getIdentifier()]);
    }

    /**
     * The public URLs of a page: one per enabled language of the page's own site
     * in which the page exists.
     *
     * Built from the live page records themselves, hidden and deleted ones
     * included: a page that was just hidden or deleted is exactly the one whose
     * cached copy has to go. Asking the router with the bare page id instead
     * answered for such a page -- and for a language without a translation --
     * with the site base, so every save of a hidden page purged the start page
     * (and, before keys were anchored, the whole host). A language without a
     * translation only gets a URL when it falls back to the default language,
     * because only then does it render the page.
     *
     * @return list<string>
     */
    public function getPageUrls(int $pageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException) {
            return [];
        }
        try {
            $records = $this->liveRecordsByLanguage($pageId);
        } catch (\Throwable $e) {
            $this->logUrlFailure($pageId, $e);
            $records = [];
        }
        $default = $records[0] ?? null;
        if ($default === null) {
            return [];
        }

        $urls = [];
        foreach ($site->getLanguages() as $language) {
            $languageId = $language->getLanguageId();
            $record = $records[$languageId] ?? null;
            if ($record === null) {
                if ($language->getFallbackType() !== 'fallback') {
                    continue;
                }
                $record = ['sys_language_uid' => $languageId, 'l10n_parent' => 0] + $default;
            }
            try {
                $urls[] = (string)$site->getRouter()->generateUri($record, ['_language' => $language]);
                // \Throwable, not \Exception. Route generation reaches deep into
                // core and fails with Errors as readily as with Exceptions — an
                // ArgumentCountError out of RequestContextFactory is what surfaced
                // this. The caller is a DataHandler hook, so anything escaping here
                // stops an editor saving a page.
            } catch (\Throwable $e) {
                $this->logUrlFailure($pageId, $e);
            }
        }

        return $urls;
    }

    /**
     * Live page records (no workspace versions) for the page and its
     * translations, by language, in the shape PageRouter::generateUri() takes
     * as a ready page record: a translation carries the default page's uid and
     * its own as _LOCALIZED_UID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function liveRecordsByLanguage(int $pageId): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
                ),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $records = [];
        foreach ($rows as $row) {
            $languageId = (int)$row['sys_language_uid'];
            if ($languageId === 0) {
                if ((int)$row['uid'] === $pageId) {
                    $records[0] = $row;
                }
                continue;
            }
            $row['_LOCALIZED_UID'] = (int)$row['uid'];
            $row['uid'] = $pageId;
            $records[$languageId] = $row;
        }

        return $records;
    }

    private function logUrlFailure(int $pageId, \Throwable $e): void
    {
        if ($this->enableDebugLogging) {
            $this->logger->warning('Caddy Cache: Could not generate URL', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }
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
     * dropped it.
     *
     * The key ends in "$". Souin's API compiles it into a regex and deletes
     * every stored key it matches anywhere, plus every key it is a prefix of
     * (Default storage, DeleteMany). Without the anchor "GET-host-/" deleted
     * every entry of the host and "GET-host-/blog" everything below /blog and
     * /blogger as well: one save of a hidden page, whose URL fell back to the
     * site base, emptied the edge. Measured on the caddy-proxy image,
     * 2026-11-08.
     *
     * The anchor also keeps "/blog?page=2" out, which invalidateUrls() purges
     * per host with "GET-host-/?.+=": "/?" makes the slash optional (the "?"
     * is not a literal here) and ".+=" needs a query, so only entries with a
     * query string go. One key for both is not possible: a group or an escape
     * needs "(", a backslash or "|", which TYPO3's Uri percent-encodes and Souin
     * does not decode, so such a key matches nothing.
     */
    private function souinKeyForUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = rawurldecode($parts['path'] ?? '/');

        return 'GET-' . self::souinLiteral(self::hostOf($url)) . '-' . self::souinLiteral($path === '' ? '/' : $path) . '$';
    }

    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * A string as it may stand in a Souin key regex: letters, digits and the
     * characters that are neither regex syntax nor percent-encoded by TYPO3's
     * Uri stay; everything else becomes ".", one per character. "." is the
     * only escape that survives the trip (see souinKeyForUrl()); it widens the
     * key by one character, never by a page.
     */
    private static function souinLiteral(string $value): string
    {
        return (string)preg_replace('#[^A-Za-z0-9/_~:@&=,;.-]#u', '.', $value);
    }

    /**
     * @param list<string> $tags
     */
    private function purgeViaApi(string $target, array $tags, string $ref): bool
    {
        if (!$this->enabled) {
            return false;
        }

        PurgeAudit::record('purge', [
            'key' => rawurldecode((string) preg_replace('#^.*?/souin/#', '', $target)),
            'ref' => $ref,
            'tags' => $tags,
        ]);

        try {
            $request = $this->requestFactory->createRequest('PURGE', $target);

            if ($this->apiKey !== '') {
                $request = $request->withHeader('X-API-Key', $this->apiKey);
            }

            if ($tags !== []) {
                $request = $request->withHeader(
                    $this->cacheTagsHeader,
                    implode(', ', $tags),
                );
            }

            $response = $this->httpClient->sendRequest($request);

            // Souin's PURGE answers 204 No Content and nothing else. A 200 or 2xx with a
            // body is something else answering (a proxy, an upstream page), not a purge.
            //
            // An answer is not an acknowledgement. A wrong API key (401), a
            // proxy in the way (405) or a Souin without the API (404) all come
            // back as responses, not exceptions -- and were logged nowhere
            // outside debug mode, so the page stayed stale with no trace.
            $status = $response->getStatusCode();
            if ($status !== 204) {
                $this->logger->error('Caddy Cache: PURGE failed', [
                    'target' => $target,
                    'ref' => $ref,
                    'statusCode' => $status,
                ]);

                return false;
            }

            if ($this->enableDebugLogging) {
                $this->logger->debug('Caddy Cache: PURGE sent', [
                    'target' => $target,
                    'ref' => $ref,
                    'tags' => $tags,
                    'statusCode' => $status,
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Caddy Cache: PURGE failed', [
                'target' => $target,
                'ref' => $ref,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return ($nullOnFailure is true ? list<string>|null : list<string>)
     */
    private function fetchFromAdminApi(string $path, bool $nullOnFailure = false): ?array
    {
        if (!$this->enabled) {
            return $nullOnFailure ? null : [];
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

                return is_array($decoded) ? $this->normalizeSouinKeys($decoded) : ($nullOnFailure ? null : []);
            }
        } catch (\Exception $e) {
            $this->logger->error('Caddy Cache: Admin API unavailable', [
                'url' => $apiUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $nullOnFailure ? null : [];
    }

    /**
     * @param array<array-key, mixed> $raw Decoded Souin admin API payload
     *
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
