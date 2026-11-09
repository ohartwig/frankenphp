<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * A SingletonInterface service that loads language-dependent data once, in
 * its constructor, as glossary parsers do with their terms. Created through
 * makeInstance(), outside the DI container: it lives as long as
 * GeneralUtility's singleton registry keeps it. Kept from one request to the
 * next, it serves the terms of the previous request's language.
 */
final class LanguageTermsSingleton implements SingletonInterface
{
    public readonly int $languageId;

    public readonly string $terms;

    public function __construct()
    {
        [$this->languageId, $this->terms] = GreetingTitles::forGlobalRequest();
    }
}
