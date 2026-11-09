<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Language-dependent data as the probes load it: the titles of all greetings
 * in the language of the request in $GLOBALS['TYPO3_REQUEST'], the way a
 * service that is built without a request at hand finds out the language.
 */
final class GreetingTitles
{
    /**
     * @return array{0: int, 1: string} language id and the titles, comma-separated
     */
    public static function forGlobalRequest(): array
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $language = $request instanceof ServerRequestInterface ? $request->getAttribute('language') : null;
        $languageId = $language instanceof SiteLanguage ? $language->getLanguageId() : -1;

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_workeralternationmarkers_greeting');
        $titles = $queryBuilder
            ->select('title')
            ->from('tx_workeralternationmarkers_greeting')
            ->where($queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchFirstColumn();

        return [$languageId, implode(',', array_map(strval(...), $titles))];
    }
}
