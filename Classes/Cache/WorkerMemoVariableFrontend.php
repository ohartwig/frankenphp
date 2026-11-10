<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

use TYPO3\CMS\Core\Cache\Backend\TransientBackendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Exception\Crypto\InvalidHashStringException;

/**
 * VariableFrontend that remembers, per FrankenPHP worker, which backend
 * payloads it has already authenticated and decoded (KOH_WORKER_CACHE_MEMO=1).
 *
 * Reading a cache entry costs three steps in TYPO3 14: the backend read, the
 * HMAC check (SHA3-384 over the whole payload) and unserialize(). For the
 * large read-mostly entries (a 2.2 MiB menu tree in `hash`: 1.8 ms read,
 * 6.1 ms HMAC, 2.6 ms unserialize) the last two dominate.
 *
 * How a get() runs here:
 *
 * 1. The backend is read, as always. That read is the validation: whatever
 *    another pod wrote, flushed or let expire is seen at once, because
 *    nothing below is used unless the bytes from this read match.
 * 2. If the worker memo holds an entry for this cache and identifier with
 *    byte-identical content, its HMAC is known to be valid (the check is a
 *    pure function of the bytes and the installation's encryption key), so
 *    it is skipped:
 *    - payloads without objects and without PHP references: the decoded
 *      value is returned as is. It consists of scalars and arrays only, and
 *      PHP arrays are values (copy on write), so no request can change what
 *      another request receives;
 *    - all other payloads are unserialize()d again, which creates fresh
 *      objects for every request. Objects are never shared between requests
 *      (TYPO3's Page objects, for one, are mutable through ArrayAccess).
 * 3. Otherwise the payload goes the core way: HMAC check, unserialize(). If
 *    the check passes, the payload is remembered. If it fails, the core
 *    deserializer handles the payload exactly as the VariableFrontend does
 *    (including its fallback for unauthenticated, class-free payloads), and
 *    nothing is remembered.
 *
 * So the memo changes the cost of a read, never its result. It needs no
 * invalidation protocol, holds no request state, and works across any number
 * of replicas; flushes take effect on the next read on every pod, as before.
 *
 * Outside a FrankenPHP worker, or with the switch off, every method is the
 * core VariableFrontend.
 */
class WorkerMemoVariableFrontend extends VariableFrontend
{
    /** As AuthenticatedMessageDeserializer::HASH_ALGO; a different one only means no memo (step 3). */
    private const HASH_ALGO = HashAlgo::SHA3_384;

    /** An object, a custom-serialised object or a PHP reference anywhere in a serialised payload. */
    private const NOT_SHAREABLE = '/[OCRr]:\d+[:;]/';

    public function get(string $entryIdentifier): mixed
    {
        $memo = VerifiedPayloadMemo::active();
        if ($memo === null || $this->backend instanceof TransientBackendInterface) {
            return parent::get($entryIdentifier);
        }
        if (!$this->isValidEntryIdentifier($entryIdentifier)) {
            throw new \InvalidArgumentException(
                '"' . $entryIdentifier . '" is not a valid cache entry identifier.',
                1233058294
            );
        }

        $key = $this->identifier . "\0" . $entryIdentifier;
        $raw = $this->backend->get($entryIdentifier);
        if (!is_string($raw)) {
            $memo->forget($key);
            if ($raw === false) {
                return false;
            }

            // Not a string from a non-transient backend: whatever the core does with it.
            return parent::get($entryIdentifier);
        }

        $known = $memo->find($key, $raw);
        if ($known !== null) {
            if ($known->hasValue) {
                return $known->value;
            }

            // These exact bytes passed the HMAC check in this worker.
            return unserialize(substr($raw, 0, $known->serializedLength), ['allowed_classes' => true]);
        }

        if ($raw === '') {
            return $this->decodeLikeCore($raw);
        }
        try {
            $serialized = (new HashService())->validateAndStripHmac($raw, VariableFrontend::class, self::HASH_ALGO);
        } catch (InvalidHashStringException) {
            return $this->decodeLikeCore($raw);
        }

        $memoryBefore = memory_get_usage();
        // Integrity checked via HMAC right above, as in AuthenticatedMessageDeserializer.
        $value = unserialize($serialized, ['allowed_classes' => true]);
        $valueBytes = max(0, memory_get_usage() - $memoryBefore);
        $shareable = preg_match(self::NOT_SHAREABLE, $serialized) === 0;

        $memo->remember($key, new VerifiedPayload(
            raw: $raw,
            serializedLength: \strlen($serialized),
            hasValue: $shareable,
            value: $shareable ? $value : null,
            bytes: \strlen($raw) + ($shareable ? $valueBytes : 0),
        ));

        return $value;
    }

    public function flush(): void
    {
        parent::flush();
        VerifiedPayloadMemo::active()?->forgetPrefix($this->identifier . "\0");
    }

    public function remove(string $entryIdentifier): bool
    {
        $removed = parent::remove($entryIdentifier);
        VerifiedPayloadMemo::active()?->forget($this->identifier . "\0" . $entryIdentifier);

        return $removed;
    }

    /**
     * The part of VariableFrontend::get() after the backend read, for a
     * payload whose HMAC did not validate with HASH_ALGO.
     */
    private function decodeLikeCore(string $raw): mixed
    {
        try {
            $deserializer = new \TYPO3\CMS\Core\Serializer\AuthenticatedMessageDeserializer(
                new HashService(),
                new \TYPO3\CMS\Core\Serializer\DeserializationService()
            );

            return $deserializer->deserialize($raw, VariableFrontend::class);
        } catch (\TYPO3\CMS\Core\Serializer\Exception\DeserializerException) {
            return false;
        }
    }
}
