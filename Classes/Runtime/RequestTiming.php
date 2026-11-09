<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * One JSON line per frontend request the worker served: how long the TYPO3
 * bootstrap took, how long the application, and whether TYPO3 rendered the
 * page or answered from its page cache.
 *
 * The edge only sees the total of a request it did not have cached. Whether
 * that time went into booting TYPO3 - which the worker does for every request -
 * or into rendering a page TYPO3 had not cached either, decides which
 * optimisation pays off, and only the worker can tell them apart.
 *
 *   {"koh_timing":"frontend","host":"example.org","path":"/blog","status":200,
 *    "page":"rendered","boot_ms":31.2,"app_ms":214.9,"total_ms":246.4,
 *    "worker_request":17,"mem_peak_mb":42.0}
 *
 * `page` is `rendered` when TYPO3 generated the page content in this request
 * (AfterCacheableContentIsGeneratedEvent fired) and `not-rendered` otherwise:
 * a page from TYPO3's page cache, or a response that is no page (a redirect,
 * a 404, a sitemap). Read it together with `status`.
 *
 * Only the path is logged, never the query string. Written with error_log(),
 * like PurgeAudit, so it lands in the container log without any log
 * configuration. On by default; KOH_REQUEST_TIMING=0 switches it off.
 */
final class RequestTiming
{
    public const ENV = 'KOH_REQUEST_TIMING';

    private static bool $rendered = false;

    /** @var (callable(string): mixed)|null */
    private static $writer;

    public static function isEnabled(): bool
    {
        $value = getenv(self::ENV);

        return !is_string($value) || !in_array(strtolower(trim($value)), ['0', 'false', 'off', 'no'], true);
    }

    /**
     * Starts a request: nothing rendered yet, and the memory peak of the
     * previous request in this worker no longer counts.
     */
    public static function begin(): void
    {
        self::$rendered = false;
        memory_reset_peak_usage();
    }

    public static function markRendered(): void
    {
        self::$rendered = true;
    }

    public static function wasRendered(): bool
    {
        return self::$rendered;
    }

    /**
     * Times are hrtime(true) values: nanoseconds, an int on 64-bit systems.
     *
     * @param int|float      $start          when the request started
     * @param int|float|null $booted         when TYPO3 had booted; null if it never did
     * @param int|float      $end            when the response was complete
     * @param int            $workerRequest  the how-manieth request of this worker process
     */
    public static function record(int|float $start, int|float|null $booted, int|float $end, int $status, int $workerRequest): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $line = json_encode(
            [
                'koh_timing' => 'frontend',
                'host' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
                'path' => is_string($path) ? $path : '',
                'status' => $status,
                'page' => self::$rendered ? 'rendered' : 'not-rendered',
                'boot_ms' => $booted === null ? null : self::milliseconds($booted - $start),
                'app_ms' => $booted === null ? null : self::milliseconds($end - $booted),
                'total_ms' => self::milliseconds($end - $start),
                'worker_request' => $workerRequest,
                'mem_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            ],
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION,
        );
        $writer = self::$writer ?? static fn(string $message): bool => error_log($message);
        $writer((string) $line);
    }

    /**
     * @internal for tests: where the lines go instead of error_log(); null restores it
     *
     * @param (callable(string): mixed)|null $writer
     */
    public static function writeTo(?callable $writer): void
    {
        self::$writer = $writer;
    }

    private static function milliseconds(int|float $nanoseconds): float
    {
        return round($nanoseconds / 1e6, 1);
    }
}
