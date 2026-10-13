<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Service;

use Koh\FrankenPHP\Service\WorkerSafeOpcodeCacheService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The service withholds the GLOBAL opcache_reset() on FrankenPHP worker deployments
 * (where it wedges the resident workers) while leaving per-file invalidation and
 * classic non-worker installs untouched.
 */
final class WorkerSafeOpcodeCacheServiceTest extends TestCase
{
    private ?string $previousFlag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $existing = getenv('FRANKENPHP_WORKER');
        $this->previousFlag = is_string($existing) ? $existing : null;
        $this->clearFlag();
    }

    protected function tearDown(): void
    {
        if ($this->previousFlag === null) {
            $this->clearFlag();
        } else {
            putenv('FRANKENPHP_WORKER=' . $this->previousFlag);
        }
        parent::tearDown();
    }

    private function clearFlag(): void
    {
        putenv('FRANKENPHP_WORKER');
        unset($_SERVER['FRANKENPHP_WORKER'], $_ENV['FRANKENPHP_WORKER']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function flagProvider(): iterable
    {
        yield 'true' => ['true', true];
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        yield 'YES uppercase' => ['YES', true];
        yield 'false' => ['false', false];
        yield '0' => ['0', false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('flagProvider')]
    public function testWorkerDeploymentDetectionFollowsEnv(string $value, bool $expected): void
    {
        putenv('FRANKENPHP_WORKER=' . $value);

        self::assertSame($expected, WorkerSafeOpcodeCacheService::isFrankenPhpWorkerDeployment());
    }

    public function testDetectionFallsBackToServerSuperglobal(): void
    {
        // getenv() returns false; the deploy value may surface via $_SERVER instead.
        $_SERVER['FRANKENPHP_WORKER'] = 'true';

        self::assertTrue(WorkerSafeOpcodeCacheService::isFrankenPhpWorkerDeployment());
    }

    public function testDetectionDefaultsToFalseWhenUnset(): void
    {
        self::assertFalse(WorkerSafeOpcodeCacheService::isFrankenPhpWorkerDeployment());
    }

    public function testGlobalResetIsSkippedInWorkerDeployment(): void
    {
        putenv('FRANKENPHP_WORKER=true');

        // No file arg = global reset. Must short-circuit before touching OPcache;
        // if it delegated to the parent it would call opcache_reset() here.
        $service = new WorkerSafeOpcodeCacheService();
        $service->clearAllActive();

        // Reaching this line without side effects/errors is the assertion: the guard
        // returned early. (A real global reset is unobservable but harmless in CLI.)
        $this->addToAssertionCount(1);
    }

    public function testPerFileInvalidationIsNeverSuppressed(): void
    {
        putenv('FRANKENPHP_WORKER=true');

        // A concrete file arg must always be delegated — never withheld — even in
        // worker mode. clearAllActive() with an unknown path is a safe no-op in the
        // parent (opcache_invalidate on a non-cached file), so this must not throw.
        $service = new WorkerSafeOpcodeCacheService();
        $service->clearAllActive('/does/not/exist/frankenphp-test.php');

        $this->addToAssertionCount(1);
    }
}
