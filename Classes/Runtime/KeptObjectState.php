<?php

declare(strict_types=1);

namespace Koh\FrankenPHP\Runtime;

use Psr\Container\ContainerInterface;

/**
 * The property values of the objects a persistent bootstrap keeps across
 * requests, as they were right after the boot, and the means to put them
 * back.
 *
 * Keeping an object keeps whatever a request writes into it. A registry
 * created by the boot (MetaTagManagerRegistry, a service an extension
 * instantiates in ext_localconf.php) would otherwise hand the next request
 * the managers, tags or lookups of the previous one. A bootstrap per request
 * never has that problem, because every request gets new objects in their
 * boot state; restoring the recorded values gives the kept objects that same
 * state without building them again, regardless of their class and of who
 * registered them.
 *
 * Restored are the declared, non-static, non-readonly properties of every
 * class in the hierarchy (private ones of parent classes included): each to
 * the value it had, or back to uninitialised. Readonly properties cannot
 * change after the boot, static properties are not the object's. Not
 * restored: the internal state of objects of PHP's own classes (ArrayObject,
 * SplObjectStorage, ...), properties with hooks, and objects a kept property
 * points to without being kept itself (an object the boot created and a
 * request changes in place). drift() and requestReferences() find what this
 * leaves open; see PersistentBootstrap's audit.
 *
 * @internal
 */
final class KeptObjectState
{
    /** Array items the nested walks look at per array: TCA-sized arrays hold configuration, not objects. */
    private const ARRAY_ITEMS = 200;

    /**
     * Per kept object: the object, its label, and per property whether it
     * was initialised and its value.
     *
     * @var list<array{object: object, label: string, properties: list<array{\ReflectionProperty, bool, mixed}>}>
     */
    private array $entries = [];

    /** @var array<class-string, \Closure(object, string): void> */
    private static array $unsetters = [];

    /**
     * @param iterable<string, mixed> $objects label (service id, singleton class, global) => value; non-objects are ignored
     * @param list<class-string>      $skip    classes (and their subclasses) never captured
     * @param int                     $depth   0: the objects themselves; more: also the objects their properties
     *                                         point to, through arrays and objects, up to that many levels (for
     *                                         the audit: what a request changes in place further down)
     */
    public static function capture(iterable $objects, array $skip = [], int $depth = 0): self
    {
        $state = new self();
        $seen = [];
        foreach ($objects as $label => $object) {
            if (is_object($object)) {
                $state->add($object, $label, $skip, $depth, $seen);
            }
        }

        return $state;
    }

    /** Puts every captured property back to its recorded value. */
    public function restore(): void
    {
        foreach ($this->entries as $entry) {
            $object = $entry['object'];
            foreach ($entry['properties'] as [$property, $initialised, $value]) {
                if ($initialised) {
                    $property->setValue($object, $value);
                } elseif ($property->isInitialized($object)) {
                    self::unsetProperty($object, $property);
                }
            }
        }
    }

    /** Number of objects whose properties are captured. */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * What is captured: label => class.
     *
     * @return array<string, class-string>
     */
    public function objects(): array
    {
        $objects = [];
        foreach ($this->entries as $entry) {
            $objects[$entry['label']] = $entry['object']::class;
        }

        return $objects;
    }

    /**
     * The captured objects whose properties differ from the recorded values
     * now: what a request wrote into kept objects.
     *
     * @return list<array{label: string, class: class-string, properties: list<string>}>
     */
    public function drift(): array
    {
        $drift = [];
        foreach ($this->entries as $entry) {
            $object = $entry['object'];
            $changed = [];
            foreach ($entry['properties'] as [$property, $initialised, $value]) {
                $now = $property->isInitialized($object);
                if ($now !== $initialised || ($now && $property->getValue($object) !== $value)) {
                    $changed[] = $property->getName();
                }
            }
            if ($changed !== []) {
                $drift[] = ['label' => $entry['label'], 'class' => $object::class, 'properties' => array_values(array_unique($changed))];
            }
        }

        return $drift;
    }

    /**
     * Kept objects that point, directly or through arrays and further
     * objects (up to $depth levels), to an object of one of $classes: state
     * of one request (a Context, a request, a site language) captured by an
     * object that outlives it.
     *
     * @param list<class-string> $classes
     *
     * @return list<array{label: string, class: class-string, path: string, target: class-string}>
     */
    public function requestReferences(array $classes, int $depth = 4): array
    {
        $found = [];
        foreach ($this->entries as $entry) {
            $visited = [spl_object_id($entry['object']) => true];
            foreach ($entry['properties'] as [$property, $initialised, $value]) {
                if (!$initialised) {
                    continue;
                }
                foreach (self::references($value, $classes, $depth, '->' . $property->getName(), $visited) as [$path, $target]) {
                    $found[] = ['label' => $entry['label'], 'class' => $entry['object']::class, 'path' => $path, 'target' => $target];
                }
            }
        }

        return $found;
    }

