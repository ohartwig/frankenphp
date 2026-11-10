<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Checks, once TYPO3 has produced a response, that what it used belongs to
 * the request the worker is serving. Used with the persistent bootstrap,
 * where state from an earlier request is the failure to expect.
 *
 * Compared with the request as FrankenPHP handed it over ($_SERVER):
 *
 * - `indpenv_host`: GeneralUtility::getIndpEnv('HTTP_HOST'), which memoises;
 * - `indpenv:<NAME>`: every other value getIndpEnv() memoised during the
 *   request, against the same value computed afresh (see staleIndpEnv());
 * - `global_request_host`: $GLOBALS['TYPO3_REQUEST'];
 * - `language_host`, `language_path`: the base of the site language TYPO3
 *   resolved, which must have the request's host (when it names one) and,
 *   for a language other than the default, be a prefix of the request's
 *   path. The default language is also what TYPO3 resolves for paths
 *   outside every language base - a static route such as /robots.txt, or
 *   / when the default language lives under /de/ - so its path is no
 *   evidence of a leak.
 *
 * checkHead() adds the one check on the page itself: its og:url must name
 * the request's host and page.
 *
 * Hosts compare without port and case, paths decoded and without case. A
 * missing global request is no finding: a request can end before TYPO3 sets
 * it. The checks are deliberately few and exact: a finding costs the request
 * a 503 and the worker its life, so nothing here may fire on a healthy
 * request. The path of the global request is not compared, because
 * middlewares may legitimately hand on a request with another path.
 *
 * @internal
 */
