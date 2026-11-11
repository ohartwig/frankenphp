<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

use Koh\FrankenPHP\Runtime\WorkerSwitch;

/**
 * The TYPO3 cache tags of a page as edge cache tags (Souin's Surrogate-Key),
 * behind KOH_EDGE_PURGE_BY_TAG.
 *
 * Purging a page by its URL reaches one key per language: the URL the router
 * builds for the page. Everything else the page renders under -- pagination
 * and category routes, a feed of another page type, plugin detail URLs, query
 * variants -- has a key of its own that no URL purge names, and stayed at the
 * edge until it expired. A tag reaches all of them: every response of a page
 * carries the page's TYPO3 cache tags, and a purge by tag deletes every
 * response that carries it, whatever its URL.
 *
 * With the switch on, SurrogateKeyMiddleware adds the cache tags TYPO3
 * collected for the response (pageId_<n> of the page, of the page its content
 * comes from and of its translation, plus the record and plugin tags the
 * rendering added) and CacheInvalidationListener purges by the tags the core
 * flushed. Off, nothing changes: the site identifier only, URL purges only.
 *
 * Souin (v1.7.10) files a response under each tag of its header and purges a
 * tag by an exact lookup, so pageId_1 does not take pageId_12 with it (checked
 * against the caddy-proxy image, see the README). It splits the header on
 * ", "; a TYPO3 cache tag has neither a comma nor a space.
 */
final class EdgeCacheTags
{
    public const ENV = 'KOH_EDGE_PURGE_BY_TAG';

    /**
     * Longest Surrogate-Key value a response carries, and longest one a
     * single PURGE sends. TYPO3 caps its own X-TYPO3-Cache-Tags header at the
     * same 8000 bytes, below the 8 KB per-header default of nginx and Apache;
     * caddy-proxy accepts 16 KB of request headers in total, the PURGE
     * included.
     */
    public const MAX_HEADER_LENGTH = 8000;

    /** FrontendInterface::PATTERN_TAG: what TYPO3 accepts as a cache tag. */
    private const TAG_PATTERN = '/^[a-zA-Z0-9_%\-&]{1,250}$/';

    private const SEPARATOR = ', ';

    public static function enabled(): bool
    {
        return WorkerSwitch::isOn(self::ENV);
    }

    public static function pageTag(int $pageId): string
    {
        return 'pageId_' . $pageId;
    }

    /**
     * Tags in the order given, each once, without what Souin cannot file --
     * anything that is no TYPO3 cache tag (a comma or a space would split
     * it) -- and without what no response carries, see isEdgeTag(). The
     * header and the purge both go through here, so a tag the purge leaves
     * out is one no response was filed under.
     *
     * @param iterable<mixed> $tags
     *
     * @return list<string>
     */
    public static function usable(iterable $tags): array
    {
        $usable = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && preg_match(self::TAG_PATTERN, $tag) === 1 && self::isEdgeTag($tag)) {
                $usable[$tag] = true;
            }
        }

        return array_map('strval', array_keys($usable));
    }

    /**
     * Whether a response can carry this tag in its Surrogate-Key.
     *
     * The vocabulary of the header is open: the site identifier, pageId_<n>,
     * and whatever TYPO3 collected while rendering -- menuId_<n>,
     * "<table>_<uid>" of every record the content object renderer or
     * Extbase read, "<table>" and "<table>_pid_<n>" of an Extbase query,
     * any addPageCacheTags or extension tag. So this names what is out
     * rather than what is in: the tags of a table whose TCA says
     * ctrl.adminOnly. TYPO3 marks backend configuration that way (backend
     * users and groups, file mounts, system news, dashboards, TypoScript
     * records, scheduler tasks, workspaces); the frontend renders none of
     * them as content. DataHandler flushes "<table>" and "<table>_<uid>" for
     * every record it saves, so the scheduler, saving its task records after
     * each run, had the edge purge tx_scheduler_task and
     * tx_scheduler_task_<uid> -- tags no response was ever filed under.
     *
     * Anything that names no TCA table (pageId_, menuId_, the site
     * identifier, a custom tag) is kept, and so is everything when TCA is
     * not loaded.
     *
     * @param array<string, mixed>|null $tca TCA to read, $GLOBALS['TCA'] by default
     */
    public static function isEdgeTag(string $tag, ?array $tca = null): bool
    {
        if (str_starts_with($tag, 'pageId_')) {
            return true;
        }
        $tca ??= is_array($GLOBALS['TCA'] ?? null) ? $GLOBALS['TCA'] : [];
        $table = null;
        if (isset($tca[$tag])) {
            $table = $tag;
        } elseif (preg_match('/^(.+?)(?:_pid)?_\d+$/', $tag, $matches) === 1 && isset($tca[$matches[1]])) {
            $table = $matches[1];
        } elseif (preg_match('/^(.+)_\d+$/', $tag, $matches) === 1 && isset($tca[$matches[1]])) {
            $table = $matches[1];
        }
        if ($table === null) {
            return true;
        }
        $ctrl = is_array($tca[$table]) ? ($tca[$table]['ctrl'] ?? null) : null;

        return !is_array($ctrl) || !(bool)($ctrl['adminOnly'] ?? false);
    }

    /**
     * The tags a response carries, page tags first: the page's own, then the
     * other pageId_<n>, then everything else in the order TYPO3 collected it.
     * What does not fit into MAX_HEADER_LENGTH after $reserved bytes is left
     * out, from the end; the page's own tags always fit.
     *
     * @param list<string> $tags
     *
     * @return array{list<string>, int} the tags kept and how many were left out
     */
    public static function forResponse(array $tags, ?int $pageId, int $reserved = 0): array
    {
        $own = [];
        $pages = [];
        $other = [];
        foreach (self::usable($tags) as $tag) {
            if ($pageId !== null && $tag === self::pageTag($pageId)) {
                $own[] = $tag;
            } elseif (str_starts_with($tag, 'pageId_')) {
                $pages[] = $tag;
            } else {
                $other[] = $tag;
            }
        }

        $kept = [];
        $length = $reserved;
        $dropped = 0;
        foreach ([...$own, ...$pages, ...$other] as $tag) {
            $added = strlen($tag) + ($kept === [] && $reserved === 0 ? 0 : strlen(self::SEPARATOR));
            if ($kept !== [] && $length + $added > self::MAX_HEADER_LENGTH) {
                ++$dropped;
                continue;
            }
            $kept[] = $tag;
            $length += $added;
        }

        return [$kept, $dropped];
    }

    /**
     * Tags split into header values of at most MAX_HEADER_LENGTH bytes, one
     * per PURGE request.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public static function headerValues(array $tags): array
    {
        $values = [];
        $current = '';
        foreach (self::usable($tags) as $tag) {
            $candidate = $current === '' ? $tag : $current . self::SEPARATOR . $tag;
            if ($current !== '' && strlen($candidate) > self::MAX_HEADER_LENGTH) {
                $values[] = $current;
                $candidate = $tag;
            }
            $current = $candidate;
        }
        if ($current !== '') {
            $values[] = $current;
        }

        return $values;
    }

    public static function join(string ...$parts): string
    {
        return implode(self::SEPARATOR, array_values(array_filter($parts, static fn(string $part): bool => $part !== '')));
    }
}
