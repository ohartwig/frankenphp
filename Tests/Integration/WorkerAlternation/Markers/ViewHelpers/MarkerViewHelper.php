<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\ViewHelpers;

use Koh\FrankenPHP\Tests\Integration\WorkerAlternation\Markers\ConfigServiceMutator;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * `<wa:marker />`, through the global namespace `wa` (Configuration/Fluid/
 * Namespaces.php): renders which page and language Fluid rendered for, and
 * whether the namespace ConfigServiceMutator adds after a site B request is
 * known to this request's ViewHelper resolver.
 */
final class MarkerViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function render(): string
    {
        $context = $this->renderingContext;
        if ($context === null) {
            return '';
        }
        $request = $context->hasAttribute(ServerRequestInterface::class)
            ? $context->getAttribute(ServerRequestInterface::class)
            : null;
        $pageInformation = $request instanceof ServerRequestInterface ? $request->getAttribute('frontend.page.information') : null;
        $language = $request instanceof ServerRequestInterface ? $request->getAttribute('language') : null;
        $namespaces = $context->getViewHelperResolver()->getNamespaces();

        return sprintf(
            "<li data-marker=\"fluid.viewhelper\">wa-%d-%d</li>\n<li data-marker=\"fluid.namespace_leak\">%s</li>\n",
            $pageInformation instanceof PageInformation ? $pageInformation->getId() : 0,
            $language instanceof SiteLanguage ? $language->getLanguageId() : -1,
            isset($namespaces[ConfigServiceMutator::LEAKED_NAMESPACE]) ? '1' : '0',
        );
    }
}
