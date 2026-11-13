<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

use Moselwal\KeyValueStore\Cache\Backend\ConditionalReadBackendInterface;
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
 * 1. The backend is asked, on every get(). Its answer is the validation:
 *    whatever another pod wrote, flushed or let expire is seen at once,
 *    because nothing below is used unless the backend holds, at this read,
 *    the bytes this worker verified. There are two ways to ask:
 *    - the full read: the backend returns the payload, and it is compared
 *      byte for byte with the remembered one;
 *    - the conditional read, for a remembered payload of at least
 *      KOH_WORKER_CACHE_MEMO_CONDITIONAL_MIN_BYTES (default 16 kB) on a
 *      backend that offers it (ConditionalReadBackendInterface of
 *      moselwal/keyvalue-store): the backend compares the length and the
 *      closing bytes of what it stores with the validator it issued for the
 *      remembered payload, and returns the payload only if they differ.
 *      Every payload remembered here ends in an HMAC-SHA3-384 (96 hex
 *      digits) over everything before it, so stored bytes with the same
 *      length and the same closing bytes are the remembered bytes. Fetching
 *      2.2 MiB to compare them costs 2.3 ms, asking costs 70 µs.
 * 2. If the worker memo holds an entry for this cache and identifier with
 *    that content, its HMAC is known to be valid (the check is a pure
 *    function of the bytes and the installation's encryption key), so it is
 *    skipped:
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
 * So the memo changes the cost of a read, never its result: for everything
 * written through a cache frontend, a get() answers what the core
 * VariableFrontend answers for the bytes the backend holds at that moment.
 * It needs no invalidation protocol, holds no request state, and works
 * across any number of replicas; flushes take effect on the next read on
 * every pod, as before.
 *
 * Integrity is the core's: no byte is unserialize()d that did not pass the
 * HMAC check in this worker. The conditional read differs from the full one
 * in a single case, which needs write access to the cache server: stored
 * bytes forged to the length and closing bytes of a verified payload. The
 * full read hands them to the core deserializer (step 3); the conditional
 * read answers with the verified value the worker remembers. Storing that
 * old, validly signed payload again achieves the same against either read.
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
        $validator = null;
        if ($memo->conditionalReadMinBytes > 0 && $this->backend instanceof ConditionalReadBackendInterface) {
            $candidate = $memo->candidate($key);
            if ($candidate !== null && $candidate->validator !== null) {
                $read = $this->backend->getUnlessUnchanged($entryIdentifier, $candidate->validator);
                if ($read === null) {
                    // The backend still stores the bytes this worker verified.
                    $memo->confirm($key);

                    return $this->decoded($candidate);
                }
            } else {
                $read = $this->backend->getWithValidator($entryIdentifier);
            }
            $raw = $read->payload;
            $validator = $read->validator;
        } else {
            $raw = $this->backend->get($entryIdentifier);
        }
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
            if ($known->validator !== $validator) {
                // The same payload, stored as other bytes (recompressed, for one).
                $memo->remember($key, $known->withValidator($validator));
            }

            return $this->decoded($known);
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
            bytes: \strlen($raw) + ($shareable ? $valueBytes : 0) + \strlen($validator ?? ''),
            validator: $validator,
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
     * The value of a payload the backend holds and this worker verified.
     */
    private function decoded(VerifiedPayload $known): mixed
    {
        if ($known->hasValue) {
            return $known->value;
        }

        // These exact bytes passed the HMAC check in this worker.
        return unserialize(substr($known->raw, 0, $known->serializedLength), ['allowed_classes' => true]);
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
