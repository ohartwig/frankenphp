<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Service;

/**
 * Writes one JSON line per edge cache purge to stderr, with what caused it.
 *
 * Off unless the environment sets KOH_CACHE_PURGE_AUDIT=1. Meant for a
 * measurement window: the purge itself is unchanged, the line only says
 * which request, command, backend user and code path asked for it, so a
 * purge rate that empties the edge cache can be traced to its source.
 *
 * Written with error_log() rather than a TYPO3 logger, so it needs no log
 * configuration in the installation and lands in the container log like
 * the rest of FrankenPHP's output.
 */
final class PurgeAudit
{
    public const ENV = 'KOH_CACHE_PURGE_AUDIT';

    private const MAX_FRAMES = 12;

    /** @var (callable(string): mixed)|null */
    private static $writer;

    public static function isEnabled(): bool
    {
        $value = getenv(self::ENV);

        return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function record(string $action, array $details = []): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $line = json_encode(
            ['koh_purge_audit' => $action] + $details + self::context(),
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        $writer = self::$writer ?? static fn(string $message): bool => error_log($message);
        $writer((string) $line);
    }

    /**
     * For tests: where lines go instead of error_log(). null restores the default.
     *
     * @param (callable(string): mixed)|null $writer
     */
    public static function writeTo(?callable $writer): void
    {
        self::$writer = $writer;
    }

    /**
     * @return array<string, mixed>
     */
    public static function context(): array
    {
        $context = ['sapi' => PHP_SAPI];

        if (PHP_SAPI === 'cli') {
            $argv = $_SERVER['argv'] ?? [];
            if (is_array($argv)) {
                $context['command'] = implode(' ', array_slice($argv, 1, 3));
            }
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof \Psr\Http\Message\ServerRequestInterface) {
            $uri = $request->getUri();
            $context['request'] = $request->getMethod() . ' ' . $uri->getHost() . $uri->getPath();
            $userAgent = $request->getHeaderLine('User-Agent');
            if ('' !== $userAgent) {
                $context['user_agent'] = substr($userAgent, 0, 80);
            }
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (is_object($backendUser) && isset($backendUser->user) && is_array($backendUser->user)) {
            $context['be_user'] = (string) ($backendUser->user['username'] ?? '');
            $context['workspace'] = (int) ($backendUser->workspace ?? 0);
        }

        $context['caller'] = self::callers();

        return $context;
    }

    /**
     * The code path that asked for the purge, innermost first, without this
     * package's own purge plumbing.
     *
     * @return list<string>
     */
    private static function callers(): array
    {
        $frames = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if ('' === $class || str_starts_with($class, 'Koh\\FrankenPHP\\Service\\')) {
                continue;
            }
            $frames[] = $class . '::' . $frame['function'];
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }

        return $frames;
    }
}
