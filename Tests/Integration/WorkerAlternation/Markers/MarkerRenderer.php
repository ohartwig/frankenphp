<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * USER_INT for the worker alternation test: prints, one `<li data-marker>`
 * per fact, what TYPO3 believes about the request it is rendering.
 *
 * The values come from the places a leak would show up: the getIndpEnv()
 * cache, the request's normalizedParams, the request in $GLOBALS, the site
 * and language TYPO3 resolved, and absolute links built from them. The test
 * runner compares each value with what the request it sent should produce.
 *
 * The static properties deliberately survive between requests: in a worker
 * the class stays loaded, so `worker.token` stays the same and
 * `worker.count` grows by one per request as long as the same worker serves
 * every request. Without a worker each request starts at 1 again.
 */
final class MarkerRenderer
{
    private static ?string $token = null;

    private static int $count = 0;

    private ?ContentObjectRenderer $cObj = null;

    public function setContentObjectRenderer(ContentObjectRenderer $cObj): void
    {
        $this->cObj = $cObj;
    }

    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        self::$token ??= bin2hex(random_bytes(8));
        ++self::$count;

        $site = $request->getAttribute('site');
        $language = $request->getAttribute('language');
        $pageInformation = $request->getAttribute('frontend.page.information');
        $normalizedParams = $request->getAttribute('normalizedParams');
        $globalRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;

        $pageId = $pageInformation instanceof PageInformation ? $pageInformation->getId() : 0;
        $pageRecord = $pageInformation instanceof PageInformation ? $pageInformation->getPageRecord() : [];
        $otherPageId = (int) ($conf['otherPage.'][$pageId] ?? 0);

        $markers = [
            'worker.token' => self::$token,
            'worker.count' => (string) self::$count,
            'worker.pid' => (string) getmypid(),
            'indpenv.http_host' => (string) GeneralUtility::getIndpEnv('HTTP_HOST'),
            'indpenv.request_host' => (string) GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST'),
            'indpenv.site_url' => (string) GeneralUtility::getIndpEnv('TYPO3_SITE_URL'),
            'indpenv.request_uri' => (string) GeneralUtility::getIndpEnv('REQUEST_URI'),
            'normalized.http_host' => $normalizedParams instanceof NormalizedParams ? $normalizedParams->getHttpHost() : '',
            'normalized.site_url' => $normalizedParams instanceof NormalizedParams ? $normalizedParams->getSiteUrl() : '',
            'globals.request_host' => $globalRequest instanceof ServerRequestInterface ? $globalRequest->getUri()->getAuthority() : '',
            'singleton.request_host' => GeneralUtility::makeInstance(RequestHostMemo::class)->hostOf($request),
            'site.identifier' => $site instanceof Site ? $site->getIdentifier() : '',
            'site.base' => $site instanceof Site ? (string) $site->getBase() : '',
            'language.id' => $language instanceof SiteLanguage ? (string) $language->getLanguageId() : '',
            'language.code' => $language instanceof SiteLanguage ? $language->getLocale()->getLanguageCode() : '',
            'language.base' => $language instanceof SiteLanguage ? (string) $language->getBase() : '',
            'page.uid' => (string) $pageId,
            'page.title' => (string) ($pageRecord['title'] ?? ''),
            'link.self' => $this->absoluteLink($pageId, $request),
            'link.other' => $otherPageId > 0 ? $this->absoluteLink($otherPageId, $request) : '',
        ];

        $items = '';
        foreach ($markers as $key => $value) {
            $items .= '<li data-marker="' . $key . '">' . htmlspecialchars($value) . "</li>\n";
        }

        return "<ul id=\"worker-alternation-markers\">\n" . $items . "</ul>\n";
    }

    private function absoluteLink(int $pageId, ServerRequestInterface $request): string
    {
        $cObj = $this->cObj ?? GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->setRequest($request);

        return $cObj->createUrl(['parameter' => $pageId, 'forceAbsoluteUrl' => true]);
    }
}
