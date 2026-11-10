<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Functional;

use Koh\FrankenPHP\Fluid\MemoizingTemplatePaths;
use Koh\FrankenPHP\Fluid\TemplatePathMemo;
use Koh\FrankenPHP\Fluid\TemplatePathMemoViewFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Fluid\Core\Rendering\RenderingContextFactory;
use TYPO3\CMS\Fluid\View\FluidViewAdapter;
use TYPO3\CMS\Fluid\View\FluidViewFactory;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The decorator sits in front of TYPO3's view factory, is a pass-through
 * while the memo is off, and with the memo on builds the view exactly as
 * FluidViewFactory does except for the TemplatePaths class.
 */
final class TemplatePathMemoViewFactoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/frankenphp'];

    protected function tearDown(): void
    {
        TemplatePathMemo::setInstance(null);
        parent::tearDown();
    }

    private function data(): ViewFactoryData
    {
        return new ViewFactoryData(
            templateRootPaths: [20 => 'EXT:fluid/Resources/Private/Templates/', 10 => 'EXT:frankenphp/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:frankenphp/Resources/Private/Partials/', 'EXT:frankenphp/Resources/Private/Missing/'],
            layoutRootPaths: ['EXT:frankenphp/Resources/Private/Layouts/'],
            format: 'html',
        );
    }

    #[Test]
    public function theViewFactoryIsDecorated(): void
    {
        self::assertInstanceOf(TemplatePathMemoViewFactory::class, $this->get(ViewFactoryInterface::class));
    }

    #[Test]
    public function withTheMemoOffTheViewIsTheCoresOwn(): void
    {
        $view = $this->get(ViewFactoryInterface::class)->create($this->data());

        self::assertInstanceOf(FluidViewAdapter::class, $view);
        self::assertSame(TemplatePaths::class, $view->getRenderingContext()->getTemplatePaths()::class);
    }

    #[Test]
    public function withTheMemoOnTheViewResolvesThroughItAndOtherwiseMatchesTheCore(): void
    {
        $core = (new FluidViewFactory($this->get(RenderingContextFactory::class)))->create($this->data());
        TemplatePathMemo::setInstance(new TemplatePathMemo(TemplatePathMemo::installedCodeRoots()));

        $view = $this->get(ViewFactoryInterface::class)->create($this->data());

        self::assertInstanceOf(FluidViewAdapter::class, $core);
        self::assertInstanceOf(FluidViewAdapter::class, $view);
        $corePaths = $core->getRenderingContext()->getTemplatePaths();
        $paths = $view->getRenderingContext()->getTemplatePaths();
        self::assertInstanceOf(MemoizingTemplatePaths::class, $paths);
        self::assertSame($corePaths->getTemplateRootPaths(), $paths->getTemplateRootPaths());
        self::assertSame($corePaths->getPartialRootPaths(), $paths->getPartialRootPaths());
        self::assertSame($corePaths->getLayoutRootPaths(), $paths->getLayoutRootPaths());
        self::assertSame($corePaths->getFormat(), $paths->getFormat());
        self::assertSame(
            $corePaths->getLayoutPathAndFilename('Default'),
            $paths->getLayoutPathAndFilename('Default'),
        );
    }

    #[Test]
    public function theCodeRootsAreTheVendorDirectoryAndThePackages(): void
    {
        $memo = new TemplatePathMemo(TemplatePathMemo::installedCodeRoots());

        $template = $this->get(\TYPO3\CMS\Core\Package\PackageManager::class)->getPackage('frankenphp')->getPackagePath() . 'Resources/Private/Layouts/Default.html';
        self::assertTrue($memo->covers($template));
        self::assertFalse($memo->covers(\TYPO3\CMS\Core\Core\Environment::getPublicPath() . '/fileadmin/x.html'));
    }
}
