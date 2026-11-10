<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

/**
 * Reads on/off switches and numbers from the environment, and tells whether
 * this PHP process is a FrankenPHP worker.
 *
 * Settings may come from the worker's `env` lines in the Caddyfile (then they
 * are in $_SERVER) or from the container environment (getenv()); $_ENV is
 * empty when variables_order lacks E. The same order as the worker script.
 *
 * @internal
 */
final class WorkerSwitch
{
    public static function isOn(string $name): bool
    {
        $value = self::value($name);
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public static function value(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_scalar($value) || $value === false) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param positive-int $default
     *
     * @return positive-int
     */
    public static function positiveInt(string $name, int $default): int
    {
        $value = self::value($name);
        if ($value === null || preg_match('/^[0-9]+$/', $value) !== 1 || (int) $value < 1) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Whether this process is a FrankenPHP worker (the frontend worker script
     * runs only there), as opposed to a classic request or the CLI. Memory
     * that outlives a request is only worth anything there.
     */
    public static function isFrankenPhpWorker(): bool
    {
        return \function_exists('frankenphp_handle_request') && (bool) ($_SERVER['FRANKENPHP_WORKER'] ?? false);
    }
}
