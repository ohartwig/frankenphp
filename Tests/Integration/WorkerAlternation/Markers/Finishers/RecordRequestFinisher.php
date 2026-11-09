<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\Finishers;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Form\Domain\Finishers\AbstractFinisher;

/**
 * Runs after SaveToDatabase and writes into the row it inserted which site,
 * language and host handled the submission, as the request the form runtime
 * holds tells them. A request kept from an earlier submission would record
 * that one's site, language or host.
 */
final class RecordRequestFinisher extends AbstractFinisher
{
    protected function executeInternal()
    {
        $uid = (int) $this->finisherContext->getFinisherVariableProvider()->get('SaveToDatabase', 'insertedUids.0', 0);
        if ($uid === 0) {
            return null;
        }
        $request = $this->finisherContext->getRequest();
        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        $normalizedParams = $request->getAttribute('normalizedParams');

        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_workeralternationmarkers_submission')
            ->update(
                'tx_workeralternationmarkers_submission',
                [
                    'site' => $site instanceof Site ? $site->getIdentifier() : '',
                    'language' => $language instanceof SiteLanguage ? $language->getLanguageId() : -1,
                    'host' => $normalizedParams instanceof NormalizedParams ? $normalizedParams->getHttpHost() : '',
                ],
                ['uid' => $uid],
            );

        return null;
    }
}
