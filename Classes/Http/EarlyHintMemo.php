<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Http;

use Koh\FrankenPHP\Runtime\WorkerSwitch;

/**
 * Worker-wide memory of the preload hints of each page, so that a 103 Early
 * Hints response can go out before TYPO3 renders instead of after.
 *
 * Which stylesheets, fonts and images a page needs is only known once it has
 * been rendered. A 103 sent at that point arrives together with the 200 and
 * gives the browser no head start. This memo keeps the hints of the last
 * render of each page (host + path, without query) for the life of a
 * FrankenPHP worker, and the hints every page of a host had in common, so
 * that the next request for the page, or the first request for another page
 * of the same host, can announce them before the render begins.
 *
 * A stale entry costs one unused preload until the next render of that page
 * replaces it; a deployment starts new workers and so begins empty.
 *
 * On by default in a FrankenPHP worker; KOH_EARLY_HINTS_MEMO=0 switches it
 * off, which leaves only the Link header on the final response. Bounded by
 * entry count; when full, the oldest page is forgotten.
 *
 * @internal
 */
final class EarlyHintMemo
{
    public const ENV = 'KOH_EARLY_HINTS_MEMO';

    public const ENV_MAX_ENTRIES = 'KOH_EARLY_HINTS_MEMO_MAX_ENTRIES';

    public const DEFAULT_MAX_ENTRIES = 1024;

    private static ?self $instance = null;

    private static bool $decided = false;

    /** @var array<string, list<string>> page key => link values */
    private array $pages = [];

    /** @var array<string, list<string>> host => link values every recorded page of the host had */
    private array $common = [];

    public function __construct(private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES) {}

    /**
     * The process-wide memo in a FrankenPHP worker, null elsewhere or when
     * switched off.
     */
    public static function active(): ?self
    {
        if (self::$decided) {
            return self::$instance;
        }
        self::$decided = true;
        $switch = WorkerSwitch::value(self::ENV);
        $enabled = $switch === null || (filter_var($switch, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
        if (!$enabled || !WorkerSwitch::isFrankenPhpWorker()) {
            return self::$instance = null;
        }

        return self::$instance = new self(
            WorkerSwitch::positiveInt(self::ENV_MAX_ENTRIES, self::DEFAULT_MAX_ENTRIES),
        );
    }

    /**
     * @internal for tests: use the given memo (null and $decided false: decide again on the next call)
     */
    public static function setInstance(?self $memo, bool $decided = true): void
    {
        self::$instance = $memo;
        self::$decided = $decided;
    }

    /**
     * The hints to announce before rendering: those of the page itself, else
     * those all pages of the host shared so far.
     *
     * @return list<string>
     */
    public function lookup(string $host, string $path): array
    {
        $host = strtolower($host);

        return $this->pages[$host . $path] ?? $this->common[$host] ?? [];
    }

    /**
     * @param list<string> $links
     */
    public function record(string $host, string $path, array $links): void
    {
        $host = strtolower($host);
        $key = $host . $path;

        unset($this->pages[$key]);
        if (count($this->pages) >= $this->maxEntries) {
            unset($this->pages[array_key_first($this->pages)]);
        }
        $this->pages[$key] = $links;

        $this->common[$host] = isset($this->common[$host])
            ? array_values(array_intersect($this->common[$host], $links))
            : $links;
    }
}
