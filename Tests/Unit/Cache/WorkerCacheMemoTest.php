<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Cache;

use Koh\FrankenPHP\Cache\VerifiedPayloadMemo;
use Koh\FrankenPHP\Cache\WorkerCacheMemo;
use Koh\FrankenPHP\Cache\WorkerMemoVariableFrontend;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

final class WorkerCacheMemoTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(WorkerCacheMemo::ENV);
        putenv(WorkerCacheMemo::ENV_CACHES);
        putenv(WorkerCacheMemo::ENV_MAX_BYTES);
        VerifiedPayloadMemo::setInstance(null);
    }

    #[Test]
    public function offChangesNothing(): void
    {
        $configurations = ['hash' => ['frontend' => VariableFrontend::class]];

        self::assertSame([], WorkerCacheMemo::configure($configurations, false, ['hash']));
        self::assertSame(VariableFrontend::class, $configurations['hash']['frontend']);
    }

    #[Test]
    public function onlyListedCachesWithThePlainVariableFrontendAreSwitched(): void
    {
        $configurations = [
            'hash' => ['frontend' => VariableFrontend::class, 'backend' => 'B', 'options' => ['database' => 4]],
            'rootline' => ['backend' => 'B'],
            'typoscript' => ['frontend' => PhpFrontend::class],
            'custom' => ['frontend' => 'Vendor\\OwnFrontend'],
            'pages' => ['frontend' => VariableFrontend::class],
        ];

        $switched = WorkerCacheMemo::configure($configurations, true, ['hash', 'rootline', 'typoscript', 'custom', 'missing']);

        self::assertSame(['hash', 'rootline'], $switched);
        self::assertSame(WorkerMemoVariableFrontend::class, $configurations['hash']['frontend']);
        self::assertSame(['database' => 4], $configurations['hash']['options'], 'backend and options stay');
        self::assertSame(WorkerMemoVariableFrontend::class, $configurations['rootline']['frontend']);
        self::assertSame(PhpFrontend::class, $configurations['typoscript']['frontend']);
        self::assertSame('Vendor\\OwnFrontend', $configurations['custom']['frontend']);
        self::assertSame(VariableFrontend::class, $configurations['pages']['frontend'], 'not listed');
        self::assertArrayNotHasKey('missing', $configurations, 'a cache is never created by this');
    }

    #[Test]
    public function theCacheListComesFromTheEnvironment(): void
    {
        self::assertSame(WorkerCacheMemo::DEFAULT_CACHES, WorkerCacheMemo::cacheIdentifiers());
        putenv(WorkerCacheMemo::ENV_CACHES . '= hash, ,rootline,hash');
        self::assertSame(['hash', 'rootline'], WorkerCacheMemo::cacheIdentifiers());
    }

    #[Test]
    public function theSwitchAndTheBudgetComeFromTheEnvironment(): void
    {
        self::assertFalse(WorkerCacheMemo::isEnabled());
        self::assertSame(VerifiedPayloadMemo::DEFAULT_MAX_BYTES, WorkerCacheMemo::maxBytes());
        putenv(WorkerCacheMemo::ENV . '=1');
        putenv(WorkerCacheMemo::ENV_MAX_BYTES . '=1048576');
        self::assertTrue(WorkerCacheMemo::isEnabled());
        self::assertSame(1048576, WorkerCacheMemo::maxBytes());
        putenv(WorkerCacheMemo::ENV_MAX_BYTES . '=-5');
        self::assertSame(VerifiedPayloadMemo::DEFAULT_MAX_BYTES, WorkerCacheMemo::maxBytes());
    }

    #[Test]
    public function outsideAFrankenPhpWorkerTheMemoIsNeverActive(): void
    {
        putenv(WorkerCacheMemo::ENV . '=1');
        $backup = $_SERVER['FRANKENPHP_WORKER'] ?? null;
        unset($_SERVER['FRANKENPHP_WORKER']);
        try {
            self::assertFalse(WorkerCacheMemo::isActive());
            self::assertNull(VerifiedPayloadMemo::active());
        } finally {
            if ($backup !== null) {
                $_SERVER['FRANKENPHP_WORKER'] = $backup;
            }
        }
    }
}
