<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\EventListener;

use Koh\FrankenPHP\Runtime\RequestTiming;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\Event\AfterCacheableContentIsGeneratedEvent;

/**
 * Tells RequestTiming that TYPO3 rendered the page in this request: the event
 * fires after the content is generated and never for a page served from the
 * page cache.
 */
final class RequestTimingListener
{
    #[AsEventListener(identifier: 'frankenphp-request-timing-rendered')]
    public function __invoke(AfterCacheableContentIsGeneratedEvent $event): void
    {
        RequestTiming::markRendered();
    }
}
