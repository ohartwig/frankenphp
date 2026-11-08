<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Functional;

use Koh\FrankenPHP\Message\PurgeEdgeCache;
use Koh\FrankenPHP\MessageHandler\PurgeEdgeCacheHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Smoke test: boots a TYPO3 instance with this extension installed.
 *
 * It exists because a container that does not compile passed every unit
 * test elsewhere: a unit test never builds the container, so the failure
 * only surfaced in a consuming installation's release build.
 *
 * The statement of this test is the boot itself: if the container cannot be
 * built, the run never reaches the first assertion.
 */
final class ContainerWiringTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/frankenphp'];

    #[Test]
    public function theInstanceComesUpWithThisExtensionInstalled(): void
    {
        // A question to the container, not a type assertion on it: the getter
        // is typed, so asserting its type can never fail. Finding a core
        // service says the container was built and answers.
        self::assertTrue($this->getContainer()->has(CacheManager::class));
    }

    /**
     * The edge purge is a message now. Without koh/typo3-async it has no
     * route of its own, so TYPO3's default routing handles it in the request
     * -- through the handler the attribute registers, or not at all.
     */
    #[Test]
    public function aPurgeMessageIsHandledInTheRequestWithoutKohAsync(): void
    {
        self::assertArrayNotHasKey(PurgeEdgeCache::class, $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'] ?? []);

        $envelope = $this->get(MessageBusInterface::class)->dispatch(new PurgeEdgeCache([]));

        $handled = $envelope->all(HandledStamp::class);
        self::assertCount(1, $handled);
        self::assertInstanceOf(HandledStamp::class, $handled[0]);
        self::assertStringContainsString(PurgeEdgeCacheHandler::class . "::__invoke", $handled[0]->getHandlerName());
    }

    /**
     * Every hand-wired service in Services.yaml can be built.
     *
     * Hand-wired means an entry with its own `arguments`, `factory` or
     * `alias` — the part autowiring does not cover and someone typed. The
     * list is read from the file rather than kept here, so a new entry is
     * covered from its first run.
     */
    #[Test]
    public function everyHandWiredServiceCanBeBuilt(): void
    {
        $services = self::handWiredServices();

        // A loop, not a data provider: an extension without hand-wired
        // services is a valid state, an empty data provider is a PHPUnit error.
        if ([] === $services) {
            self::markTestSkipped('this extension wires no service by hand');
        }

        foreach ($services as $id) {
            self::assertInstanceOf($id, $this->get($id), $id);
        }
    }

    /**
     * @return list<class-string>
     */
    private static function handWiredServices(): array
    {
        $parsed = Yaml::parseFile(
            \sprintf('%s/Configuration/Services.yaml', \dirname(__DIR__, 2)),
            Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS,
        );

        /** @var array<string, mixed> $services */
        $services = $parsed['services'] ?? [];
        $found = [];

        foreach ($services as $id => $definition) {
            if (!\is_string($id) || !\is_array($definition)) {
                continue;
            }

            // Namespace rules (`Foo\Bar\:` with `resource:`), `_defaults` and
            // service ids like `cache.agentic_commerce` name no single class.
            if (!class_exists($id) && !interface_exists($id)) {
                continue;
            }

            if ([] === array_intersect(['arguments', 'factory', 'alias'], array_keys($definition))) {
                continue;
            }

            $found[] = $id;
        }

        return $found;
    }
}
