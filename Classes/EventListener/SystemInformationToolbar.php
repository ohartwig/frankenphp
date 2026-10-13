<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\EventListener;

use Koh\FrankenPHP\Service\WorkerSafeOpcodeCacheService;
use TYPO3\CMS\Backend\Backend\Event\SystemInformationToolbarCollectorEvent;

class SystemInformationToolbar
{
    public function __invoke(SystemInformationToolbarCollectorEvent $event): void
    {
        // @todo Explicitly check for the file being imported, once this information is available in the PSR-14 event
        $toolbarItem = $event->getToolbarItem();
        // Read the deployment flag through the same helper the OPcache service uses, so the
        // toolbar reports exactly what the extension acts on. A bare truthiness check on
        // $_SERVER also ignored getenv()/$_ENV and read "false"/"off" as enabled.
        $info = WorkerSafeOpcodeCacheService::isFrankenPhpWorkerDeployment() ? 'Enabled' : 'Disabled';
        $toolbarItem->addSystemInformation('Worker Mode', $info, 'frankenphp');
    }
}
