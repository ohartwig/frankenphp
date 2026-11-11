<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\KeptConfigurationServices;
use Koh\FrankenPHP\Runtime\PersistentBootstrap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Type\Map;

/**
 * KOH_PERSISTENT_CONFIG_SERVICES: the three configuration services are built
 * with the boot and kept, and what a request changes in them is put back
 * before the next one, also where KeptObjectState cannot reach (the items of
 * an ArrayObject, the maps inside the policy map).
 */
final class KeptConfigurationServicesTest extends TestCase
{
    private ?string $log = null;

    private string|false $previousLog = false;

    protected function tearDown(): void
    {
        if ($this->log !== null) {
            ini_set('error_log', $this->previousLog === false ? '' : $this->previousLog);
            @unlink($this->log);
            $this->log = null;
        }
    }

    #[Test]
    public function withTheSwitchOnTheBootBuildsAndKeepsThem(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: true);
        $bootstrap->record($container);
        $namespaces = $container->get('fluid.namespaces');

        $bootstrap->resetContainer();

        foreach (KeptConfigurationServices::IDS as $id) {
            self::assertTrue($container->initialized($id), $id . ' is part of the boot state');
        }
        self::assertSame($namespaces, $container->get('fluid.namespaces'));
    }

    #[Test]
    public function withTheSwitchOffEachRequestBuildsThemAgain(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: false);
        $bootstrap->record($container);
        $first = $container->get('fluid.namespaces');

        $bootstrap->resetContainer();

        self::assertFalse($container->initialized('fluid.namespaces'));
        self::assertNotSame($first, $container->get('fluid.namespaces'));
    }

    #[Test]
    public function aRequestThatChangedTheFluidNamespacesDoesNotHandItToTheNext(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: true);
        $bootstrap->record($container);
        $namespaces = $container->get('fluid.namespaces');
        self::assertInstanceOf(\ArrayObject::class, $namespaces);
        $collections = $container->get('fluid.component.collections');
        self::assertInstanceOf(\ArrayObject::class, $collections);
        $this->captureLog();

        $namespaces['leak'] = ['Vendor\\Leak\\ViewHelpers'];
        $namespaces['f'][] = 'Vendor\\Override\\ViewHelpers';
        $collections->exchangeArray([]);
        $bootstrap->afterRequest();

        self::assertSame($namespaces, $container->get('fluid.namespaces'), 'the same object, put back in place');
        self::assertSame(['f' => ['TYPO3\\CMS\\Fluid\\ViewHelpers']], $namespaces->getArrayCopy());
        self::assertSame(['Vendor\\Components' => ['templatePaths' => ['EXT:vendor/Components/']]], $collections->getArrayCopy());
        $lines = $this->loggedLines();
        self::assertStringContainsString('"koh_worker_drift":2', $lines);
        self::assertStringContainsString('"label":"service:fluid.namespaces"', $lines);
        self::assertStringContainsString('"label":"service:fluid.component.collections"', $lines);
    }

    #[Test]
    public function aRequestThatChangedAPolicyInsideTheMapDoesNotHandItToTheNext(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: true);
        $bootstrap->record($container);
        $policies = $container->get('content.security.policies');
        self::assertInstanceOf(Map::class, $policies);
        $frontend = $policies['frontend'];
        self::assertInstanceOf(Map::class, $frontend);
        $this->captureLog();

        // Deeper than KeptObjectState restores: a map the kept map holds.
        $frontend['leak'] = 'https://leak.example';
        $policies['backend'] = new Map();
        $bootstrap->afterRequest();

        self::assertSame($policies, $container->get('content.security.policies'));
        self::assertFalse(isset($policies['backend']));
        self::assertSame($frontend, $policies['frontend']);
        self::assertFalse(isset($frontend['leak']));
        self::assertSame('https://package.example', $frontend['package']);
        self::assertStringContainsString('"koh_worker_drift":1', $this->loggedLines());
        self::assertStringContainsString('"label":"service:content.security.policies->values[0]"', $this->loggedLines());
    }

    #[Test]
    public function readingThePoliciesIsNoDrift(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: true);
        $bootstrap->record($container);
        $policies = $container->get('content.security.policies');
        self::assertInstanceOf(Map::class, $policies);
        $this->captureLog();

        // What MutationRepository does with them on every request.
        foreach ($policies as $scope => $mutations) {
            self::assertInstanceOf(Map::class, $mutations);
            foreach ($mutations as $mutation) {
                self::assertIsString($mutation);
            }
        }
        $bootstrap->afterRequest();

        self::assertSame('', $this->loggedLines());
    }

    #[Test]
    public function anUnchangedRequestLogsNothing(): void
    {
        $container = self::container();
        $bootstrap = new PersistentBootstrap(audit: false, keepConfigServices: true);
        $bootstrap->record($container);
        $this->captureLog();

        $bootstrap->afterRequest();
        $bootstrap->resetContainer();

        self::assertSame('', $this->loggedLines());
    }

    /**
     * A container that builds the three services on first use, as TYPO3's
     * compiled container does through the service providers.
     */
    private static function container(): Container
    {
        return new class extends Container {
            public function __construct()
            {
                parent::__construct();
                $this->methodMap = [
                    'fluid.namespaces' => 'buildNamespaces',
                    'fluid.component.collections' => 'buildCollections',
                    'content.security.policies' => 'buildPolicies',
                ];
            }

            /** @return \ArrayObject<string, mixed> */
            protected function buildNamespaces(): \ArrayObject
            {
                return $this->services['fluid.namespaces'] = new \ArrayObject(['f' => ['TYPO3\\CMS\\Fluid\\ViewHelpers']]);
            }

            /** @return \ArrayObject<string, mixed> */
            protected function buildCollections(): \ArrayObject
            {
                return $this->services['fluid.component.collections'] = new \ArrayObject(['Vendor\\Components' => ['templatePaths' => ['EXT:vendor/Components/']]]);
            }

            protected function buildPolicies(): Map
            {
                $frontend = new Map();
                $frontend['package'] = 'https://package.example';
                $policies = new Map();
                $policies['frontend'] = $frontend;

                return $this->services['content.security.policies'] = $policies;
            }
        };
    }

    private function captureLog(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'koh-config-services');
        $this->previousLog = ini_set('error_log', $this->log);
    }

    private function loggedLines(): string
    {
        return $this->log === null ? '' : (string) file_get_contents($this->log);
    }
}
