<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers;

use Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\Domain\Repository\GreetingRepository;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Context\Context;
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
 * Further markers come from where TYPO3 keeps state between the start and
 * the end of a request, which a bootstrap shared between requests would
 * keep for longer: the Context aspects, an Extbase repository read, a
 * SingletonInterface object and a DI service that load language-dependent
 * data in their constructor, and a static property a constructor appends to.
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
        $markers += $this->contextMarkers();
        $markers += $this->stateMarkers($site instanceof Site ? $site->getRootPageId() : 0, $conf);

        return self::renderList('worker-alternation-markers', $markers);
    }

    /**
     * The last content object of the page (USER_INT objects are rendered after
     * everything else): the global request must still be this request.
     *
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function renderLate(string $content, array $conf, ServerRequestInterface $request): string
    {
        $global = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $globalPage = $global instanceof ServerRequestInterface ? $global->getAttribute('frontend.page.information') : null;
        $requestPage = $request->getAttribute('frontend.page.information');

        return self::renderList('worker-alternation-late-int', [
            'late.int.globals_host' => $global instanceof ServerRequestInterface ? $global->getUri()->getAuthority() : '',
            'late.int.globals_page_id' => $globalPage instanceof PageInformation ? (string) $globalPage->getId() : '',
            'late.int.request_page_id' => $requestPage instanceof PageInformation ? (string) $requestPage->getId() : '',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function contextMarkers(): array
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $fallbackChain = $context->getPropertyFromAspect('language', 'fallbackChain', []);

        return [
            'context.language.id' => (string) $context->getPropertyFromAspect('language', 'id'),
            'context.language.content_id' => (string) $context->getPropertyFromAspect('language', 'contentId'),
            'context.language.overlay_type' => (string) $context->getPropertyFromAspect('language', 'overlayType'),
            'context.language.fallback_chain' => implode(',', is_array($fallbackChain) ? $fallbackChain : []),
            'context.date.timestamp' => (string) $context->getPropertyFromAspect('date', 'timestamp'),
            'globals.exec_time' => (string) ($GLOBALS['EXEC_TIME'] ?? ''),
            'globals.sim_exec_time' => (string) ($GLOBALS['SIM_EXEC_TIME'] ?? ''),
            'context.visibility.hidden_pages' => self::flag($context->getPropertyFromAspect('visibility', 'includeHiddenPages')),
            'context.visibility.hidden_content' => self::flag($context->getPropertyFromAspect('visibility', 'includeHiddenContent')),
            'context.visibility.deleted_records' => self::flag($context->getPropertyFromAspect('visibility', 'includeDeletedRecords')),
            'context.frontend_user.logged_in' => self::flag($context->getPropertyFromAspect('frontend.user', 'isLoggedIn')),
            'context.frontend_user.id' => (string) $context->getPropertyFromAspect('frontend.user', 'id'),
            'context.backend_user.logged_in' => self::flag($context->getPropertyFromAspect('backend.user', 'isLoggedIn')),
            'context.backend_user.id' => (string) $context->getPropertyFromAspect('backend.user', 'id'),
        ];
    }

    /**
     * @param array<string, mixed> $conf
     * @return array<string, string>
     */
    private function stateMarkers(int $rootPageId, array $conf): array
    {
        $greetingUid = (int) ($conf['greetingUid.'][$rootPageId] ?? 0);
        $greeting = GeneralUtility::makeInstance(GreetingRepository::class)->findInCurrentLanguage($greetingUid);
        $singleton = GeneralUtility::makeInstance(LanguageTermsSingleton::class);
        $service = GeneralUtility::makeInstance(LanguageTermsService::class);

        return [
            'extbase.title' => $greeting?->getTitle() ?? '',
            'extbase.language_uid' => $greeting === null ? '' : (string) $greeting->getLanguageUid(),
            'singleton.language_id' => (string) $singleton->languageId,
            'singleton.language_terms' => $singleton->terms,
            'service.language_id' => (string) $service->languageId,
            'service.language_terms' => $service->terms,
            'static.append_length' => (string) GeneralUtility::makeInstance(AppendingParser::class)->length(),
        ];
    }

    private static function flag(mixed $value): string
    {
        return $value ? '1' : '0';
    }

    /**
     * @param array<string, string> $markers
     */
    private static function renderList(string $id, array $markers): string
    {
        $items = '';
        foreach ($markers as $key => $value) {
            $items .= '<li data-marker="' . $key . '">' . htmlspecialchars($value) . "</li>\n";
        }

        return '<ul id="' . $id . "\">\n" . $items . "</ul>\n";
    }

    private function absoluteLink(int $pageId, ServerRequestInterface $request): string
    {
        $cObj = $this->cObj ?? GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->setRequest($request);

        return $cObj->createUrl(['parameter' => $pageId, 'forceAbsoluteUrl' => true]);
    }
}
