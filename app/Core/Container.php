<?php

declare(strict_types=1);

namespace GoldBot\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Dependency injection container with constructor autowiring.
 *
 * This is the single seam where interfaces map to implementations. Every port
 * described in docs/01 §4 — market data, calendar, notifier, cache, clock,
 * lock — is bound here via config/services.php, which is what makes swapping
 * a vendor a one-line change instead of a refactor.
 *
 * Autowiring resolves concrete constructor dependencies by type. Interfaces
 * must be bound explicitly: guessing an implementation would make the wiring
 * invisible, and invisible wiring is how a test accidentally hits the network.
 */
final class Container
{
    /** @var array<string,Closure> */
    private array $bindings = [];

    /** @var array<string,object> */
    private array $instances = [];

    /** @var array<string,bool> */
    private array $shared = [];

    /** @var list<string> Resolution stack, for cycle detection. */
    private array $building = [];

    public function bind(string $id, Closure $factory, bool $shared = false): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id] = $shared;

        unset($this->instances[$id]);
    }

    public function singleton(string $id, Closure $factory): void
    {
        $this->bind($id, $factory, true);
    }

    public function instance(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
    }

    /**
     * Alias an interface to an already-bound concrete class.
     */
    public function alias(string $id, string $concrete): void
    {
        $this->singleton($id, fn (Container $c): object => $c->get($concrete));
    }

    public function has(string $id): bool
    {
        // An interface with no binding is not resolvable, so has() must not
        // claim it is — callers use this to decide whether to call get().
        return isset($this->bindings[$id])
            || isset($this->instances[$id])
            || class_exists($id);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|object
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->building, true)) {
            throw new RuntimeException(
                'Circular dependency detected while resolving [' . $id . ']: '
                . implode(' -> ', [...$this->building, $id])
            );
        }

        $this->building[] = $id;

        try {
            $object = isset($this->bindings[$id])
                ? ($this->bindings[$id])($this)
                : $this->build($id);
        } finally {
            array_pop($this->building);
        }

        if (($this->shared[$id] ?? false) === true) {
            $this->instances[$id] = $object;
        }

        return $object;
    }

    /**
     * Instantiate a concrete class, resolving its constructor dependencies.
     */
    private function build(string $id): object
    {
        // class_exists() is false for interfaces, so an unbound interface would
        // otherwise get the unhelpful "not a known class" message rather than
        // the one that names the actual fix.
        if (interface_exists($id)) {
            throw new RuntimeException(
                "Cannot resolve [{$id}]: it is an interface or abstract class with no binding. "
                . 'Bind it explicitly in config/services.php.'
            );
        }

        if (!class_exists($id)) {
            throw new RuntimeException(
                "Cannot resolve [{$id}]: it is not a known class and has no binding."
            );
        }

        $reflector = new ReflectionClass($id);

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException(
                "Cannot resolve [{$id}]: it is an interface or abstract class with no binding. "
                . 'Bind it explicitly in config/services.php.'
            );
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $id();
        }

        $arguments = array_map(
            fn (ReflectionParameter $p): mixed => $this->resolveParameter($p, $id),
            $constructor->getParameters()
        );

        return $reflector->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter, string $owner): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new RuntimeException(
            "Cannot resolve parameter \${$parameter->getName()} of [{$owner}]: "
            . 'it has no type hint, no default and no binding.'
        );
    }

    /**
     * Invoke a callable, resolving any object parameters from the container.
     *
     * @param array<string,mixed> $overrides Values supplied by name.
     */
    public function call(callable $callable, array $overrides = []): mixed
    {
        $reflector = new \ReflectionFunction(Closure::fromCallable($callable));

        $arguments = [];

        foreach ($reflector->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $overrides)) {
                $arguments[] = $overrides[$name];
                continue;
            }

            $arguments[] = $this->resolveParameter($parameter, 'closure');
        }

        return $reflector->invokeArgs($arguments);
    }

    /**
     * Names of every explicit binding. Used by the boot self-check to assert
     * that every declared port actually resolves (docs/04, Phase 1 verify).
     *
     * @return list<string>
     */
    public function bindingIds(): array
    {
        return array_values(array_unique([
            ...array_keys($this->bindings),
            ...array_keys($this->instances),
        ]));
    }
}
