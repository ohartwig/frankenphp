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
        $memo = new TemplatePathMemo(['/app/vendor', '/app/packages/site/', 'relative/root', '/app/x/../y']);

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
