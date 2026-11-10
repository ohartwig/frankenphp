<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Fluid;

use Koh\FrankenPHP\Fluid\MemoizingTemplatePaths;
use Koh\FrankenPHP\Fluid\TemplatePathMemo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Fluid\View\TemplatePaths;
use TYPO3Fluid\Fluid\View\Exception\InvalidTemplateResourceException;

/**
 * Same answers as TYPO3's TemplatePaths; inside code directories a lookup
 * made once is not repeated on the filesystem, outside them it always is.
 */
final class MemoizingTemplatePathsTest extends TestCase
{
    private string $root;

    private string $code;

    private string $fileadmin;

    private TemplatePathMemo $memo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/koh-tpm-' . bin2hex(random_bytes(6));
        $this->code = $this->root . '/vendor/acme/site/Resources/Private';
        $this->fileadmin = $this->root . '/public/fileadmin/templates';
        foreach (['Templates/Page', 'Layouts', 'Partials/Header', 'Override/Partials'] as $dir) {
            mkdir($this->code . '/' . $dir, 0o777, true);
        }
        mkdir($this->fileadmin . '/Partials', 0o777, true);
        file_put_contents($this->code . '/Templates/Page/Default.html', 'page');
        file_put_contents($this->code . '/Layouts/Default.html', 'layout');
        file_put_contents($this->code . '/Partials/Header/All.html', 'header');
        file_put_contents($this->code . '/Partials/Footer.fluid.html', 'footer');
        file_put_contents($this->fileadmin . '/Partials/Teaser.html', 'teaser');
        $this->memo = new TemplatePathMemo([$this->root . '/vendor/']);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir((string) $file) : unlink((string) $file);
        }
        rmdir($this->root);
    }

    /**
     * @param list<string> $partialRoots
     */
    private function configure(TemplatePaths $paths, array $partialRoots): TemplatePaths
    {
        $paths->setTemplateRootPaths([$this->code . '/Templates']);
        $paths->setLayoutRootPaths([$this->code . '/Layouts']);
        $paths->setPartialRootPaths($partialRoots);

        return $paths;
    }

    private function memoizing(?array $partialRoots = null): MemoizingTemplatePaths
    {
        $paths = $this->configure(new MemoizingTemplatePaths($this->memo), $partialRoots ?? [$this->code . '/Partials', $this->code . '/Override/Partials']);
        self::assertInstanceOf(MemoizingTemplatePaths::class, $paths);

        return $paths;
    }

    private function core(?array $partialRoots = null): TemplatePaths
    {
        return $this->configure(new TemplatePaths(), $partialRoots ?? [$this->code . '/Partials', $this->code . '/Override/Partials']);
    }

    #[Test]
    public function itResolvesWhatTheCoreResolves(): void
    {
        $core = $this->core();
        $memoizing = $this->memoizing();

        self::assertSame($core->getTemplateRootPaths(), $memoizing->getTemplateRootPaths());
        self::assertSame($core->getPartialRootPaths(), $memoizing->getPartialRootPaths());
        self::assertSame(
            $core->resolveTemplateFileForControllerAndActionAndFormat('Page', 'Default'),
            $memoizing->resolveTemplateFileForControllerAndActionAndFormat('Page', 'Default'),
        );
        self::assertSame($core->getLayoutPathAndFilename('Default'), $memoizing->getLayoutPathAndFilename('Default'));
        self::assertSame($core->getPartialPathAndFilename('Header/All'), $memoizing->getPartialPathAndFilename('Header/All'));
        self::assertSame($core->getPartialPathAndFilename('Footer'), $memoizing->getPartialPathAndFilename('Footer'));
        self::assertSame($core->getPartialIdentifier('Footer'), $memoizing->getPartialIdentifier('Footer'));
        self::assertSame($core->getTemplateIdentifier('Page', 'Default'), $memoizing->getTemplateIdentifier('Page', 'Default'));
        self::assertSame($core->getLayoutIdentifier('Default'), $memoizing->getLayoutIdentifier('Default'));
    }

    #[Test]
    public function aLookupInCodeDirectoriesIsAnsweredFromTheMemoInTheNextRequest(): void
    {
        $first = $this->memoizing();
        $resolved = $first->getPartialPathAndFilename('Header/All');
        $identifier = $first->getPartialIdentifier('Header/All');

        // Gone from disk: a new TemplatePaths object (the next request) still
        // answers from the worker's memo, so no filesystem call was made.
        unlink($resolved);
        $next = $this->memoizing();

        self::assertSame($resolved, $next->getPartialPathAndFilename('Header/All'));
        self::assertSame($identifier, $next->getPartialIdentifier('Header/All'));
    }

    #[Test]
    public function aNegativeLookupThrowsTheCoresExceptionAgainWithoutTouchingTheDisk(): void
    {
        $coreError = null;
        try {
            $this->core()->getPartialPathAndFilename('Missing');
        } catch (InvalidTemplateResourceException $e) {
            $coreError = $e;
        }
        self::assertNotNull($coreError);

        $errors = [];
        foreach ([1, 2] as $request) {
            try {
                $this->memoizing()->getPartialPathAndFilename('Missing');
                self::fail('no exception in request ' . $request);
            } catch (InvalidTemplateResourceException $e) {
                $errors[] = $e;
            }
            // Created after the first lookup: the memo still says "missing".
            file_put_contents($this->code . '/Partials/Missing.html', 'x');
        }

        foreach ($errors as $error) {
            self::assertSame($coreError::class, $error::class);
            self::assertSame($coreError->getMessage(), $error->getMessage());
            self::assertSame($coreError->getCode(), $error->getCode());
        }
    }

    #[Test]
    public function lookupsOutsideCodeDirectoriesAreNeverRemembered(): void
    {
        $roots = [$this->fileadmin . '/Partials'];
        self::assertSame($this->fileadmin . '/Partials/Teaser.html', $this->memoizing($roots)->getPartialPathAndFilename('Teaser'));

        unlink($this->fileadmin . '/Partials/Teaser.html');
        file_put_contents($this->fileadmin . '/Partials/Teaser.fluid.html', 'new');

        self::assertSame($this->fileadmin . '/Partials/Teaser.fluid.html', $this->memoizing($roots)->getPartialPathAndFilename('Teaser'));
    }

    #[Test]
    public function aMixOfCodeAndOtherDirectoriesIsNotRemembered(): void
    {
        $roots = [$this->code . '/Partials', $this->fileadmin . '/Partials'];
        $this->memoizing($roots)->getPartialPathAndFilename('Footer');
        file_put_contents($this->fileadmin . '/Partials/Footer.html', 'override');

        self::assertSame($this->fileadmin . '/Partials/Footer.html', $this->memoizing($roots)->getPartialPathAndFilename('Footer'));
    }

    #[Test]
    public function aNameWithDotSegmentsIsNotRemembered(): void
    {
        $name = '../../../../../../public/fileadmin/templates/Partials/Teaser';
        $this->memoizing()->getPartialPathAndFilename($name);
        unlink($this->fileadmin . '/Partials/Teaser.html');

        $this->expectException(InvalidTemplateResourceException::class);
        $this->memoizing()->getPartialPathAndFilename($name);
    }

    #[Test]
    public function anExplicitTemplateFileIsCheckedOnceAndThenKnown(): void
    {
        $file = $this->code . '/Templates/Page/Default.html';
        $paths = $this->memoizing();
        $paths->setTemplatePathAndFilename($file);
        self::assertSame($file, $paths->resolveTemplateFileForControllerAndActionAndFormat('', '', null, true));

        unlink($file);
        $next = $this->memoizing();
        $next->setTemplatePathAndFilename($file);

        self::assertSame($file, $next->resolveTemplateFileForControllerAndActionAndFormat('', '', null, true));
    }

    #[Test]
    public function aMissingExplicitTemplateFileStillThrows(): void
    {
        $paths = $this->memoizing();
        $paths->setTemplatePathAndFilename($this->code . '/Templates/Page/Nope.html');

        $this->expectException(InvalidTemplateResourceException::class);
        $paths->resolveTemplateFileForControllerAndActionAndFormat('', '', null, true);
    }

    #[Test]
    public function theMemoStopsRecordingWhenFull(): void
    {
        $this->memo = new TemplatePathMemo([$this->root . '/vendor/'], 2);
        $paths = $this->memoizing();
        $paths->getPartialPathAndFilename('Footer');
        $paths->getPartialPathAndFilename('Header/All');

        self::assertSame(2, $this->memo->count());
    }
}