    /**
     * The static properties of every loaded class outside PHP itself,
     * Composer and this namespace, for the audit: neither this class nor a
     * bootstrap per request resets them, so request state kept there
     * survives in both modes.
     *
     * @return array<string, mixed> "Class::$property" => value
     */
    public static function statics(): array
    {
        $statics = [];
        foreach (get_declared_classes() as $class) {
            if (str_starts_with($class, __NAMESPACE__ . '\\') || str_starts_with($class, 'Composer\\')) {
                continue;
            }
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInternal() || $reflection->isAnonymous()) {
                continue;
            }
            foreach ($reflection->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class || !$property->isInitialized()) {
                    continue;
                }
                $statics[$class . '::$' . $property->getName()] = $property->getValue();
            }
        }

        return $statics;
    }

    /**
     * @param list<class-string> $skip
     * @param array<int, true>   $seen
     */
    private function add(object $object, string $label, array $skip, int $depth, array &$seen): void
    {
        if (isset($seen[spl_object_id($object)]) || self::isSkipped($object, $skip)) {
            return;
        }
        $seen[spl_object_id($object)] = true;
        $properties = self::properties($object);
        if ($properties === []) {
            return;
        }
        $this->entries[] = ['object' => $object, 'label' => $label, 'properties' => $properties];
        if ($depth <= 0) {
            return;
        }
        foreach ($properties as [$property, $initialised, $value]) {
            if ($initialised) {
                $this->addNested($value, $label . '->' . $property->getName(), $skip, $depth, $seen);
            }
        }
    }

    /**
     * @param list<class-string> $skip
     * @param array<int, true>   $seen
     */
    private function addNested(mixed $value, string $label, array $skip, int $depth, array &$seen): void
    {
        if (is_object($value)) {
            if (!(new \ReflectionObject($value))->isInternal()) {
                $this->add($value, $label, $skip, $depth - 1, $seen);
            }

            return;
        }
        if (!is_array($value) || $depth <= 1) {
            return;
        }
        foreach (array_slice($value, 0, self::ARRAY_ITEMS, true) as $key => $item) {
            $this->addNested($item, $label . '[' . $key . ']', $skip, $depth - 1, $seen);
        }
    }

    /**
     * @param list<class-string> $classes
     * @param array<int, true>   $visited
     *
     * @return list<array{string, class-string}> path => class of what was found
     */
    private static function references(mixed $value, array $classes, int $depth, string $path, array &$visited): array
    {
        if ($depth <= 0) {
            return [];
        }
        if (is_array($value)) {
            $found = [];
            foreach (array_slice($value, 0, self::ARRAY_ITEMS, true) as $key => $item) {
                if (is_array($item) || is_object($item)) {
                    array_push($found, ...self::references($item, $classes, $depth - 1, $path . '[' . $key . ']', $visited));
                }
            }

            return $found;
        }
        if (!is_object($value) || isset($visited[spl_object_id($value)])) {
            return [];
        }
        $visited[spl_object_id($value)] = true;
        foreach ($classes as $class) {
            if ($value instanceof $class) {
                return [[$path, $value::class]];
            }
        }
        if ($value instanceof \Closure || $value instanceof ContainerInterface || (new \ReflectionObject($value))->isInternal()) {
            return [];
        }
        $found = [];
        foreach (self::properties($value) as [$property, $initialised, $item]) {
            if ($initialised && (is_array($item) || is_object($item))) {
                array_push($found, ...self::references($item, $classes, $depth - 1, $path . '->' . $property->getName(), $visited));
            }
        }

        return $found;
    }

    /**
     * @param list<class-string> $skip
     */
    private static function isSkipped(object $object, array $skip): bool
    {
        if ($object instanceof \Closure || $object instanceof \UnitEnum || $object instanceof ContainerInterface) {
            return true;
        }
        // The worker's own objects (the worker script's variables are
        // globals): their state is the worker's, not a request's.
        if (str_starts_with($object::class, __NAMESPACE__ . '\\')) {
            return true;
        }
        foreach ($skip as $class) {
            if ($object instanceof $class) {
                return true;
            }
        }
        $reflection = new \ReflectionObject($object);
        // PHP 8.4+: reading an uninitialised lazy object would initialise it,
        // and nothing has written to it yet.
        // @phpstan-ignore function.alreadyNarrowedType (absent on PHP 8.3, which composer.json admits)
        if (method_exists($reflection, 'isUninitializedLazyObject') && $reflection->isUninitializedLazyObject($object)) {
            return true;
        }

        return false;
    }

    /**
     * The restorable properties of $object with their current state.
     *
     * @return list<array{\ReflectionProperty, bool, mixed}>
     */
    private static function properties(object $object): array
    {
        $properties = [];
        for ($class = new \ReflectionClass($object); $class !== false && !$class->isInternal(); $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->isReadOnly() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }
                // PHP 8.4+: a hook may compute or reject a value; leave such properties alone.
                // @phpstan-ignore function.alreadyNarrowedType (absent on PHP 8.3, which composer.json admits)
                if (method_exists($property, 'hasHooks') && $property->hasHooks()) {
                    continue;
                }
                $initialised = $property->isInitialized($object);
                $properties[] = [$property, $initialised, $initialised ? $property->getValue($object) : null];
            }
        }

        return $properties;
    }

    /**
     * Back to uninitialised: only an unset() in the scope of the declaring
     * class does that, reflection cannot.
     */
    private static function unsetProperty(object $object, \ReflectionProperty $property): void
    {
        $class = $property->getDeclaringClass()->getName();
        self::$unsetters[$class] ??= \Closure::bind(
            static function (object $object, string $name): void {
                // @phpstan-ignore property.dynamicName (the property is one of this class's, named by reflection)
                unset($object->$name);
            },
            null,
            $class,
        );
        (self::$unsetters[$class])($object, $property->getName());
    }
}
