<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Koh\FrankenPHP\Service\CaddyCacheService;

class CacheManagementController extends ActionController
{
    private CaddyCacheService $caddyCacheService;
    private SiteFinder $siteFinder;
    private ModuleTemplateFactory $moduleTemplateFactory;

    public function __construct(
        CaddyCacheService $caddyCacheService,
        SiteFinder $siteFinder,
        ModuleTemplateFactory $moduleTemplateFactory
    ) {
        $this->caddyCacheService = $caddyCacheService;
        $this->siteFinder = $siteFinder;
        $this->moduleTemplateFactory = $moduleTemplateFactory;
    }

    /**
     * Show cache management interface
     */
    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle('Caddy Cache Management');

        $sites = $this->siteFinder->getAllSites();

        $moduleTemplate->assignMultiple([
            'sites' => $sites,
            'keys' => $this->caddyCacheService->getKeys(),
            'surrogate_keys' => $this->caddyCacheService->getSurrogateKeys(),
        ]);

        return $moduleTemplate->renderResponse('CacheManagement/Index');
    }

    /**
     * Flush all cache
     */
    public function flushAction(): ResponseInterface
    {
        try {
            $this->caddyCacheService->invalidateAllSites();

            $this->addFlashMessage(
                'Caddy cache has been successfully flushed for all sites.',
                'Cache Flushed',
                ContextualFeedbackSeverity::OK
            );
        } catch (\Exception $e) {
            $this->addFlashMessage(
                'Failed to flush Caddy cache: ' . $e->getMessage(),
                'Cache Flush Error',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse($this->uriBuilder->uriFor('index'));
    }

    /**
     * Flush cache for specific site
     */
    public function flushSiteAction(): ResponseInterface
    {
        $siteIdentifier = $this->request->getArgument('site');

        if (!is_string($siteIdentifier) || $siteIdentifier === '') {
            $this->addFlashMessage(
                'No site identifier provided.',
                'Invalid Request',
                ContextualFeedbackSeverity::ERROR
            );
            return new RedirectResponse($this->uriBuilder->uriFor('index'));
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            $result = $this->caddyCacheService->flushSite($site);

            if ($result->isComplete()) {
                $this->addFlashMessage(
                    sprintf('Caddy cache has been flushed for site "%s".', $site->getIdentifier()),
                    'Cache Flushed',
                    ContextualFeedbackSeverity::OK
                );
            } elseif ($result->disabled) {
                $this->addFlashMessage(
                    'The Caddy cache integration is disabled; nothing was flushed.',
                    'Cache Not Flushed',
                    ContextualFeedbackSeverity::WARNING
                );
            } elseif ($result->failed) {
                $this->addFlashMessage(
                    sprintf('The Caddy cache for site "%s" could not be flushed; see the log.', $site->getIdentifier()),
                    'Cache Flush Error',
                    ContextualFeedbackSeverity::ERROR
                );
            } else {
                $this->addFlashMessage(
                    sprintf(
                        'Nothing was flushed for site "%s": no Surrogate-Key emitted for %s. '
                        . 'Pages cached before the key was emitted are not reachable by tag; use "Flush all".',
                        $site->getIdentifier(),
                        implode(', ', $result->unmatched),
                    ),
                    'Nothing To Flush',
                    ContextualFeedbackSeverity::WARNING
                );
            }
        } catch (\Exception $e) {
            $this->addFlashMessage(
                'Failed to flush Caddy cache for site: ' . $e->getMessage(),
                'Cache Flush Error',
                ContextualFeedbackSeverity::ERROR
            );
        }

        return new RedirectResponse($this->uriBuilder->uriFor('index'));
    }

}
