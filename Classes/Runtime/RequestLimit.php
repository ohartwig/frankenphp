<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * How many requests a worker serves before it ends and FrankenPHP starts a
 * fresh one (MAX_REQUESTS).
 *
 * The limit bounds what nothing resets: static properties, memory a library
 * never gives back. Workers of a pod start together, so with the same limit
 * they also end together, and the requests that arrive then all wait for a
 * boot. Each worker therefore moves its own limit by up to JITTER_PERCENT in
 * either direction, and the restarts spread out.
 *
 * @internal
 */
final class RequestLimit
{
    public const ENV = 'MAX_REQUESTS';

    public const DEFAULT = 500;

    /** How far a worker moves its limit, in percent of the configured value. */
    public const JITTER_PERCENT = 10;

    /**
     * @param array<array-key, mixed>             $environment as for DatabaseConnectionReuse::fromEnvironment()
     * @param (\Closure(int, int): int)|null $random      for tests: instead of random_int()
     *
     * @return positive-int
     */
    public static function fromEnvironment(array $environment, ?\Closure $random = null): int
    {
        $limit = self::configured($environment[self::ENV] ?? null);
        $jitter = intdiv($limit * self::JITTER_PERCENT, 100);
        if ($jitter === 0) {
            return $limit;
        }
        $random ??= random_int(...);

        return max(1, $limit + max(-$jitter, min($jitter, $random(-$jitter, $jitter))));
    }

    /**
     * Anything but a whole number above zero is the default: a limit of zero
     * would end the worker before its first request, again and again.
     *
     * @return positive-int
     */
    private static function configured(mixed $value): int
    {
        if (!is_scalar($value) || preg_match('/^[0-9]+$/', trim((string) $value)) !== 1) {
            return self::DEFAULT;
        }
        $limit = (int) trim((string) $value);

        return $limit >= 1 ? $limit : self::DEFAULT;
    }
}
