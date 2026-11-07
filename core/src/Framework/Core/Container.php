<?php

declare(strict_types=1);

namespace Kestello\Framework\Core;

use Closure;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;

/**
 * Lightweight dependency-injection container with auto-resolution.
 */
final class Container
{
    /** @var array<string, Closure|string> Registered bindings. */
    private array $bindings = [];

    /** @var array<string, true> Keys that should be treated as singletons. */
    private array $singletons = [];

    /** @var array<string, mixed> Resolved singleton instances. */
    private array $instances = [];

    /**
     * Register a binding.
     *
     * @param string         $abstract Fully-qualified class or interface name, or an alias.
     * @param Closure|string $concrete A factory closure or a concrete class name.
     */
    public function bind(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->singletons[$abstract], $this->instances[$abstract]);
    }

    /**
     * Register a shared (singleton) binding.
     *
     * @param string         $abstract Fully-qualified class or interface name, or an alias.
     * @param Closure|string $concrete A factory closure or a concrete class name.
     */
    public function singleton(string $abstract, Closure|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        $this->singletons[$abstract] = true;
    }

    /**
     * Store an already-resolved instance (always treated as a singleton).
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->singletons[$abstract] = true;
    }

    /**
     * Resolve a binding from the container.
     *
     * @param string               $abstract   The abstract type to resolve.
     * @param array<string, mixed> $parameters Additional constructor parameters keyed by name.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        // Return cached singleton if available.
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        $concrete = $this->bindings[$abstract] ?? $abstract;

        $object = match (true) {
            $concrete instanceof Closure => $concrete($this, $parameters),
            is_string($concrete) && $concrete !== $abstract => $this->make($concrete, $parameters),
            is_string($concrete) => $this->build($concrete, $parameters),
            default => throw new RuntimeException("Unable to resolve [{$abstract}]."),
        };

        if (isset($this->singletons[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Determine whether a binding or instance exists.
     */
    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    // ------------------------------------------------------------------
    // Auto-resolution
    // ------------------------------------------------------------------

    /**
     * Build a concrete class using reflection-based auto-wiring.
     *
     * @param string               $concrete   Fully-qualified class name.
     * @param array<string, mixed> $parameters Explicit parameter overrides.
     */
    private function build(string $concrete, array $parameters = []): object
    {
        try {
            $reflector = new ReflectionClass($concrete);
        } catch (ReflectionException) {
            throw new RuntimeException("Class [{$concrete}] does not exist.");
        }

        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class [{$concrete}] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return $reflector->newInstance();
        }

        $dependencies = array_map(
            fn(ReflectionParameter $param): mixed => $this->resolveDependency($param, $parameters),
            $constructor->getParameters(),
        );

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @param ReflectionParameter  $param      The parameter to resolve.
     * @param array<string, mixed> $parameters Explicit overrides.
     */
    private function resolveDependency(ReflectionParameter $param, array $parameters): mixed
    {
        $name = $param->getName();

        // Explicit parameter supplied by the caller.
        if (array_key_exists($name, $parameters)) {
            return $parameters[$name];
        }

        $type = $param->getType();

        // If the parameter has a class type-hint, try to resolve it.
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            try {
                return $this->make($type->getName());
            } catch (RuntimeException $e) {
                if ($param->isDefaultValueAvailable()) {
                    return $param->getDefaultValue();
                }
                throw $e;
            }
        }

        // Fall back to the default value.
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        throw new InvalidArgumentException(
            "Unable to resolve parameter [\${$name}] in class [{$param->getDeclaringClass()?->getName()}].",
        );
    }
}
