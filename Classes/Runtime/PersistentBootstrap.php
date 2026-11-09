<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Clock\RequestClock;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Boots TYPO3 once per worker and gives every later request a container in
 * the state the boot left it in (KOH_PERSISTENT_BOOTSTRAP=1).
 *
 * What a boot builds splits into two kinds. Configuration: the compiled
 * container, TYPO3_CONF_VARS, TCA, the package manager, what ext_localconf.php
 * registered in services (renderer and meta tag registries, the TCA schema).
 * That is the same for every request and is what makes the boot expensive.
 * Request state: every service a request instantiates (Context, PageRenderer,
 * AssetCollector, the caches, the Extbase persistence session, ...), the
 * request id with its CSP nonce, the clock, the time globals. That must not
 * survive the request.
 *
 * So right after the boot this class records what exists at that point:
 *
 * - the services the container holds (`services` and `privates`), which are
 *   the externally set ones from Bootstrap::init() (`_early.*`, the service
 *   provider registry) plus what the boot itself instantiated;
 * - GeneralUtility's singleton instances;
 * - the globals, minus the superglobals.
 *
 * Before each later request it puts exactly that back: Container::reset()
 * drops every service instantiated since (calling reset() on ResetInterface
 * services), the recorded instances are put back, and RequestId, RequestClock
 * and LogManager are replaced with new ones, as Bootstrap::init() creates
 * them for each request. The globals are restored, globals the previous
 * request added are removed, and SystemEnvironmentBuilder::run() sets the time
 * globals again. The error handling and the time zone are set up again because
 * Bootstrap::init() decides them per request (displayErrors -1 depends on the
 * client address).
 *
 * Not covered: static properties of classes. Nothing resets them, in this
 * mode or with a bootstrap per request; MAX_REQUESTS bounds how long they
 * grow.
 *
 * A failsafe boot (no usable configuration) is never kept.
 *
 * @internal
 */
final class PersistentBootstrap
{
    public const ENV = 'KOH_PERSISTENT_BOOTSTRAP';

    /** Superglobals FrankenPHP fills for each request; never recorded or restored. */
    private const SUPERGLOBALS = ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION', 'argv', 'argc'];

    private ?Container $container = null;

    /** @var array<string, mixed> */
    private array $bootServices = [];

    /** @var array<string, mixed> */
    private array $bootPrivates = [];

    /** @var array<class-string, SingletonInterface> */
    private array $bootSingletons = [];

    /** @var array<string, mixed> */
    private array $bootGlobals = [];

    /** @var \Closure(ClassLoader): ContainerInterface */
    private \Closure $boot;

    /** @var \Closure(): void */
    private \Closure $refreshEnvironment;

    /**
     * @param (\Closure(ClassLoader): ContainerInterface)|null $boot               for tests: instead of SystemEnvironmentBuilder::run() and Bootstrap::init()
     * @param (\Closure(): void)|null                          $refreshEnvironment for tests: instead of what is set up again per request (time globals, error handling, time zone)
     */
    public function __construct(?\Closure $boot = null, ?\Closure $refreshEnvironment = null)
    {
        $this->boot = $boot ?? static function (ClassLoader $classLoader): ContainerInterface {
            SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_FE);

            return Bootstrap::init($classLoader);
        };
        $this->refreshEnvironment = $refreshEnvironment ?? static function (): void {
            // EXEC_TIME and friends, T3_SERVICES, Environment: as for a fresh boot.
            SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_FE);
            Bootstrap::startOutputBuffering();
            \Closure::bind(
                static function (): void {
                    static::initializeErrorHandling();
                    static::setDefaultTimezone();
                },
                null,
                Bootstrap::class,
            )();
        };
    }

    /**
     * @param array<array-key, mixed> $environment as for DatabaseConnectionReuse::fromEnvironment()
     */
    public static function isEnabled(array $environment): bool
    {
        $value = $environment[self::ENV] ?? null;
        if (!is_scalar($value) || '' === $value) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    /**
     * The container for this request: booted on the first call, prepared from
     * the recorded boot state on every later one.
     *
     * @return array{0: ContainerInterface, 1: bool} the container, and whether it was kept from an earlier request
     */
    public function container(ClassLoader $classLoader): array
    {
        $kept = $this->container;
        if ($kept !== null) {
            $this->prepareRequest($kept);

            return [$kept, true];
        }

        $container = ($this->boot)($classLoader);
        if ($container instanceof Container && $this->bootCompleted($container)) {
            $this->record($container);
        }

        return [$container, false];
    }

    public function isBooted(): bool
    {
        return $this->container !== null;
    }

    /**
     * After a request: drop its services now, so an idle worker does not hold
     * the last page's objects.
     */
    public function afterRequest(): void
    {
        if ($this->container !== null) {
            $this->resetContainer();
        }
    }

    /**
     * @internal public for tests: takes the boot state from a booted container
     */
    public function record(Container $container): void
    {
        [$this->bootServices, $this->bootPrivates] = \Closure::bind(
            fn(): array => [$this->services, $this->privates],
            $container,
            Container::class,
        )();
        $this->bootSingletons = \Closure::bind(
            static fn(): array => static::$singletonInstances,
            null,
            GeneralUtility::class,
        )();
        $this->bootGlobals = array_diff_key($GLOBALS, array_flip(self::SUPERGLOBALS));
        $this->container = $container;
    }

    /**
     * @internal public for tests: the container back in its recorded state,
     * with a new request id, clock and log manager
     */
    public function resetContainer(): void
    {
        $container = $this->container;
        if ($container === null) {
            return;
        }
        $container->reset();

        $services = $this->bootServices;
        $requestId = new RequestId();
        $services['_early.' . RequestId::class] = $requestId;
        $services['_early.' . RequestClock::class] = RequestClock::create();
        $services['_early.' . LogManager::class] = new LogManager((string) $requestId);
        $privates = $this->bootPrivates;

        \Closure::bind(
            function () use ($services, $privates): void {
                $this->services = $services;
                $this->privates = $privates;
            },
            $container,
            Container::class,
        )();
    }

    private function prepareRequest(Container $container): void
    {
        $this->restoreGlobals();
        ($this->refreshEnvironment)();
        $this->resetContainer();
        // RequestStateReset::resetRequestState() emptied both.
        GeneralUtility::setContainer($container);
        GeneralUtility::resetSingletonInstances($this->bootSingletons);
    }

    private function restoreGlobals(): void
    {
        foreach (array_keys($GLOBALS) as $name) {
            if (!array_key_exists($name, $this->bootGlobals) && !in_array($name, self::SUPERGLOBALS, true)) {
                unset($GLOBALS[$name]);
            }
        }
        foreach ($this->bootGlobals as $name => $value) {
            $GLOBALS[$name] = $value;
        }
    }

    private function bootCompleted(Container $container): bool
    {
        if (!$container->has('boot.state')) {
            return false;
        }
        $state = $container->get('boot.state');

        return ($state->complete ?? false) === true && ($state->cacheDisabled ?? true) === false;
    }
}
