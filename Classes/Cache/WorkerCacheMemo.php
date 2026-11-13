<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Cache;

use Koh\FrankenPHP\Runtime\WorkerSwitch;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Serializer\AuthenticatedMessageDeserializer;

/**
 * Switch and wiring of the worker cache memo (KOH_WORKER_CACHE_MEMO=1).
 *
 * `configure()` (called from ext_localconf.php) gives the listed caches the
 * {@see WorkerMemoVariableFrontend}. That frontend asks the backend on every
 * get(), as before; in a FrankenPHP worker it skips the HMAC check and, for
 * object-free payloads, unserialize() as well, when the backend holds bytes
 * identical to bytes it already verified. For large payloads on a backend
 * with conditional reads the backend answers that without sending them.
 * Outside a worker (CLI, classic requests, backend pods) it is the core
 * VariableFrontend.
 *
 * Only caches that already exist when this extension's ext_localconf.php runs
 * and use the plain VariableFrontend are switched; a cache defined later by
 * another extension can name the frontend in its configuration itself.
 *
 * @internal
 */
final class WorkerCacheMemo
{
    public const ENV = 'KOH_WORKER_CACHE_MEMO';

    public const ENV_CACHES = 'KOH_WORKER_CACHE_MEMO_CACHES';

    public const ENV_MAX_BYTES = 'KOH_WORKER_CACHE_MEMO_MAX_BYTES';

    public const ENV_CONDITIONAL_READ_MIN_BYTES = 'KOH_WORKER_CACHE_MEMO_CONDITIONAL_MIN_BYTES';

    /**
     * Read-mostly caches whose reads dominate an uncached render (measured on
     * TYPO3 14: the menu trees in `hash` reach 2 MiB, `rootline` is read some
     * 20 times per page). Not `pages`: its entries are read once per request.
     */
    public const DEFAULT_CACHES = ['hash', 'rootline', 'dpnglossary_termscache', 'structured_content_schema', 'consent_catalog'];

    public static function isEnabled(): bool
    {
        return WorkerSwitch::isOn(self::ENV);
    }

    /**
     * Enabled, running in a FrankenPHP worker, and on a TYPO3 version that
     * authenticates cache payloads the way the frontend expects (v14).
     */
    public static function isActive(): bool
    {
        return self::isEnabled()
            && WorkerSwitch::isFrankenPhpWorker()
            && class_exists(AuthenticatedMessageDeserializer::class);
    }

    /**
     * @return positive-int
     */
    public static function maxBytes(): int
    {
        return WorkerSwitch::positiveInt(self::ENV_MAX_BYTES, VerifiedPayloadMemo::DEFAULT_MAX_BYTES);
    }

    /**
     * Payload size from which the frontend validates with a conditional read
     * (where the backend offers one); 0 switches conditional reads off.
     *
     * @return int<0, max>
     */
    public static function conditionalReadMinBytes(): int
    {
        $value = WorkerSwitch::value(self::ENV_CONDITIONAL_READ_MIN_BYTES);
        if ($value === null || preg_match('/^[0-9]+$/', $value) !== 1) {
            return VerifiedPayloadMemo::DEFAULT_CONDITIONAL_READ_MIN_BYTES;
        }

        return max(0, (int) $value);
    }

    /**
     * @return list<string>
     */
    public static function cacheIdentifiers(): array
    {
        $value = WorkerSwitch::value(self::ENV_CACHES);
        if ($value === null) {
            return self::DEFAULT_CACHES;
        }

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn(string $id): bool => $id !== '')));
    }

    /**
     * Switches the listed caches to the memo frontend when the switch is on.
     *
     * @param array<array-key, mixed> $cacheConfigurations TYPO3_CONF_VARS[SYS][caching][cacheConfigurations]
     * @param list<string>|null       $identifiers         null: from the environment
     *
     * @return list<string> the caches switched
     */
    public static function configure(array &$cacheConfigurations, ?bool $enabled = null, ?array $identifiers = null): array
    {
        if (!($enabled ?? self::isEnabled()) || !class_exists(AuthenticatedMessageDeserializer::class)) {
            return [];
        }
        $switched = [];
        foreach ($identifiers ?? self::cacheIdentifiers() as $identifier) {
            $configuration = $cacheConfigurations[$identifier] ?? null;
            if (!is_array($configuration)) {
                continue;
            }
            $frontend = $configuration['frontend'] ?? VariableFrontend::class;
            if (!is_string($frontend) || ltrim($frontend, '\\') !== VariableFrontend::class) {
                // PhpFrontend, FluidTemplateCache or an extension's own frontend: not ours to replace.
                continue;
            }
            $cacheConfigurations[$identifier]['frontend'] = WorkerMemoVariableFrontend::class;
            $switched[] = $identifier;
        }

        return $switched;
    }
}
