<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Functional;

use Koh\FrankenPHP\Cache\VerifiedPayloadMemo;
use Koh\FrankenPHP\Cache\WorkerCacheMemo;
use Koh\FrankenPHP\Cache\WorkerMemoVariableFrontend;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * With KOH_WORKER_CACHE_MEMO=1, ext_localconf.php gives the core caches the
 * memo frontend, and a read through it on the real database backend answers
 * as the core frontend does, also after another process changed the entry.
 */
final class WorkerCacheMemoTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/frankenphp'];

    /** The testing framework puts caches on NullBackend; this test needs a real, shared one. */
    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'hash' => ['backend' => Typo3DatabaseBackend::class],
                ],
            ],
        ],
    ];

    protected function setUp(): void
    {
        putenv(WorkerCacheMemo::ENV . '=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv(WorkerCacheMemo::ENV);
        VerifiedPayloadMemo::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function hashAndRootlineGetTheMemoFrontend(): void
    {
        $configurations = $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'];
        self::assertSame(WorkerMemoVariableFrontend::class, $configurations['hash']['frontend']);
        self::assertSame(WorkerMemoVariableFrontend::class, $configurations['rootline']['frontend']);
        self::assertNotSame(WorkerMemoVariableFrontend::class, $configurations['pages']['frontend'] ?? VariableFrontend::class);

        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(WorkerMemoVariableFrontend::class, $cacheManager->getCache('hash'));
        self::assertInstanceOf(WorkerMemoVariableFrontend::class, $cacheManager->getCache('rootline'));
    }

    #[Test]
    public function readsOnTheDatabaseBackendFollowEveryChange(): void
    {
        $memo = new VerifiedPayloadMemo();
        VerifiedPayloadMemo::setInstance($memo);
        $cache = $this->get(CacheManager::class)->getCache('hash');
        $backend = $cache->getBackend();
        // Another pod: the core frontend on the same table.
        $otherPod = new VariableFrontend('hash', $backend);

        $otherPod->set('tree', ['v' => 1], ['pageId_1']);
        self::assertSame(['v' => 1], $cache->get('tree'));
        self::assertSame(['v' => 1], $cache->get('tree'));
        self::assertSame(1, $memo->stats()['hits']);

        $otherPod->set('tree', ['v' => 2], ['pageId_1']);
        self::assertSame(['v' => 2], $cache->get('tree'));

        $otherPod->flushByTag('pageId_1');
        self::assertFalse($cache->get('tree'));
        self::assertFalse($cache->has('tree'));
    }
}
