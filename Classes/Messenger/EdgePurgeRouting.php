<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Messenger;

use Koh\FrankenPHP\Message\PurgeEdgeCache;

/**
 * Whether the edge cache purge after an editorial save leaves the request.
 *
 * It does only when all three hold:
 *
 *  - koh/typo3-async is installed in a release that has the koh_async_cache
 *    transport (its Transports::CACHE constant exists). Routing to a
 *    transport the container does not know fails every dispatch;
 *  - koh_async's AsyncMode is on (MESSENGER_ASYNC=1): the platform sets it
 *    together with the consumer, and a queue nobody reads keeps its purges;
 *  - KOH_ASYNC_EDGE_PURGE is on: the purge moves per tenant, after the
 *    tenant's consumer reads koh_async_cache.
 *
 * Otherwise PurgeEdgeCache stays on TYPO3's default routing, which handles
 * it in the request: the purges are the ones sent before the message existed.
 *
 * The message class goes on koh_async's decoding allowlist whenever the
 * transport exists, switch or not: the allowlist is read in the consumer,
 * which may run with other environment variables than the pod that queued
 * the message, and a row it refuses stops it. The exact class, not a
 * namespace: it is made of strings and arrays only.
 *
 * Called from ext_localconf.php. koh/typo3-async is no dependency of this
 * package, so its classes are named as strings here.
 */
final class EdgePurgeRouting
{
    public const ENV = 'KOH_ASYNC_EDGE_PURGE';

    public const TRANSPORT_CONSTANT = 'Koh\\Typo3Async\\Infrastructure\\Messenger\\Transports::CACHE';

    public const ASYNC_MODE_FACTORY = ['Koh\\Typo3Async\\Domain\\AsyncMode', 'fromEnvironment'];

    /**
     * The name of koh_async's cache transport, or null where the installed
     * koh/typo3-async has none (or none is installed).
     */
    public static function availableTransport(bool $kohAsyncLoaded, string $constant = self::TRANSPORT_CONSTANT): ?string
    {
        if (!$kohAsyncLoaded || !\defined($constant)) {
            return null;
        }
        $name = \constant($constant);

        return \is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * @param array{string, string} $factory
     */
    public static function asyncModeEnabled(array $factory = self::ASYNC_MODE_FACTORY): bool
    {
        if (!\is_callable($factory)) {
            return false;
        }
        $mode = $factory();

        return \is_object($mode) && method_exists($mode, 'isEnabled') && $mode->isEnabled() === true;
    }

    public static function switchedOn(): bool
    {
        $value = getenv(self::ENV);

        return \is_string($value) && \in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<array-key, mixed> $confVars $GLOBALS['TYPO3_CONF_VARS']
     */
    public static function configure(array &$confVars, ?string $transport, bool $asyncMode, bool $switchedOn): void
    {
        if ($transport === null) {
            return;
        }
        $extensions = \is_array($confVars['EXTENSIONS'] ?? null) ? $confVars['EXTENSIONS'] : [];
        $kohAsync = \is_array($extensions['koh_async'] ?? null) ? $extensions['koh_async'] : [];
        $kohAsync['messageClassPrefixes'] = self::withMessageClass($kohAsync['messageClassPrefixes'] ?? []);
        $extensions['koh_async'] = $kohAsync;
        $confVars['EXTENSIONS'] = $extensions;

        if ($asyncMode && $switchedOn) {
            $sys = \is_array($confVars['SYS'] ?? null) ? $confVars['SYS'] : [];
            $messenger = \is_array($sys['messenger'] ?? null) ? $sys['messenger'] : [];
            $routing = \is_array($messenger['routing'] ?? null) ? $messenger['routing'] : [];
            $routing[PurgeEdgeCache::class] = $transport;
            $messenger['routing'] = $routing;
            $sys['messenger'] = $messenger;
            $confVars['SYS'] = $sys;
        }
    }

    /**
     * koh_async reads messageClassPrefixes as a list or a comma-separated
     * string; both keep what they hold.
     *
     * @return list<string>
     */
    private static function withMessageClass(mixed $configured): array
    {
        if (\is_string($configured)) {
            $configured = explode(',', $configured);
        }
        $prefixes = [];
        foreach (\is_array($configured) ? $configured : [] as $prefix) {
            if (\is_string($prefix) && trim($prefix) !== '') {
                $prefixes[] = trim($prefix);
            }
        }
        if (!\in_array(PurgeEdgeCache::class, $prefixes, true)) {
            $prefixes[] = PurgeEdgeCache::class;
        }

        return $prefixes;
    }
}
