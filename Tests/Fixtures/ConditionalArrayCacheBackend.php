<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Fixtures;

use Moselwal\KeyValueStore\Cache\Backend\ConditionalRead;
use Moselwal\KeyValueStore\Cache\Backend\ConditionalReadBackendInterface;
use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * A shared, non-transient backend with conditional reads, as
 * moselwal/keyvalue-store's KeyValueBackend offers them: the validator is the
 * length and the closing bytes of what is stored, and with $compression what
 * is stored is the zlib stream, not the payload.
 *
 * Counts how often it handed out a payload (payloadsSent) and how often it
 * only confirmed one (confirmations), which is what a reader pays for.
 */
final class ConditionalArrayCacheBackend implements BackendInterface, ConditionalReadBackendInterface
{
    private const TAIL_BYTES = 96;

    /** @var array<string, string> the stored bytes */
    public array $stored = [];

    public int $payloadsSent = 0;

    public int $confirmations = 0;

    public function __construct(private readonly bool $compression = false) {}

    public function setCache(FrontendInterface $cache): void {}

    public function set(string $entryIdentifier, string $data, array $tags = [], ?int $lifetime = null): void
    {
        $this->stored[$entryIdentifier] = $this->compression ? (string) gzcompress($data) : $data;
    }

    public function get(string $entryIdentifier): mixed
    {
        return $this->getWithValidator($entryIdentifier)->payload;
    }

    public function getWithValidator(string $entryIdentifier): ConditionalRead
    {
        $stored = $this->stored[$entryIdentifier] ?? null;
        if ($stored === null) {
            return new ConditionalRead(false, null);
        }
        ++$this->payloadsSent;

        return new ConditionalRead(
            $this->compression ? gzuncompress($stored) : $stored,
            self::validator($stored),
        );
    }

    public function getUnlessUnchanged(string $entryIdentifier, string $validator): ?ConditionalRead
    {
        $stored = $this->stored[$entryIdentifier] ?? null;
        if ($stored !== null && self::validator($stored) === $validator) {
            ++$this->confirmations;

            return null;
        }

        return $this->getWithValidator($entryIdentifier);
    }

    public function has(string $entryIdentifier): bool
    {
        return isset($this->stored[$entryIdentifier]);
    }

    public function remove(string $entryIdentifier): bool
    {
        $had = isset($this->stored[$entryIdentifier]);
        unset($this->stored[$entryIdentifier]);

        return $had;
    }

    public function flush(): void
    {
        $this->stored = [];
    }

    public function collectGarbage(): void {}

    private static function validator(string $stored): string
    {
        return \strlen($stored) . ':' . substr($stored, -self::TAIL_BYTES);
    }
}
