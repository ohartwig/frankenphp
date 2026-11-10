<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Fluid;

use Koh\FrankenPHP\Fluid\TemplatePathMemo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TemplatePathMemoTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(TemplatePathMemo::ENV);
        TemplatePathMemo::setInstance(null);
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function paths(): iterable
    {
        yield 'vendor file' => ['/app/vendor/acme/site/Resources/Private/Partials/A.html', true];
        yield 'package outside vendor' => ['/app/packages/site/Resources/Private/', true];
        yield 'fileadmin' => ['/app/public/fileadmin/templates/A.html', false];
        yield 'var' => ['/app/var/cache/x.html', false];
        yield 'dot dot' => ['/app/vendor/acme/../../public/fileadmin/A.html', false];
        yield 'dot' => ['/app/vendor/./acme/A.html', false];
        yield 'trailing dot dot' => ['/app/vendor/acme/..', false];
        yield 'relative' => ['vendor/acme/A.html', false];
        yield 'prefix only' => ['/app/vendorX/acme/A.html', false];
        yield 'empty' => ['', false];
        yield 'nul byte' => ["/app/vendor/a\0.html", false];
    }

    #[Test]
    #[DataProvider('paths')]
    public function onlyCodeDirectoriesAreCovered(string $path, bool $covered): void
    {
        $memo = new TemplatePathMemo(['relative/root', '/app/vendor', '/app/packages/site/', '/app/vendor/', '/app/x/../y']);

        self::assertSame($covered, $memo->covers($path));
        self::assertSame(['/app/vendor/', '/app/packages/site/'], $memo->codeRoots());
    }

    #[Test]
    public function aFileNameWithDotSegmentsIsNeverCovered(): void
    {
        $memo = new TemplatePathMemo(['/app/vendor/']);

        self::assertTrue($memo->coversAll(['/app/vendor/a/'], 'Header/All'));
        self::assertFalse($memo->coversAll(['/app/vendor/a/'], '../x'));
        self::assertFalse($memo->coversAll(['/app/vendor/a/'], 'a/../../x'));
        self::assertFalse($memo->coversAll([], 'x'));
        self::assertFalse($memo->coversAll(['/app/vendor/a/', '/app/public/b/'], 'x'));
    }

    #[Test]
    public function outsideAFrankenPhpWorkerItIsNeverActive(): void
    {
        putenv(TemplatePathMemo::ENV . '=1');
        $backup = $_SERVER['FRANKENPHP_WORKER'] ?? null;
        unset($_SERVER['FRANKENPHP_WORKER']);
        try {
            self::assertTrue(TemplatePathMemo::isEnabled());
            self::assertNull(TemplatePathMemo::active());
        } finally {
            if ($backup !== null) {
                $_SERVER['FRANKENPHP_WORKER'] = $backup;
            }
        }
    }

    #[Test]
    public function eachKindOfLookupIsRecordedOnceAndCountsAgainstTheBound(): void
    {
        $memo = new TemplatePathMemo(['/app/vendor/'], 4);
        $memo->rememberSanitized('EXT:a/', '/app/vendor/a/');
        $memo->rememberSanitized('EXT:a/', '/app/vendor/other/');
        $memo->rememberResolved('k', '/app/vendor/a/B.html');
        $memo->rememberResolved('k', ['/app/vendor/a/C.html']);
        $memo->rememberIdentifier('/app/vendor/a/B.html', 'template_B');
        $memo->rememberIdentifier('/app/vendor/a/B.html', 'template_X');
        $memo->rememberExisting('/app/vendor/a/B.html');
        $memo->rememberExisting('/app/vendor/a/B.html');

        self::assertSame(4, $memo->count());
        self::assertSame('/app/vendor/a/', $memo->sanitized('EXT:a/'));
        self::assertSame('/app/vendor/a/B.html', $memo->resolved('k'));
        self::assertSame('template_B', $memo->identifier('/app/vendor/a/B.html'));
        self::assertTrue($memo->isKnownToExist('/app/vendor/a/B.html'));
        self::assertFalse($memo->isKnownToExist('/app/vendor/a/C.html'));

        $memo->rememberSanitized('EXT:b/', '/app/vendor/b/');
        $memo->rememberResolved('l', '/app/vendor/b/B.html');
        $memo->rememberIdentifier('/app/vendor/b/B.html', 'template_b');
        $memo->rememberExisting('/app/vendor/b/B.html');
        self::assertSame(4, $memo->count(), 'full: nothing more is recorded');
        self::assertNull($memo->sanitized('EXT:b/'));
        self::assertNull($memo->resolved('l'));
        self::assertNull($memo->identifier('/app/vendor/b/B.html'));
        self::assertFalse($memo->isKnownToExist('/app/vendor/b/B.html'));
    }

    #[Test]
    public function aFileNameWhoseOnlyDotSegmentIsAtTheEndIsNotCovered(): void
    {
        $memo = new TemplatePathMemo(['/app/vendor/']);

        self::assertFalse($memo->coversAll(['/app/vendor/a/'], 'x/..'));
        self::assertFalse($memo->coversAll(['/app/vendor/a/'], '.'));
    }

    #[Test]
    public function aSetInstanceIsTheActiveOne(): void
    {
        $memo = new TemplatePathMemo(['/app/vendor/']);
        TemplatePathMemo::setInstance($memo);

        self::assertSame($memo, TemplatePathMemo::active());
    }

    #[Test]
    public function inAWorkerWithTheSwitchOnTheMemoIsActive(): void
    {
        require_once __DIR__ . '/../../Fixtures/frankenphp_handle_request.php';
        $backup = $_SERVER['FRANKENPHP_WORKER'] ?? null;
        $_SERVER['FRANKENPHP_WORKER'] = '1';
        try {
            self::assertNull(TemplatePathMemo::active(), 'switch off');
            putenv(TemplatePathMemo::ENV . '=1');
            TemplatePathMemo::setInstance(new TemplatePathMemo(['/app/vendor/']));
            self::assertNotNull(TemplatePathMemo::active());
        } finally {
            if ($backup === null) {
                unset($_SERVER['FRANKENPHP_WORKER']);
            } else {
                $_SERVER['FRANKENPHP_WORKER'] = $backup;
            }
        }
    }

    #[Test]
    public function offByDefault(): void
    {
        self::assertFalse(TemplatePathMemo::isEnabled());
        self::assertNull(TemplatePathMemo::active());
    }

    #[Test]
    public function fluidOfThisInstallationIsSupported(): void
    {
        self::assertTrue(TemplatePathMemo::isSupportedFluid());
    }
}
