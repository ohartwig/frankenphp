<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

/**
 * What a tag purge actually did, as far as the Souin admin API lets us tell.
 *
 * Souin answers every PURGE with 204 whether it matched an entry or not, so a
 * status code alone proves nothing. A tag purge is therefore only "purged" for
 * the tags Souin lists as known, and every other tag is reported as unmatched:
 * nothing emitted a Surrogate-Key for it, so there was nothing to remove.
 */
final class PurgeResult
{
    /**
     * @param list<string> $purged    Tags Souin knew and purged (PURGE answered 204)
     * @param list<string> $unmatched Tags no cached response was stored under
     */
    private function __construct(
        public readonly array $purged,
        public readonly array $unmatched,
        public readonly bool $failed,
        public readonly bool $disabled,
    ) {}

    /**
     * @param list<string> $purged
     * @param list<string> $unmatched
     */
    public static function done(array $purged, array $unmatched): self
    {
        return new self($purged, $unmatched, false, false);
    }

    /** @param list<string> $tags */
    public static function failed(array $tags): self
    {
        return new self([], $tags, true, false);
    }

    public static function disabled(): self
    {
        return new self([], [], false, true);
    }

    public function isComplete(): bool
    {
        return !$this->failed && !$this->disabled && $this->purged !== [] && $this->unmatched === [];
    }
}
