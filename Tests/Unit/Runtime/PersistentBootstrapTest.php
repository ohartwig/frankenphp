<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\PersistentBootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Contracts\Service\ResetInterface;
use TYPO3\CMS\Core\Clock\RequestClock;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * The switch, and the container reset that makes a kept boot safe: what the
 * boot created stays, what a request created goes, and the request id, clock
 * and log manager are new for every request.
 */
final class PersistentBootstrapTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function environments(): iterable
    {
        yield 'unset' => [[], false];
        yield 'empty' => [[PersistentBootstrap::ENV => ''], false];
        yield 'zero' => [[PersistentBootstrap::ENV => '0'], false];
        yield 'one' => [[PersistentBootstrap::ENV => '1'], true];
        yield 'true' => [[PersistentBootstrap::ENV => 'true'], true];
        yield 'on' => [[PersistentBootstrap::ENV => 'on'], true];
        yield 'garbage' => [[PersistentBootstrap::ENV => 'maybe'], false];
        yield 'array' => [[PersistentBootstrap::ENV => ['1']], false];
    }

    /**
     * @param array<string, mixed> $environment
     */
    #[Test]
    #[DataProvider('environments')]
    public function isOffUnlessSwitchedOnExplicitly(array $environment, bool $enabled): void
    {
        self::assertSame($enabled, PersistentBootstrap::isEnabled($environment));
    }

    #[Test]
    public function resetKeepsTheBootServicesAndDropsTheRequestServices(): void
    {
        $container = new Container();
        $registry = new \stdClass();
        $earlyId = new RequestId();
        $container->set('boot.registry', $registry);
        $container->set('_early.' . RequestId::class, $earlyId);
        $container->set('_early.' . RequestClock::class, new RequestClock());
        $container->set('_early.' . LogManager::class, new LogManager('boot'));

        $bootstrap = new PersistentBootstrap();
        $bootstrap->record($container);

        $requestService = new class implements ResetInterface {
            public bool $wasReset = false;

            public function reset(): void
            {
                $this->wasReset = true;
            }
        };
        $container->set('request.service', $requestService);

        $bootstrap->resetContainer();

        self::assertSame($registry, $container->get('boot.registry'));
        self::assertFalse($container->has('request.service'));
        self::assertTrue($requestService->wasReset);
        $requestId = $container->get('_early.' . RequestId::class);
        self::assertInstanceOf(RequestId::class, $requestId);
        self::assertNotSame($earlyId, $requestId);
        self::assertNotSame($earlyId->nonce, $requestId->nonce);
        self::assertInstanceOf(RequestClock::class, $container->get('_early.' . RequestClock::class));
        self::assertInstanceOf(LogManager::class, $container->get('_early.' . LogManager::class));
    }

    #[Test]
    public function everyResetHandsOutANewRequestId(): void
    {
        $container = new Container();
        $bootstrap = new PersistentBootstrap();
        $bootstrap->record($container);

        $bootstrap->resetContainer();
        $first = $container->get('_early.' . RequestId::class);
        $bootstrap->resetContainer();

        self::assertNotSame($first, $container->get('_early.' . RequestId::class));
    }

    #[Test]
    public function nothingIsKeptBeforeABoot(): void
    {
        $bootstrap = new PersistentBootstrap();

        self::assertFalse($bootstrap->isBooted());
        $bootstrap->afterRequest();
        self::assertFalse($bootstrap->isBooted());
    }

    /**
     * A boot from cold caches instantiates request services as well; the
     * runtime cache and the CacheManager are never carried into a request.
     */
    #[Test]
    public function requestCachesAreNeverKeptEvenIfTheBootCreatedThem(): void
    {
        $container = new Container();
        $container->set('cache.runtime', new \stdClass());
        $container->set(\TYPO3\CMS\Core\Cache\CacheManager::class, new \stdClass());
        $container->set('boot.registry', new \stdClass());
        $bootstrap = new PersistentBootstrap();
        $bootstrap->record($container);

        $bootstrap->resetContainer();

        self::assertFalse($container->initialized('cache.runtime'));
        self::assertFalse($container->initialized(\TYPO3\CMS\Core\Cache\CacheManager::class));
        self::assertTrue($container->initialized('boot.registry'));
    }
}
