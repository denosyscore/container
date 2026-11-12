<?php

declare(strict_types=1);

namespace Denosys\Container;

use Closure;
use Denosys\Container\Exceptions\ContainerException;
use Denosys\Container\Exceptions\ContainerResolutionException;
use Denosys\Container\Exceptions\NotFoundException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Throwable;

/**
 * A PSR-11 container: explicit bindings, shared instances, aliases and
 * constructor autowiring. Constructor parameters can carry attributes that
 * implement ParameterResolver, such as #[Config('app.key')].
 *
 * An identifier the container cannot provide raises NotFoundException. A
 * failure while building an entry it does provide raises
 * ContainerResolutionException, naming the entry and keeping the cause.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, array{concrete: Closure, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** @var array<class-string, ReflectionClass<object>> */
    private array $reflections = [];

    private ?Closure $deferredResolver = null;

    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->forget($abstract);

        $class = $concrete ?? $abstract;
        $factory = $class instanceof Closure ? $class : fn (): object => $this->build($class);

        $this->bindings[$abstract] = ['concrete' => $factory, 'shared' => $shared];
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, shared: true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        if (interface_exists($abstract) && !$instance instanceof $abstract) {
            throw new ContainerException("Instance does not implement {$abstract}");
        }

        $this->forget($abstract);
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    public function alias(string $alias, string $abstract): void
    {
        if (!isset($this->bindings[$abstract]) && !isset($this->instances[$abstract]) && !$this->isInstantiable($abstract)) {
            throw new NotFoundException(
                "Cannot alias '{$alias}' to '{$abstract}': target is not bound and not an instantiable class."
            );
        }

        $this->aliases[$alias] = $abstract;
    }

    public function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    public function extend(string $abstract, Closure $concrete): void
    {
        $abstract = $this->getAlias($abstract);

        if (array_key_exists($abstract, $this->instances)) {
            $this->instances[$abstract] = $concrete($this->instances[$abstract], $this);

            return;
        }

        if (!isset($this->bindings[$abstract])) {
            throw new NotFoundException("Cannot extend '{$abstract}' because it is not bound in the container.");
        }

        $original = $this->bindings[$abstract]['concrete'];
        $this->bindings[$abstract]['concrete'] = static fn (Container $container): mixed => $concrete($original($container), $container);
    }

    public function setDeferredResolver(Closure $resolver): void
    {
        $this->deferredResolver = $resolver;
    }

    public function has(string $id): bool
    {
        $id = $this->getAlias($id);

        return isset($this->bindings[$id]) || array_key_exists($id, $this->instances) || class_exists($id);
    }

    public function get(string $id): mixed
    {
        $requested = $id;
        $id = $this->getAlias($id);

        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        // Let the host register a provider for this identifier on demand. The
        // alias is resolved again because the provider may have added it.
        if ($this->deferredResolver !== null && !isset($this->bindings[$id])) {
            ($this->deferredResolver)($requested);
            $id = $this->getAlias($requested);
            if (array_key_exists($id, $this->instances)) {
                return $this->instances[$id];
            }
        }

        if (!isset($this->bindings[$id]) && !class_exists($id)) {
            throw new NotFoundException("No entry or class found for '{$id}'.");
        }

        if (isset($this->resolving[$id])) {
            throw new ContainerResolutionException(
                "Circular dependency detected while resolving '{$id}'.",
                $id,
                array_keys($this->resolving),
            );
        }

        $this->resolving[$id] = true;
        try {
            $binding = $this->bindings[$id] ?? null;
            $instance = $binding !== null ? ($binding['concrete'])($this) : $this->build($id);

            if ($binding !== null && $binding['shared']) {
                $this->instances[$id] = $instance;
            }

            return $instance;
        } catch (ContainerResolutionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ContainerResolutionException(
                "Failed to resolve '{$id}': " . $exception->getMessage(),
                $id,
                [...array_keys($this->resolving)],
                null,
                $exception,
            );
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Instantiate a class, resolving its constructor parameters.
     */
    private function build(string $class): object
    {
        $reflection = $this->reflect($class);
        if (!$reflection->isInstantiable()) {
            throw new ContainerException("Class '{$class}' is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $class);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter, string $class): mixed
    {
        foreach ($parameter->getAttributes(ParameterResolver::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            return $attribute->newInstance()->resolve($parameter, $this);
        }

        $name = $parameter->getName();
        $type = $parameter->getType();
        $candidates = match (true) {
            $type instanceof ReflectionNamedType => [$type],
            $type instanceof ReflectionUnionType => $type->getTypes(),
            default => [],
        };

        foreach ($candidates as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                $abstract = $candidate->getName();
                if ($this->canProvide($abstract)) {
                    return $this->get($abstract);
                }
            }
        }

        if ($type !== null && $parameter->allowsNull()) {
            return null;
        }
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type === null) {
            throw new ContainerException("Cannot resolve untyped parameter \${$name} in {$class} constructor.");
        }
        if ($type instanceof ReflectionUnionType) {
            throw new ContainerException(
                "Cannot resolve union-typed parameter \${$name} in {$class}. Bind one of the union members or make it nullable/defaulted."
            );
        }
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $abstract = $type->getName();
            $what = interface_exists($abstract) ? 'interface' : (class_exists($abstract) ? 'abstract class' : 'unknown type');

            throw new ContainerException(
                "Cannot resolve {$what} '{$abstract}' for \${$name} in {$class}. Bind it or make the parameter nullable/defaulted."
            );
        }

        throw new ContainerException("Cannot resolve builtin parameter \${$name} in {$class} constructor without a default value.");
    }

    private function canProvide(string $abstract): bool
    {
        $abstract = $this->getAlias($abstract);

        return isset($this->bindings[$abstract])
            || array_key_exists($abstract, $this->instances)
            || $this->isInstantiable($abstract);
    }

    private function isInstantiable(string $class): bool
    {
        return class_exists($class) && $this->reflect($class)->isInstantiable();
    }

    /**
     * @return ReflectionClass<object>
     */
    private function reflect(string $class): ReflectionClass
    {
        if (!class_exists($class)) {
            throw new NotFoundException("Class '{$class}' not found.");
        }

        return $this->reflections[$class] ??= new ReflectionClass($class);
    }

    /**
     * Drop a cached instance and any aliases pointing at a rebound identifier.
     */
    private function forget(string $abstract): void
    {
        unset($this->instances[$abstract]);

        foreach ($this->aliases as $alias => $target) {
            if ($target === $abstract) {
                unset($this->aliases[$alias]);
            }
        }
    }
}
