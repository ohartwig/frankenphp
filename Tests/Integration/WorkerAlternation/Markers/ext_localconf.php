<?php

declare(strict_types=1);

use Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\MarkerMetaTagManager;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

// Registered during the boot, so a persistent bootstrap keeps the registry
// with this registration, see MarkerMetaTagManager.
GeneralUtility::makeInstance(MetaTagManagerRegistry::class)->registerManager('worker_alternation', MarkerMetaTagManager::class);
