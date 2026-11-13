<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

/**
 * One entry of {@see VerifiedPayloadMemo}: backend bytes whose HMAC was
 * valid, the decoded value if it may be shared between requests, and the
 * backend's validator for these bytes if it issued one.
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
     * @param string|null $validator   what a backend with conditional reads issued for the bytes it stores
     *                                 for this payload (opaque here); null: every read fetches the payload
     */
    public function __construct(
        public string $raw,
        public int $serializedLength,
        public bool $hasValue,
        public mixed $value,
        public int $bytes,
        public ?string $validator = null,
    ) {}

    public function withValidator(?string $validator): self
    {
        return new self(
            $this->raw,
            $this->serializedLength,
            $this->hasValue,
            $this->value,
            $this->bytes - \strlen($this->validator ?? '') + \strlen($validator ?? ''),
            $validator,
        );
    }
}
