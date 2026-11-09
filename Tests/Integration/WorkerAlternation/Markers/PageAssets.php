<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * USER (rendered on every request because of config.no_cache) that adds a
 * meta tag, inline JavaScript and a style sheet for the current page and
 * language through PageRenderer and through AssetCollector. Each carries the
 * key "<page uid>-<language id>". A PageRenderer or AssetCollector kept from
 * an earlier request would print that request's assets as well, which the
 * runner sees as a second key or a duplicate. The style sheets are external
 * URLs: nothing loads them, and TYPO3 does not look for local files.
 */
final class PageAssets
{
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        $language = $request->getAttribute('language');
        $key = sprintf(
            '%d-%d',
            $pageInformation instanceof PageInformation ? $pageInformation->getId() : 0,
            $language instanceof SiteLanguage ? $language->getLanguageId() : -1,
        );

        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->setMetaTag('name', 'wa-pr-meta', 'wa-pr-meta-' . $key);
        // PageRenderer prints the block name as a comment: /*wa-pr-js-<key>*/
        $pageRenderer->addJsInlineCode('wa-pr-js-' . $key, 'window.waPageRenderer = "' . $key . '";');
        $pageRenderer->addCssFile('https://assets.example/wa-pr-css-' . $key . '.css');

        $assetCollector = GeneralUtility::makeInstance(AssetCollector::class);
        $assetCollector->addInlineJavaScript('wa-ac-js-' . $key, '/* wa-ac-js-' . $key . ' */ window.waAssetCollector = "' . $key . '";');
        $assetCollector->addStyleSheet('wa-ac-css-' . $key, 'https://assets.example/wa-ac-css-' . $key . '.css');

        return '';
    }
}
