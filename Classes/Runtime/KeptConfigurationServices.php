<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Type\Map;

/**
 * Three configuration services a persistent bootstrap builds with the boot
 * and keeps (KOH_PERSISTENT_CONFIG_SERVICES=1, only with
 * KOH_PERSISTENT_BOOTSTRAP=1): the content security policies of all packages
 * (`content.security.policies`), the global Fluid namespaces
 * (`fluid.namespaces`) and the Fluid component collections
 * (`fluid.component.collections`).
 *
 * Every request otherwise builds them again from the package list, with one
 * file check per package for each of them. Their content depends on nothing
 * but the installed packages, so it is boot state.
 *
 * KeptObjectState cannot put them back: the two Fluid services are
 * ArrayObjects, whose items are PHP's internal state, and the policies are a
 * Map of further Maps that the boot creates and nobody keeps on their own.
 * A request that changed one of them in place would hand the change to every
 * later request. So the state they had right after the boot is recorded here,
 * and before each request every one of them is compared with it; one that
 * differs is put back in place (the same object, so whatever holds it sees
 * the boot's content again) and reported as drift.
 *
 * @internal
 */
final class KeptConfigurationServices
{
    public const ENV = 'KOH_PERSISTENT_CONFIG_SERVICES';

    public const IDS = ['content.security.policies', 'fluid.namespaces', 'fluid.component.collections'];

    /** How far the recorded state follows the policy map into the maps and collections it holds. */
    private const DEPTH = 4;

    /**
     * Properties that change by reading alone: a Map's iteration cursor moves
     * with every foreach over it. Put back like the rest, but no drift.
     */
    private const CURSORS = [Map::class => ['index', 'end']];

    /**
     * Per service: the object, and its recorded state (the items of an
     * ArrayObject; the property values of any other object, as deep as DEPTH).
     *
     * @var array<string, array{object: object, items: array<array-key, mixed>|null, state: KeptObjectState|null}>
     */
    private array $kept = [];

    public static function isEnabled(): bool
    {
        return WorkerSwitch::isOn(self::ENV);
    }

    /** Builds the services in $container, so the boot state recorded next includes them. */
    public static function instantiate(Container $container): void
    {
        foreach (self::IDS as $id) {
            if ($container->has($id)) {
                $container->get($id);
            }
        }
    }

    /**
     * Records the state of the services in $container, right after the boot
     * built them. By id, through the container: TYPO3 registers each of them
     * under a decorated id the id is an alias of.
     */
    public static function capture(Container $container): self
    {
        $kept = new self();
        foreach (self::IDS as $id) {
            if (!$container->has($id)) {
                continue;
            }
            $service = $container->get($id);
            if ($service instanceof \ArrayObject) {
                $kept->kept[$id] = ['object' => $service, 'items' => $service->getArrayCopy(), 'state' => null];
            } elseif (is_object($service)) {
                $kept->kept[$id] = ['object' => $service, 'items' => null, 'state' => KeptObjectState::capture(['service:' . $id => $service], [], self::DEPTH)];
            }
        }

        return $kept;
    }

    /** @return list<string> the ids of the kept services */
    public function ids(): array
    {
        return array_keys($this->kept);
    }

    /**
     * Puts back every service that differs from its recorded state.
     *
     * @return list<array{label: string, class: class-string, properties: list<string>}> what differed, in KeptObjectState::drift()'s shape
     */
    public function verify(): array
    {
        $drift = [];
        foreach ($this->kept as $id => $entry) {
            $object = $entry['object'];
            if ($object instanceof \ArrayObject && $entry['items'] !== null) {
                if ($object->getArrayCopy() !== $entry['items']) {
                    $object->exchangeArray($entry['items']);
                    $drift[] = ['label' => 'service:' . $id, 'class' => $object::class, 'properties' => ['(items)']];
                }

                continue;
            }
            $state = $entry['state'];
            if ($state === null) {
                continue;
            }
            $changed = $state->drift();
            if ($changed === []) {
                continue;
            }
            $state->restore();
            foreach ($changed as $entry) {
                $entry['properties'] = array_values(array_diff($entry['properties'], self::CURSORS[$entry['class']] ?? []));
                if ($entry['properties'] !== []) {
                    $drift[] = $entry;
                }
            }
        }

        return $drift;
    }
}
