<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Tests\Unit\Runtime;

use Koh\FrankenPHP\Runtime\KeptObjectState;
use Koh\FrankenPHP\Runtime\RequestClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\MetaTag\GenericMetaTagManager;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;

/**
 * A kept object gets back exactly the property values the boot left it with,
 * whatever its class, and the audit sees what a request changed.
 */
final class KeptObjectStateTest extends TestCase
{
    #[Test]
    public function restorePutsBackEveryPropertyIncludingPrivateOnesOfParentClasses(): void
    {
        $object = new KeptChild();
        $state = KeptObjectState::capture(['service:child' => $object]);

        $object->change('request');
        $state->restore();

        self::assertSame(['boot', 'boot', 'boot', null], $object->values());
    }

    #[Test]
    public function aPropertyUninitialisedAtTheBootIsUninitialisedAgain(): void
    {
        $object = new KeptChild();
        $state = KeptObjectState::capture(['service:child' => $object]);

        $object->late = 'request';
        $state->restore();

        self::assertFalse((new \ReflectionProperty(KeptChild::class, 'late'))->isInitialized($object));
    }

    #[Test]
    public function readonlyPropertiesAreLeftAlone(): void
    {
        $object = new KeptChild();
        $state = KeptObjectState::capture(['service:child' => $object]);

        $state->restore();

        self::assertSame('constructed', $object->fixed);
    }

    /**
     * The production failure: managers a request created stay in the kept
     * registry, with that request's tags, and a later addProperty() without
     * replace does not overwrite them.
     */
    #[Test]
    public function theMetaTagRegistryForgetsTheManagersOfTheRequest(): void
    {
        $registry = new MetaTagManagerRegistry();
        $registry->registerManager('custom', GenericMetaTagManager::class);
        $state = KeptObjectState::capture(['service:' . MetaTagManagerRegistry::class => $registry]);
        $registry->getManagerForProperty('description')->addProperty('description', 'Start page');

        $state->restore();

        $manager = $registry->getManagerForProperty('description');
        $manager->addProperty('description', 'Contact page');
        self::assertSame('Contact page', $manager->getProperty('description')[0]['content'] ?? null);
        self::assertArrayHasKey('custom', $registry->getAllManagers(), 'registrations from the boot stay');
    }

    #[Test]
    public function driftNamesTheChangedPropertiesAndIsEmptyAfterRestore(): void
    {
        $object = new KeptChild();
        $state = KeptObjectState::capture(['service:child' => $object]);

        $object->change('request');
        $drift = $state->drift();
        $state->restore();

        self::assertCount(1, $drift);
        self::assertSame('service:child', $drift[0]['label']);
        self::assertSame(KeptChild::class, $drift[0]['class']);
        self::assertEqualsCanonicalizing(['own', 'shared', 'hidden', 'cache'], $drift[0]['properties']);
        self::assertSame([], $state->drift());
    }

    #[Test]
    public function aDeeperCaptureAlsoObservesObjectsTheKeptOnesPointTo(): void
    {
        $inner = new KeptChild();
        $outer = new \ArrayObject();
        $holder = new KeptHolder([$inner]);
        $state = KeptObjectState::capture(['service:holder' => $holder, 'service:array' => $outer], [], 3);

        $inner->change('request');

        self::assertSame(['service:holder->items[0]'], array_column($state->drift(), 'label'));
    }

    #[Test]
    public function theWorkerObjectsClosuresAndSkippedClassesAreNeverCaptured(): void
    {
        $state = KeptObjectState::capture([
            'global:classifier' => new RequestClassifier('/typo3'),
            'global:closure' => static fn(): int => 1,
            'service:skipped' => new KeptChild(),
            'service:scalar' => 'text',
        ], [KeptParent::class]);

        self::assertSame(0, $state->count());
    }

    #[Test]
    public function anObjectKeptUnderSeveralNamesIsCapturedOnce(): void
    {
        $object = new KeptChild();
        $state = KeptObjectState::capture(['service:a' => $object, 'private:b' => $object]);

        self::assertSame(['service:a' => KeptChild::class], $state->objects());
    }

    #[Test]
    public function requestReferencesFindRequestStateBehindKeptObjects(): void
    {
        $context = new Context();
        $holder = new KeptHolder(['nested' => new KeptHolder([$context])]);
        $state = KeptObjectState::capture(['service:holder' => $holder]);

        $references = $state->requestReferences([Context::class]);

        self::assertSame([[
            'label' => 'service:holder',
            'class' => KeptHolder::class,
            'path' => '->items[nested]->items[0]',
            'target' => Context::class,
        ]], $references);
    }
}

/** @internal */
class KeptParent
{
    private string $hidden = 'boot';

    protected string $shared = 'boot';

    public function change(string $value): void
    {
        $this->hidden = $value;
        $this->shared = $value;
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return [$this->hidden, $this->shared];
    }
}

/** @internal */
final class KeptChild extends KeptParent
{
    public string $own = 'boot';

    public string $late;

    public readonly string $fixed;

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public static string $static = 'boot';

    public function __construct()
    {
        $this->fixed = 'constructed';
    }

    public function change(string $value): void
    {
        parent::change($value);
        $this->own = $value;
        $this->cache = ['key' => $value];
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return [$this->own, ...parent::values(), $this->cache];
    }
}

/** @internal */
final class KeptHolder
{
    /** @param array<array-key, mixed> $items */
    public function __construct(public array $items) {}
}
