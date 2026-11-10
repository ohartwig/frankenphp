<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use TYPO3\CMS\Core\MetaTag\AbstractMetaTagManager;

/**
 * A meta tag manager of an extension's own, registered in ext_localconf.php
 * as koh/structured-content or a site package would register one. It handles
 * the property `wa-mt`; PageAssets adds it without replacing, as TYPO3's SEO
 * extension adds the description and the Open Graph tags. A manager kept
 * from an earlier request still holds that request's value, and the page
 * prints it instead of its own.
 */
final class MarkerMetaTagManager extends AbstractMetaTagManager
{
    protected $handledProperties = [
        'wa-mt' => [],
    ];
}
