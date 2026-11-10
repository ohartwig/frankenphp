<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * A non-transient cache backend in an array: stores the bytes a frontend
 * hands it, as Valkey or the database would. Several frontends may share one
 * instance to play several pods on one shared backend.
 */
final class ArrayCacheBackend implements BackendInterface
{
    /** @var array<string, string> */
    public array $data = [];

    public int $reads = 0;

    public function setCache(FrontendInterface $cache): void {}

    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        $this->data[$entryIdentifier] = $data;
    }

    public function get(string $entryIdentifier): mixed
    {
        ++$this->reads;

        return $this->data[$entryIdentifier] ?? false;
    }

    public function has(string $entryIdentifier): bool
    {
        return isset($this->data[$entryIdentifier]);
    }

    public function remove(string $entryIdentifier): bool
    {
        $had = isset($this->data[$entryIdentifier]);
        unset($this->data[$entryIdentifier]);

        return $had;
    }

    public function flush(): void
    {
        $this->data = [];
    }

    public function collectGarbage(): void {}
}