final class LeakGuard
{
    /**
     * @param array<string, mixed> $server        the request's $_SERVER
     * @param string               $indpEnvHost   GeneralUtility::getIndpEnv('HTTP_HOST')
     * @param mixed                $globalRequest $GLOBALS['TYPO3_REQUEST'] ?? null
     *
     * @return array{check: string, expected: string, actual: string}|null the first mismatch, null if none
     */
    public static function check(array $server, string $indpEnvHost, mixed $globalRequest): ?array
    {
        $host = self::host((string) ($server['HTTP_HOST'] ?? ''));
        $path = self::path((string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

        if ($host !== '' && self::host($indpEnvHost) !== $host) {
            return self::finding('indpenv_host', (string) ($server['HTTP_HOST'] ?? ''), $indpEnvHost);
        }
        if (!$globalRequest instanceof ServerRequestInterface) {
            return null;
        }
        $uri = $globalRequest->getUri();
        if ($host !== '' && self::host($uri->getHost()) !== $host) {
            return self::finding('global_request_host', (string) ($server['HTTP_HOST'] ?? ''), $uri->getHost());
        }

        $language = $globalRequest->getAttribute('language');
        if (!$language instanceof SiteLanguage) {
            return null;
        }
        $base = $language->getBase();
        if ($base->getHost() !== '' && $host !== '' && self::host($base->getHost()) !== $host) {
            return self::finding('language_host', $host, $base->getHost());
        }
        $basePath = rtrim(self::path($base->getPath()), '/');
        if ($language->getLanguageId() > 0 && $basePath !== '' && $path !== $basePath && !str_starts_with($path, $basePath . '/')) {
            return self::finding('language_path', $path, (string) $base);
        }

        return null;
    }

    /**
     * Compares every value getIndpEnv() has memoised with the value it
     * computes from the current request, and leaves the memo empty.
     *
     * getIndpEnv() derives its values from $_SERVER and TYPO3_CONF_VARS only,
     * so a memoised value that differs from a fresh one belongs to another
     * request. That also covers what the host check cannot see: a request URI
     * or script name memoised for another page on the same host.
     *
     * A value that cannot be computed again (getIndpEnv() throws) is skipped:
     * the guard must never turn a healthy request into an error.
     *
     * @return array{check: string, expected: string, actual: string}|null the first stale value, null if none
     */
    public static function staleIndpEnv(): ?array
    {
        $memoised = \Closure::bind(static fn(): array => static::$indpEnvCache, null, GeneralUtility::class)();
        GeneralUtility::flushInternalRuntimeCaches();
        foreach ($memoised as $name => $value) {
            try {
                $fresh = self::indpEnv($name);
            } catch (\Throwable) {
                // Not computable here: no evidence either way.
                continue;
            }
            if ($fresh !== $value) {
                GeneralUtility::flushInternalRuntimeCaches();

                return self::finding('indpenv:' . $name, self::export($fresh), self::export($value));
            }
        }
        GeneralUtility::flushInternalRuntimeCaches();

        return null;
    }

    /**
     * Compares the page's og:url with the request.
     *
     * Meta tags are collected in MetaTagManagerRegistry, a registry that
     * outlives the request with a persistent bootstrap; a manager carried
     * over from an earlier request makes the page claim that request's URL,
     * description and title. og:url is the one of them that can be checked
     * against the request exactly: TYPO3 renders it as a link to the page
     * itself.
     *
     * - `og_url_host`: an absolute og:url on another host;
     * - `og_url_path`: an og:url for another page. The request may go
     *   further below the og:url (a route enhancer: /blog/page-2 for the
     *   page /blog), except below the language's root, which every path of
     *   that language is below.
     *
     * Only an HTML response with status 200 is looked at, only its <head>,
     * and only the first og:url in it.
     *
     * @param array<string, mixed> $server        the request's $_SERVER
     * @param string               $content       the response body
     * @param mixed                $globalRequest $GLOBALS['TYPO3_REQUEST'] ?? null
     *
     * @return array{check: string, expected: string, actual: string}|null
     */
    public static function checkHead(array $server, string $content, mixed $globalRequest): ?array
    {
        $headEnd = stripos($content, '</head>');
        $head = $headEnd === false ? substr($content, 0, 65536) : substr($content, 0, $headEnd);
        if (preg_match('#<meta\b[^>]*\bproperty=["\']og:url["\'][^>]*>#i', $head, $tag) !== 1
            || preg_match('#\bcontent=(["\'])(.*?)\1#is', $tag[0], $attribute) !== 1) {
            return null;
        }
        $url = html_entity_decode($attribute[2], ENT_QUOTES | ENT_HTML5);
        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }
        $host = self::host((string) ($server['HTTP_HOST'] ?? ''));
        if (isset($parts['host']) && $host !== '' && self::host($parts['host']) !== $host) {
            return self::finding('og_url_host', $host, $url);
        }
        $path = rtrim(self::path((string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH)), '/');
        $ogPath = rtrim(self::path($parts['path'] ?? '/'), '/');
        if ($ogPath === $path) {
            return null;
        }
        $languageRoot = '';
        if ($globalRequest instanceof ServerRequestInterface) {
            $language = $globalRequest->getAttribute('language');
            if ($language instanceof SiteLanguage) {
                $languageRoot = rtrim(self::path($language->getBase()->getPath()), '/');
            }
        }
        if ($ogPath !== '' && $ogPath !== $languageRoot && str_starts_with($path, $ogPath . '/')) {
            return null;
        }

        return self::finding('og_url_path', (string) ($server['REQUEST_URI'] ?? '/'), $url);
    }

    /**
     * Whether the response about to be sent is an HTML page with status 200,
     * the only kind checkHead() looks at.
     *
     * @param list<string> $headers headers_list()
     */
    public static function isHtmlPage(int|bool $status, array $headers): bool
    {
        if ($status !== 200) {
            return false;
        }
        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                return stripos($header, 'text/html') !== false;
            }
        }

        return false;
    }

    /**
     * The JSON line for a finding.
     *
     * @param array{check: string, expected: string, actual: string} $finding
     */
    public static function logLine(array $finding, int $workerRequest): string
    {
        return (string) json_encode(
            ['koh_worker_leak' => 'frontend'] + $finding + ['worker_request' => $workerRequest],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    /** Lower case, without port; brackets of an IPv6 literal kept. */
    private static function host(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');

            return $end === false ? $host : substr($host, 0, $end + 1);
        }
        $colon = strrpos($host, ':');

        return $colon === false ? $host : substr($host, 0, $colon);
    }

    /**
     * getIndpEnv() without its deprecation notice (TYPO3 14.3): the guard
     * calls it on every request, and the memo it checks exists only as long
     * as getIndpEnv() does. TYPO3 13 ignores the second argument.
     */
    public static function indpEnv(string $name): mixed
    {
        return GeneralUtility::getIndpEnv($name, true);
    }

    private static function export(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }

    private static function path(string $path): string
    {
        $path = strtolower(rawurldecode($path));

        return $path === '' ? '/' : $path;
    }

    /**
     * @return array{check: string, expected: string, actual: string}
     */
    private static function finding(string $check, string $expected, string $actual): array
    {
        return ['check' => $check, 'expected' => $expected, 'actual' => $actual];
    }
}
