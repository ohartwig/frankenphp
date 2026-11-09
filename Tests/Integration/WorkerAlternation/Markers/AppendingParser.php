<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * A singleton whose constructor appends to a static property, as
 * featdd/dpn_glossary's ParserService does with
 * `self::$additionalRegexWrapCharacters .= ...`. Every time the object is
 * built again, the static string grows, because nothing in TYPO3 resets
 * static properties: within one PHP process that is a growing regex, across
 * a worker's requests an ever longer one.
 */
final class AppendingParser implements SingletonInterface
{
    public static string $wrapCharacters = '';

    public function __construct()
    {
        self::$wrapCharacters .= '\\.\\,';
    }

    public function length(): int
    {
        return strlen(self::$wrapCharacters);
    }
}
