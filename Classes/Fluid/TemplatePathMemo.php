<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Fluid;

use Koh\FrankenPHP\Runtime\WorkerSwitch;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * Worker-wide memory of Fluid's file lookups (KOH_WORKER_TEMPLATE_PATH_MEMO=1).
 *
 * Fluid resolves every template, layout and partial by trying up to six
 * candidate files per root path (`.fluid.html`, `.html`, no extension, each
 * also with an upper-case first letter) with is_file(), sanitises every root
 * path with is_dir() and builds each template's identifier from
 * file_exists() and filemtime(). Under open_basedir each of those calls is
 * checked against the allowed directories, and failed lookups, the common
 * case here, are the dearest. This memo keeps their outcome for the life of a
 * FrankenPHP worker:
 *
 * - root path sanitising: input path → sanitised path;
 * - file resolution: (root paths, name, format) → resolved file, or the list
 *   of evaluated candidates when none exists (a negative lookup);
 * - template identifiers: file → identifier (it embeds the file's mtime);
 * - existence of an explicitly set template file.
 *
 * Only lookups inside code directories are kept: the vendor directory and the
 * paths of the active packages. That is code shipped with the image, which
 * only changes with a deployment, and a deployment starts new workers.
 * Anything else (fileadmin/, var/, a path with `..`) is resolved by Fluid on
 * every call as before. open_basedir itself is untouched.
 *
 * Templates edited inside a running worker (local development) are not
 * noticed until the worker restarts: keep the switch off there.
 *
 * Bounded by entry count; when full, further lookups are not recorded.
 *
 * @internal
 */
final class TemplatePathMemo
{
    public const ENV = 'KOH_WORKER_TEMPLATE_PATH_MEMO';

    public const ENV_MAX_ENTRIES = 'KOH_WORKER_TEMPLATE_PATH_MEMO_MAX_ENTRIES';

    public const DEFAULT_MAX_ENTRIES = 8192;

    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $sanitized = [];

    /** @var array<string, string|list<string>> resolved file, or the candidates evaluated in vain */
    private array $resolved = [];

    /** @var array<string, string> */
    private array $identifiers = [];

    /** @var array<string, true> */
    private array $existing = [];

    private int $entries = 0;

    /** @var list<string> */
    private array $codeRoots;

    /**
     * @param list<string> $codeRoots absolute directories whose files only change with a deployment
     */
    public function __construct(array $codeRoots, private readonly int $maxEntries = self::DEFAULT_MAX_ENTRIES)
    {
        $roots = [];
        foreach ($codeRoots as $root) {
            if ($root === '' || $root[0] !== '/' || self::hasDotSegment($root)) {
                continue;
            }
            $roots[] = rtrim($root, '/') . '/';
        }
        $this->codeRoots = array_values(array_unique($roots));
    }

    public static function isEnabled(): bool
    {
        return WorkerSwitch::isOn(self::ENV);
    }

    /**
     * The process-wide memo when the switch is on and this process is a
     * FrankenPHP worker, null otherwise.
     */
    public static function active(): ?self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (!self::isEnabled() || !WorkerSwitch::isFrankenPhpWorker() || !self::isSupportedFluid()) {
            return null;
        }

        return self::$instance = new self(
            self::installedCodeRoots(),
            WorkerSwitch::positiveInt(self::ENV_MAX_ENTRIES, self::DEFAULT_MAX_ENTRIES),
        );
    }

    /**
     * @internal for tests: use the given memo (null: decide again on the next call)
     */
    public static function setInstance(?self $memo): void
    {
        self::$instance = $memo;
    }

    /**
     * Fluid 5 (TYPO3 14): the not-found exception carries the evaluated
     * candidates, which a negative lookup needs to throw the same exception
     * again.
     */
    public static function isSupportedFluid(): bool
    {
        return property_exists(InvalidTemplateResourceException::class, 'evaluatedTemplatePaths');
    }

    /**
     * The vendor directory and the paths of all active packages.
     *
     * @return list<string>
     */
    public static function installedCodeRoots(): array
    {
        $roots = [Environment::getProjectPath() . '/vendor/'];
        foreach (GeneralUtility::makeInstance(PackageManager::class)->getActivePackages() as $package) {
            $roots[] = $package->getPackagePath();
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    public function codeRoots(): array
    {
        return $this->codeRoots;
    }

    public function covers(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || self::hasDotSegment($path)) {
            return false;
        }
        foreach ($this->codeRoots as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, string> $paths
     */
    public function coversAll(array $paths, string $fileName): bool
    {
        if ($paths === [] || self::hasDotSegment('/' . $fileName . '/')) {
            return false;
        }
        foreach ($paths as $path) {
            if (!$this->covers($path)) {
                return false;
            }
        }

        return true;
    }

    public function sanitized(string $input): ?string
    {
        return $this->sanitized[$input] ?? null;
    }

    public function rememberSanitized(string $input, string $sanitized): void
    {
        if (!isset($this->sanitized[$input]) && $this->hasRoom()) {
            $this->sanitized[$input] = $sanitized;
            ++$this->entries;
        }
    }

    /**
     * @return string|list<string>|null the file, the candidates evaluated in vain, or null if unknown
     */
    public function resolved(string $key): string|array|null
    {
        return $this->resolved[$key] ?? null;
    }

    /**
     * @param string|list<string> $outcome
     */
    public function rememberResolved(string $key, string|array $outcome): void
    {
        if (!isset($this->resolved[$key]) && $this->hasRoom()) {
            $this->resolved[$key] = $outcome;
            ++$this->entries;
        }
    }

    public function identifier(string $file): ?string
    {
        return $this->identifiers[$file] ?? null;
    }

    public function rememberIdentifier(string $file, string $identifier): void
    {
        if (!isset($this->identifiers[$file]) && $this->hasRoom()) {
            $this->identifiers[$file] = $identifier;
            ++$this->entries;
        }
    }

    public function isKnownToExist(string $file): bool
    {
        return isset($this->existing[$file]);
    }

    public function rememberExisting(string $file): void
    {
        if (!isset($this->existing[$file]) && $this->hasRoom()) {
            $this->existing[$file] = true;
            ++$this->entries;
        }
    }

    public function count(): int
    {
        return $this->entries;
    }

    private function hasRoom(): bool
    {
        return $this->entries < $this->maxEntries;
    }

    private static function hasDotSegment(string $path): bool
    {
        return str_contains($path, '/../') || str_contains($path, '/./') || str_ends_with($path, '/..') || str_contains($path, "\0");
    }
}
