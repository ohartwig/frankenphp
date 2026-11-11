<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use Composer\Autoload\ClassLoader;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Clock\RequestClock;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\RequestId;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
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
 * So right after a boot this class records what exists at that point. It
 * keeps the second boot of the worker, not the first: a boot from cold
 * caches (after a deployment or a cache flush) also instantiates request
 * services while it builds TCA and the configuration, and those must not
 * become boot state. The first request warms the caches with a boot of its
 * own, which is then dropped. What it records:
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
 * Keeping an object also keeps what a request writes into it. So the property
 * values of every kept object (services, singleton instances, objects in
 * globals) are recorded as well, and put back before each request
 * (KeptObjectState): MetaTagManagerRegistry, for one, creates its managers on
 * first use and keeps them, with the previous page's description and Open
 * Graph tags, in the boot's instance.
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

    /**
     * Diagnostics: log the kept objects once, and after every request what it
     * changed in them before that is undone (koh_worker_kept and
     * koh_worker_drift JSON lines). For finding request state in boot
     * objects; costs a comparison of every kept property per request.
     */
    public const AUDIT_ENV = 'KOH_PERSISTENT_BOOTSTRAP_AUDIT';

    /** How far the audit follows kept objects' properties into further objects. */
    private const AUDIT_DEPTH = 4;

    /**
     * Classes of one request's state. A kept object that points to one of
     * them was given request state by the boot; the audit reports it.
     */
    private const REQUEST_STATE_CLASSES = [
        Context::class,
        \Psr\Http\Message\ServerRequestInterface::class,
        \TYPO3\CMS\Core\Site\Entity\SiteInterface::class,
        \TYPO3\CMS\Core\Site\Entity\SiteLanguage::class,
        \TYPO3\CMS\Core\Localization\LanguageService::class,
        \TYPO3\CMS\Frontend\Page\PageInformation::class,
        \TYPO3\CMS\Core\TypoScript\FrontendTypoScript::class,
        \TYPO3\CMS\Core\Page\PageRenderer::class,
        \TYPO3\CMS\Core\Page\AssetCollector::class,
        \TYPO3\CMS\Core\Authentication\AbstractUserAuthentication::class,
    ];

    /** The boot whose state is kept: the first one that could run on warm caches. */
    private const BOOTS_BEFORE_KEEPING = 2;

    /**
     * Request services never kept, even if a boot instantiated them: a runtime
     * cache or CacheManager carried into the next request would hand it the
     * previous request's runtime entries.
     */
    private const NEVER_KEPT = [
        'cache.runtime',
        CacheManager::class,
        Context::class,
        FlashMessageService::class,
    ];

    /** Superglobals FrankenPHP fills for each request; never recorded or restored. */
    private const SUPERGLOBALS = ['GLOBALS', '_GET', '_POST', '_COOKIE', '_FILES', '_SERVER', '_ENV', '_REQUEST', '_SESSION', 'argv', 'argc'];

    private ?Container $container = null;

    /** Boots so far in this worker. */
    private int $boots = 0;

    /**
     * The globals that existed right after the first boot. The kept boot
     * runs after a request, so only these are recorded: what that request
     * added to the globals is not boot state.
     *
     * @var array<string, true>|null
     */
    private ?array $firstBootGlobals = null;

    /** @var array<string, mixed> */
    private array $bootServices = [];

    /** @var array<string, mixed> */
    private array $bootPrivates = [];

    /** @var array<class-string, SingletonInterface> */
    private array $bootSingletons = [];

    /** @var array<string, mixed> */
    private array $bootGlobals = [];

    /** The property values of every kept object, as the boot left them. */
    private ?KeptObjectState $keptState = null;

    /** Whether to log what requests change in kept objects (AUDIT_ENV). */
    private bool $audit;

    /** Whether the boot builds and keeps the configuration services (KeptConfigurationServices::ENV). */
    private bool $keepConfigServices;

    /** The kept configuration services, checked and put back before each request. */
    private ?KeptConfigurationServices $configServices = null;

    /** Audit only: the kept objects and what they point to, compared after each request. */
    private ?KeptObjectState $auditState = null;

    /**
     * Audit only: static properties when the request started.
     *
     * @var array<string, mixed>|null
     */
    private ?array $auditStatics = null;

    /** @var \Closure(ClassLoader): ContainerInterface */
    private \Closure $boot;

    /** @var \Closure(): void */
    private \Closure $refreshEnvironment;

    /**
     * @param (\Closure(ClassLoader): ContainerInterface)|null $boot               for tests: instead of SystemEnvironmentBuilder::run() and Bootstrap::init()
     * @param (\Closure(): void)|null                          $refreshEnvironment for tests: instead of what is set up again per request (time globals, error handling, time zone)
     * @param bool|null                                        $keepConfigServices for tests: instead of KOH_PERSISTENT_CONFIG_SERVICES
     */
    public function __construct(?\Closure $boot = null, ?\Closure $refreshEnvironment = null, ?bool $audit = null, ?bool $keepConfigServices = null)
    {
        $this->audit = $audit ?? WorkerSwitch::isOn(self::AUDIT_ENV);
        $this->keepConfigServices = $keepConfigServices ?? KeptConfigurationServices::isEnabled();
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

        if ($this->firstBootGlobals !== null) {
            // Booting again after a request: without the globals it added.
            foreach (array_keys($GLOBALS) as $name) {
                if (!isset($this->firstBootGlobals[$name]) && !in_array($name, self::SUPERGLOBALS, true)) {
                    unset($GLOBALS[$name]);
                }
            }
        }
        $container = ($this->boot)($classLoader);
        ++$this->boots;
        $this->firstBootGlobals ??= array_fill_keys(array_keys(array_diff_key($GLOBALS, array_flip(self::SUPERGLOBALS))), true);
        // The first boot of a worker may find TYPO3's caches cold (after a
        // deployment, a cache flush). Building TCA and the configuration then
        // instantiates request services as well (the runtime cache, the
        // CacheManager, form framework services, ...), and they would be
        // recorded as boot state. That boot serves its request and is
        // dropped; the second boot runs on the caches the first one warmed.
        if ($this->boots >= self::BOOTS_BEFORE_KEEPING && $container instanceof Container && $this->bootCompleted($container)) {
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
            if ($this->auditState !== null) {
                $drift = $this->auditState->drift();
                $statics = [];
                if ($this->auditStatics !== null) {
                    foreach (KeptObjectState::statics() as $name => $value) {
                        if (array_key_exists($name, $this->auditStatics) && $this->auditStatics[$name] !== $value) {
                            $statics[] = $name;
                        }
                    }
                }
                if ($drift !== [] || $statics !== []) {
                    error_log((string) json_encode(['koh_worker_drift' => count($drift), 'objects' => $drift, 'statics' => $statics], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                }
            }
            $this->resetContainer();
        }
    }

    /**
     * @internal public for tests: takes the boot state from a booted container
     */
    public function record(Container $container): void
    {
        if ($this->keepConfigServices) {
            // Built now, they are part of the boot state recorded below.
            KeptConfigurationServices::instantiate($container);
        }
        [$this->bootServices, $this->bootPrivates] = \Closure::bind(
            fn(): array => [$this->services, $this->privates],
            $container,
            Container::class,
        )();
        $neverKept = array_flip(self::NEVER_KEPT);
        $this->bootServices = array_diff_key($this->bootServices, $neverKept);
        $this->bootPrivates = array_diff_key($this->bootPrivates, $neverKept);
        $this->bootSingletons = \Closure::bind(
            static fn(): array => static::$singletonInstances,
            null,
            GeneralUtility::class,
        )();
        $this->bootGlobals = array_diff_key($GLOBALS, array_flip(self::SUPERGLOBALS));
        if ($this->firstBootGlobals !== null) {
            $this->bootGlobals = array_intersect_key($this->bootGlobals, $this->firstBootGlobals);
        }
        $this->keptState = KeptObjectState::capture($this->keptObjects(), [ClassLoader::class]);
        $this->configServices = $this->keepConfigServices ? KeptConfigurationServices::capture($container) : null;
        $this->container = $container;
        if ($this->audit) {
            $this->auditState = KeptObjectState::capture($this->keptObjects(), [ClassLoader::class], self::AUDIT_DEPTH);
            error_log((string) json_encode([
                'koh_worker_kept' => $this->keptState->count(),
                'observed' => $this->auditState->count(),
                'request_references' => $this->keptState->requestReferences(self::REQUEST_STATE_CLASSES),
                'objects' => $this->keptState->objects(),
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    /**
     * Every object the next request gets from the boot: services, singleton
     * instances, objects in globals.
     *
     * @return \Generator<string, mixed>
     */
    private function keptObjects(): \Generator
    {
        foreach ($this->bootServices as $id => $service) {
            yield 'service:' . $id => $service;
        }
        foreach ($this->bootPrivates as $id => $service) {
            yield 'private:' . $id => $service;
        }
        foreach ($this->bootSingletons as $class => $instance) {
            yield 'singleton:' . $class => $instance;
        }
        foreach ($this->bootGlobals as $name => $value) {
            yield 'global:' . $name => $value;
        }
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
        // What the request wrote into kept objects: undone, so the next one
        // finds them as a fresh boot would leave them.
        $this->keptState?->restore();
        // The configuration services hold state KeptObjectState cannot put
        // back; a request that changed them is reported.
        $drift = $this->configServices?->verify() ?? [];
        if ($drift !== []) {
            error_log((string) json_encode(['koh_worker_drift' => count($drift), 'objects' => $drift, 'statics' => [], 'restored' => true], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
    }

    private function prepareRequest(Container $container): void
    {
        $this->restoreGlobals();
        ($this->refreshEnvironment)();
        $this->resetContainer();
        // RequestStateReset::resetRequestState() emptied both.
        GeneralUtility::setContainer($container);
        GeneralUtility::resetSingletonInstances($this->bootSingletons);
        if ($this->auditState !== null) {
            $this->auditStatics = KeptObjectState::statics();
        }
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
