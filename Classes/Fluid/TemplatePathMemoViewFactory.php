<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Fluid;

use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\CMS\Fluid\View\FluidViewFactory;
use TYPO3Fluid\Fluid\View\TemplateView;

/**
 * Decorates TYPO3's ViewFactoryInterface (PAGEVIEW, FLUIDTEMPLATE, Extbase
 * actions): with KOH_WORKER_TEMPLATE_PATH_MEMO=1 in a FrankenPHP worker, the
 * Fluid view it creates resolves its files through
 * {@see MemoizingTemplatePaths}. Otherwise, and whenever the decorated
 * factory is not TYPO3's FluidViewFactory, it is a plain pass-through.
 *
 * The view is built as FluidViewFactory::create() builds it; only the
 * TemplatePaths object differs, so that setting the root paths (each one
 * sanitised with is_dir()) already goes through the memo.
 *
 * @internal
 */
final readonly class TemplatePathMemoViewFactory implements ViewFactoryInterface
{
    public function __construct(
        private ViewFactoryInterface $inner,
        private RenderingContextFactory $renderingContextFactory,
    ) {}

    public function create(ViewFactoryData $data): ViewInterface
    {
        $memo = TemplatePathMemo::active();
        if ($memo === null || !$this->inner instanceof FluidViewFactory) {
            return $this->inner->create($data);
        }

        $renderingContext = $this->renderingContextFactory->create([], $data->request);
        $templatePaths = new MemoizingTemplatePaths($memo);
        if ($data->templateRootPaths !== null && $data->templateRootPaths !== []) {
            $templatePaths->setTemplateRootPaths($data->templateRootPaths);
        }
        if ($data->layoutRootPaths !== null && $data->layoutRootPaths !== []) {
            $templatePaths->setLayoutRootPaths($data->layoutRootPaths);
        }
        if ($data->partialRootPaths !== null && $data->partialRootPaths !== []) {
            $templatePaths->setPartialRootPaths($data->partialRootPaths);
        }
        $renderingContext->setTemplatePaths($templatePaths);
        if ($data->templatePathAndFilename !== null && $data->templatePathAndFilename !== '') {
            $templatePaths->setTemplatePathAndFilename($data->templatePathAndFilename);
        }
        if ($data->format !== null && $data->format !== '') {
            $templatePaths->setFormat($data->format);
        }

        return new FluidViewAdapter(new TemplateView($renderingContext));
    }
}
