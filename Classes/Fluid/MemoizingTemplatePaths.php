<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Fluid;

use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * TYPO3's TemplatePaths with its file lookups answered from the worker's
 * {@see TemplatePathMemo} where they concern code directories.
 *
 * Behaviour is that of the parent class: the same file is resolved, the same
 * identifier built, the same exception (message, code, evaluated paths)
 * thrown when nothing matches. Only the filesystem calls behind a lookup that
 * was already made in this worker are skipped.
 *
 * @internal
 */
class MemoizingTemplatePaths extends TemplatePaths
{
    public function __construct(private readonly TemplatePathMemo $memo) {}

    public function resolveTemplateFileForControllerAndActionAndFormat(string $controller, string $action, ?string $format = null, bool $throwOnError = false): ?string
    {
        // With an explicit template file the parent only checks that it
        // exists (file_exists()) before returning it.
        $file = $this->templatePathAndFilename;
        if ($file !== null && $throwOnError && $file !== 'php://stdin' && $this->memo->covers($file)) {
            if ($this->memo->isKnownToExist($file)) {
                return $file;
            }
            $resolved = parent::resolveTemplateFileForControllerAndActionAndFormat($controller, $action, $format, $throwOnError);
            $this->memo->rememberExisting($file);

            return $resolved;
        }

        return parent::resolveTemplateFileForControllerAndActionAndFormat($controller, $action, $format, $throwOnError);
    }

    protected function resolveFileInPaths(array $paths, string $fileName, string $format): string
    {
        if (!$this->memo->coversAll($paths, $fileName)) {
            return parent::resolveFileInPaths($paths, $fileName, $format);
        }
        $key = $fileName . "\0" . $format . "\0" . implode("\0", $paths);
        $known = $this->memo->resolved($key);
        if (is_string($known)) {
            return $known;
        }
        if (is_array($known)) {
            throw self::notFound($fileName, $known);
        }

        try {
            $resolved = parent::resolveFileInPaths($paths, $fileName, $format);
        } catch (InvalidTemplateResourceException $e) {
            $this->memo->rememberResolved($key, array_values($e->evaluatedTemplatePaths));
            throw $e;
        }
        $this->memo->rememberResolved($key, $resolved);

        return $resolved;
    }

    protected function sanitizePath(mixed $path): string|array
    {
        if (!is_string($path) || $path === '') {
            return parent::sanitizePath($path);
        }
        $known = $this->memo->sanitized($path);
        if ($known !== null) {
            return $known;
        }
        $sanitized = parent::sanitizePath($path);
        if (is_string($sanitized) && $this->memo->covers($sanitized)) {
            $this->memo->rememberSanitized($path, $sanitized);
        }

        return $sanitized;
    }

    protected function createIdentifierForFile(?string $pathAndFilename): string
    {
        if ($pathAndFilename === null || !$this->memo->covers($pathAndFilename)) {
            return parent::createIdentifierForFile($pathAndFilename);
        }
        $known = $this->memo->identifier($pathAndFilename);
        if ($known !== null) {
            return $known;
        }
        $identifier = parent::createIdentifierForFile($pathAndFilename);
        // A file that did not exist yields an identifier without mtime; it is
        // not remembered, so a later lookup sees the file once it is there.
        if (is_file($pathAndFilename)) {
            $this->memo->rememberIdentifier($pathAndFilename, $identifier);
        }

        return $identifier;
    }

    /**
     * The exception the parent's resolveFileInPaths() throws for these candidates.
     *
     * @param list<string> $possibleTemplates
     */
    private static function notFound(string $fileName, array $possibleTemplates): InvalidTemplateResourceException
    {
        return new InvalidTemplateResourceException(sprintf(
            'The Fluid template file "%s" could not be loaded. Tried paths: "%s"',
            $fileName,
            implode('", "', $possibleTemplates),
        ), 1225709595, null, $fileName, $possibleTemplates);
    }
}
