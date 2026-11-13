<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

/**
 * Worker-wide memory of cache payloads that already passed TYPO3's HMAC
 * check, for {@see WorkerMemoVariableFrontend}.
 *
 * An entry is the exact byte string a cache backend returned, the length of
 * its serialised part (the payload without the HMAC suffix) and, only for
 * payloads that contain no objects and no PHP references, the decoded value.
 * It answers only for what the shared backend holds at the moment of the
 * read: either the bytes the backend returned for this read are identical to
 * the remembered ones ({@see find()}), or the backend itself said, for this
 * read, that it still stores the bytes it issued the entry's validator for
 * ({@see candidate()}, {@see confirm()}).
 *
 * Bounded by bytes (the raw payload plus the measured size of a kept value),
 * least recently used first out. One instance per PHP process; in FrankenPHP
 * worker mode that is one worker, and it lives as long as the worker
 * (MAX_REQUESTS), outside the TYPO3 container and outside $GLOBALS, so neither
 * PersistentBootstrap nor RequestStateReset touch it. It never holds request
 * state: everything in it is a copy of a shared cache entry.
 *
 * @internal
 */
final class VerifiedPayloadMemo
{
    public const DEFAULT_MAX_BYTES = 33554432;

    /**
     * Payload size from which a conditional read replaces the full one.
     * Below, fetching the payload costs the same round trip (measured: no
     * gain up to 2 kB, 30 % at 30 kB, 97 % at 2.2 MB).
     */
    public const DEFAULT_CONDITIONAL_READ_MIN_BYTES = 16384;

    private static ?self $instance = null;

    /** @var array<string, VerifiedPayload> least recently used first */
    private array $entries = [];

    private int $bytes = 0;

    private int $hits = 0;

    private int $misses = 0;

    private int $evictions = 0;

    private int $confirmed = 0;

    /**
     * @param int $conditionalReadMinBytes payloads of at least this many bytes are validated with a
     *                                     conditional read where the backend offers one; 0: never
     */
    public function __construct(
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        public readonly int $conditionalReadMinBytes = self::DEFAULT_CONDITIONAL_READ_MIN_BYTES,
    ) {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be >= 1', 1794320001);
        }
        if ($conditionalReadMinBytes < 0) {
            throw new \InvalidArgumentException('conditionalReadMinBytes must be >= 0', 1794320002);
        }
    }

    /**
     * The process-wide memo when the switch is on and this process is a
     * FrankenPHP worker, null otherwise.
     */
    public static function active(): ?self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (!WorkerCacheMemo::isActive()) {
            return null;
        }

        return self::$instance = new self(WorkerCacheMemo::maxBytes(), WorkerCacheMemo::conditionalReadMinBytes());
    }

    /**
     * @internal for tests: use the given memo (null: decide again on the next call)
     */
    public static function setInstance(?self $memo): void
    {
        self::$instance = $memo;
    }

    /**
     * The remembered entry for $key that a conditional read may validate:
     * one with a validator and a payload of at least conditionalReadMinBytes.
     * Says nothing about what the backend holds; only {@see confirm()}, after
     * the backend answered "unchanged" for the entry's validator, makes it a
     * hit.
     */
    public function candidate(string $key): ?VerifiedPayload
    {
        $entry = $this->entries[$key] ?? null;
        if (
            $entry === null
            || $entry->validator === null
            || $this->conditionalReadMinBytes < 1
            || \strlen($entry->raw) < $this->conditionalReadMinBytes
        ) {
            return null;
        }

        return $entry;
    }

    /**
     * Counts a hit the backend confirmed without sending the payload.
     */
    public function confirm(string $key): void
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            return;
        }
        // Most recently used goes to the end.
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;
        ++$this->hits;
        ++$this->confirmed;
    }

    /**
     * The remembered entry for $key if its bytes are exactly $raw. An entry
     * under $key with other bytes is dropped: the backend holds something
     * else now.
     */
    public function find(string $key, string $raw): ?VerifiedPayload
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null) {
            ++$this->misses;

            return null;
        }
        unset($this->entries[$key]);
        if ($entry->raw !== $raw) {
            $this->bytes -= $entry->bytes;
            ++$this->misses;

            return null;
        }
        // Most recently used goes to the end.
        $this->entries[$key] = $entry;
        ++$this->hits;

        return $entry;
    }

    /**
     * Remembers a verified payload. Entries larger than half the budget are
     * not kept, so one huge entry cannot empty the memo.
     */
    public function remember(string $key, VerifiedPayload $entry): void
    {
        $this->forget($key);
        if ($entry->bytes > intdiv($this->maxBytes, 2)) {
            return;
        }
        while ($this->entries !== [] && $this->bytes + $entry->bytes > $this->maxBytes) {
            $oldestKey = array_key_first($this->entries);
            $this->bytes -= $this->entries[$oldestKey]->bytes;
            unset($this->entries[$oldestKey]);
            ++$this->evictions;
        }
        $this->entries[$key] = $entry;
        $this->bytes += $entry->bytes;
    }

    public function forget(string $key): void
    {
        if (isset($this->entries[$key])) {
            $this->bytes -= $this->entries[$key]->bytes;
            unset($this->entries[$key]);
        }
    }

    /**
     * Drops every entry whose key starts with $prefix (one cache).
     */
    public function forgetPrefix(string $prefix): void
    {
        foreach (array_keys($this->entries) as $key) {
            if (str_starts_with($key, $prefix)) {
                $this->forget($key);
            }
        }
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /**
     * @return array{hits: int, misses: int, evictions: int, entries: int, bytes: int, confirmed: int} confirmed:
     *                                                                                               the hits a conditional read answered
     */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'evictions' => $this->evictions,
            'entries' => \count($this->entries),
            'bytes' => $this->bytes,
            'confirmed' => $this->confirmed,
        ];
    }
}
