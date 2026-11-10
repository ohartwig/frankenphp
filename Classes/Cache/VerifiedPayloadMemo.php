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
 * It is consulted with the bytes the backend returned for the current read
 * and answers only when those bytes are identical to the remembered ones, so
 * it can never serve anything the shared backend does not hold at that very
 * moment.
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

    private static ?self $instance = null;

    /** @var array<string, VerifiedPayload> least recently used first */
    private array $entries = [];

    private int $bytes = 0;

    private int $hits = 0;

    private int $misses = 0;

    private int $evictions = 0;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('maxBytes must be >= 1', 1794320001);
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

        return self::$instance = new self(WorkerCacheMemo::maxBytes());
    }

    /**
     * @internal for tests: use the given memo (null: decide again on the next call)
     */
    public static function setInstance(?self $memo): void
    {
        self::$instance = $memo;
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
     * @return array{hits: int, misses: int, evictions: int, entries: int, bytes: int}
     */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'evictions' => $this->evictions,
            'entries' => \count($this->entries),
            'bytes' => $this->bytes,
        ];
    }
}
