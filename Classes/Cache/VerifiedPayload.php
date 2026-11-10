<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

/**
 * One entry of {@see VerifiedPayloadMemo}: backend bytes whose HMAC was
 * valid, and the decoded value if it may be shared between requests.
 *
 * @internal
 */
final readonly class VerifiedPayload
{
    /**
     * @param string $raw              the bytes exactly as the backend returned them
     * @param int    $serializedLength length of the serialised part (raw without the HMAC suffix)
     * @param bool   $hasValue         whether $value holds the decoded payload
     * @param mixed  $value            the decoded payload (only scalars and arrays, see WorkerMemoVariableFrontend)
     * @param int    $bytes            what the entry counts against the memo's budget
     */
    public function __construct(
        public string $raw,
        public int $serializedLength,
        public bool $hasValue,
        public mixed $value,
        public int $bytes,
    ) {}
}
