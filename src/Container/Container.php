<?php

declare(strict_types=1);

namespace Denosys\Container;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Throwable;
use Denosys\Container\Binding\ContextualBindingManager;
use Denosys\Container\Binding\TaggedBindingRegistry;
use Denosys\Container\Decorators\DecoratorChain;
use Denosys\Container\Events\BindingRegistered;
use Denosys\Container\Events\ResolutionDone;
use Denosys\Container\Events\ResolutionFailed;
use Denosys\Container\Events\ResolutionStarting;
use Denosys\Container\Exceptions\ContainerException;
use Denosys\Container\Exceptions\ContainerResolutionException;
use Denosys\Container\Exceptions\NotFoundException;
use Denosys\Container\Injection\MethodInjector;
use Denosys\Container\Performance\ContainerCompiler;
use Denosys\Container\Performance\PerformanceProfiler;
use Denosys\Container\Performance\ReflectionCache;
use Denosys\Container\Resolution\LazyResolutionProxy;
use Denosys\Container\Resolution\MultiResolutionManager;
use Denosys\Container\Resolution\ScopedBindingContext;
use Denosys\Container\Testing\MockBindingManager;
use Denosys\Container\Validation\ContainerValidator;
use Denosys\Events\Dispatcher;
use Denosys\Events\ListenerProvider;

class Container implements
    ContainerInterface,
    MethodInvokingContainerInterface,
    TaggingContainerInterface,
    IntrospectableContainerInterface,
    TestableContainerInterface
{
    /** @var array<string, mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, mixed> */
    private array $aliases = [];

    /** @var array<string, mixed> */
    private array $resolvingStack = [];

    /** @var array<string, bool> */
    private array $removedBindings = [];

    private ContextualBindingManager $contextualBindings;
    private TaggedBindingRegistry $taggedBindings;
    private MethodInjector $methodInjector;
    private MultiResolutionManager $multiResolver;
    private ScopedBindingContext $scopedContext;
    private ReflectionCache $reflectionCache;
    private PerformanceProfiler $profiler;
    private ContainerValidator $validator;
    private MockBindingManager $mockManager;
    private Dispatcher $event;
    private DecoratorChain $decoratorChain;

    /**
     * Tracks whether advanced features have been initialized (lazy loading).
     */
    private bool $advancedFeaturesInitialized = false;

    /**
     * Resolution history for debugging and testing.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $resolutionHistory = [];

    /**
     * Maximum number of entries kept in resolutionHistory.
     */
    private int $resolutionHistoryLimit = 1000;

    /**
     * Service spies for testing.
     *
     * @var array<string, mixed>
     */
    private array $spies = [];

    /**
     * Callback to resolve deferred providers.
     */
    private ?Closure $deferredResolver = null;

    /**
     * Initialize the container.
     */
    public function __construct()
    {
        $this->reflectionCache = new ReflectionCache();
        $this->profiler = new PerformanceProfiler();
        $this->event = new Dispatcher(new ListenerProvider());
    }

    /**
     * Set a callback to resolve deferred providers.
     *
     * The callback is invoked when resolving a service that isn't bound.
     * This allows ServiceProviderBootstrapper to lazy-load deferred providers.
     *
     * @param Closure(string): void $resolver
     */
    public function setDeferredResolver(Closure $resolver): void
    {
        $this->deferredResolver = $resolver;
    }

    /**
     * Set the maximum number of entries kept in the resolution history ring-buffer.
     */
    public function setResolutionHistoryLimit(int $limit): void
    {
        $this->resolutionHistoryLimit = max(1, $limit);
    }

    /**
     * Binds an abstract identifier to a concrete implementation.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->dropStaleInstances($abstract);

        if (null === $concrete) {
            $concrete = $abstract;
        }

        $concreteClass = null;
        if (!$concrete instanceof Closure) {
            $concreteClass = $concrete;
            $concrete = fn() => $this->resolve($concreteClass);
        }

        unset($this->removedBindings[$abstract]);
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared, 'concrete_class' => $concreteClass];

        $this->event->dispatch(new BindingRegistered($abstract, $concrete, $shared));
    }

    /**
     * Binds an abstract identifier to a concrete implementation as a singleton.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bind($abstract, $concrete, shared: true);
    }

    /**
     * Aliases a string to an existing abstract identifier.
     *
     * @throws NotFoundException
     */
    public function alias(string $alias, string $abstract): void
    {
        if (!$this->has($abstract)) {
            throw new NotFoundException("Cannot create alias '{$alias}' because the abstract '{$abstract}' is not bound.");
        }

        $this->aliases[$alias] = $abstract;
    }

    /**
     * Register an instance as shared in the container.
     *
     * @throws ContainerException
     */
    public function instance(string $abstract, mixed $instance): mixed
    {
        if (interface_exists($abstract) && !($instance instanceof $abstract)) {
            throw new ContainerException("Instance does not implement {$abstract}");
        }

        $this->dropStaleInstances($abstract);
        unset($this->removedBindings[$abstract]);
        $this->instances[$abstract] = $instance;

        return $instance;
    }

    /**
     * "Extends" an existing abstract identifier in the container.
     *
     * @throws NotFoundException
     */
    public function extend(string $abstract, Closure $concrete): void
    {
        $abstract = $this->getAlias($abstract);

        if (isset($this->instances[$abstract])) {
            $this->instances[$abstract] = $concrete($this->instances[$abstract], $this);
        } elseif (isset($this->bindings[$abstract])) {
            $extender = $concrete;
            $originalConcrete = $this->bindings[$abstract]['concrete'];

            $this->bindings[$abstract]['concrete'] = function ($container) use ($extender, $originalConcrete) {
                $originalInstance = $originalConcrete($container);
                return $extender($originalInstance, $container);
            };
        } else {
            throw new NotFoundException("Cannot extend '{$abstract}' because it is not bound in the container.");
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool
    {
        $id = $this->aliases[$id] ?? $id;
        if (isset($this->removedBindings[$id])) {
            return false;
        }
        return isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);
    }

    /**
     * Get the target abstract for an alias if it exists.
     */
    public function getAlias(string $abstract): string
    {
        return $this->aliases[$abstract] ?? $abstract;
    }

    /**
     * Remove a binding and any resolved instance from the container.
     */
    public function removeBinding(string $abstract): void
    {
        unset($this->bindings[$abstract], $this->instances[$abstract]);
        $this->removedBindings[$abstract] = true;
    }

    /**
     * The core resolution logic that builds a class via reflection.
     *
     * @throws ContainerException|NotFoundException|ReflectionException|ContainerResolutionException
     */
    private function resolve(string $class): object
    {
        try {
            $reflectionClass = $this->reflectionCache->getClass($class);
        } catch (ReflectionException $e) {
            throw new NotFoundException("Class '{$class}' not found.", 0, $e);
        }

        if (!$reflectionClass->isInstantiable()) {
            throw new ContainerException("Class '{$class}' is not instantiable.");
        }

        $constructor = $reflectionClass->getConstructor();

        if (null === $constructor) {
            return new $class;
        }

        $parameters = $constructor->getParameters();

        if (empty($parameters)) {
            return new $class;
        }

        $arguments = $this->resolveParameters($parameters, $class);

        return $reflectionClass->newInstanceArgs($arguments);
    }

    /**
     * @param  array<int, mixed> $parameters
     * @return array<int, mixed>
     * @throws ContainerException|ContainerResolutionException
     */
    private function resolveParameters(array $parameters, string $classContext): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName();

            if ($type === null) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new ContainerException("Cannot resolve untyped parameter \${$name} in {$classContext} constructor.");
            }

            if ($type instanceof ReflectionUnionType) {
                $resolved = false;

                foreach ($type->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType || $unionType->isBuiltin()) {
                        continue;
                    }

                    $abstract = $unionType->getName();

                    if ($this->isResolvableBinding($abstract) || $this->isInstantiableClass($abstract)) {
                        $arguments[] = $this->get($abstract);
                        $resolved = true;
                        break;
                    }
                }

                if ($resolved) {
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $arguments[] = null;
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new ContainerException("Cannot resolve union-typed parameter \${$name} in {$classContext}. Bind one of the union members or make it nullable/defaulted.");
            }

            if ($type instanceof ReflectionNamedType) {
                if (!$type->isBuiltin()) {
                    $abstract = $type->getName();

                    if ($this->isResolvableBinding($abstract) || $this->isInstantiableClass($abstract)) {
                        $arguments[] = $this->get($abstract);
                        continue;
                    }

                    if ($parameter->allowsNull()) {
                        $arguments[] = null;
                        continue;
                    }

                    if ($parameter->isDefaultValueAvailable()) {
                        $arguments[] = $parameter->getDefaultValue();
                        continue;
                    }

                    $what = interface_exists($abstract) ? 'interface' : (class_exists($abstract) ? 'abstract class' : 'unknown type');

                    throw new ContainerException(
                        "Cannot resolve {$what} '{$abstract}' for \${$name} in {$classContext}. Bind it or make the parameter nullable/defaulted."
                    );
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new ContainerException(
                    "Cannot resolve builtin parameter \${$name} in {$classContext} constructor without a default value."
                );
            }
        }

        return $arguments;
    }

    /**
     * True if the abstract has a binding/instance in this container.
     */
    private function isResolvableBinding(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * True if $name is a concrete, instantiable class (not interface/abstract).
     */
    private function isInstantiableClass(string $name): bool
    {
        if (!class_exists($name)) {
            return false;
        }

        try {
            $class = $this->reflectionCache->getClass($name);
            return $class->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }

    /**
     * Removes a resolved instance and its alias if a new binding is registered.
     */
    private function dropStaleInstances(string $abstract): void
    {
        unset($this->instances[$abstract]);

        foreach ($this->aliases as $alias => $boundAbstract) {
            if ($boundAbstract === $abstract) {
                unset($this->aliases[$alias]);
            }
        }
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @throws ContainerResolutionException
     */
    public function get(string $id): mixed
    {
        $startTime = microtime(true);

        try {
            $this->event->dispatch(new ResolutionStarting($id));

            if ($this->advancedFeaturesInitialized && $this->mockManager->hasMock($id)) {
                $instance = $this->mockManager->getMock($id);
                $this->recordResolution($id, $startTime, true);
                return $instance;
            }

            if ($this->advancedFeaturesInitialized && $this->contextualBindings->hasContextualBinding($id)) {
                $instance = $this->contextualBindings->resolve($id, $this);
                $this->recordResolution($id, $startTime, false);
                return $instance;
            }

            $id = $this->aliases[$id] ?? $id;

            if (isset($this->instances[$id])) {
                return $this->instances[$id];
            }

            // Try to load deferred provider before checking bindings
            if ($this->deferredResolver !== null && !isset($this->bindings[$id])) {
                ($this->deferredResolver)($id);
            }

            if (isset($this->bindings[$id])) {
                $concrete = $this->bindings[$id]['concrete'];
                $isShared = $this->bindings[$id]['shared'];
            } else {
                $concrete = fn() => $this->resolve($id);
                $isShared = false;
            }

            if (isset($this->resolvingStack[$id])) {
                throw new ContainerException("Circular dependency detected while resolving '{$id}'.");
            }

            $this->resolvingStack[$id] = true;

            try {
                $instance = $concrete($this);

                if ($isShared) {
                    $this->instances[$id] = $instance;
                }

                // Apply decorators if any
                if ($this->advancedFeaturesInitialized && $this->decoratorChain->hasDecorators($id)) {
                    $instance = $this->decoratorChain->decorate($id, $instance, $this);
                }
            } finally {
                unset($this->resolvingStack[$id]);
            }

            $this->recordResolution($id, $startTime, false);

            $this->event->dispatch(new ResolutionDone(
                abstract: $id,
                instance: $instance
            ));

            return $instance;

        } catch (Throwable $e) {
            $this->event->dispatch(new ResolutionFailed(
                abstract: $id,
                exception: $e
            ));

            if (!$e instanceof ContainerResolutionException) {
                throw new ContainerResolutionException(
                    "Failed to resolve '{$id}': " . $e->getMessage(),
                    $id,
                    array_keys($this->resolvingStack),
                    $this->getSuggestionsForResolutionFailure($id, $e),
                    $e
                );
            }

            throw $e;
        }
    }

    public function when(string $concrete): ContextualBindingBuilder
    {
        $this->initializeAdvancedFeatures();
        return $this->contextualBindings->when($concrete);
    }

    public function tagged(string $tag): array
    {
        $this->initializeAdvancedFeatures();
        return $this->taggedBindings->resolveTagged($tag, $this);
    }

    public function tag(array|string $abstracts, array|string $tags): void
    {
        $this->initializeAdvancedFeatures();
        $this->taggedBindings->tag($abstracts, $tags);
    }

    /**
     * Describe a callable for use in event dispatching and error messages.
     */
    private function describeCallback(callable $callback): string
    {
        if (is_string($callback)) {
            return $callback;
        }
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return $class . '::' . $callback[1];
        }
        if ($callback instanceof Closure) {
            $rf = new ReflectionFunction($callback);
            return 'Closure@' . $rf->getFileName() . ':' . $rf->getStartLine();
        }
        return 'callable';
    }

    /**
     * @throws ContainerResolutionException|Throwable
     * @param array<int, mixed> $parameters
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        $this->initializeAdvancedFeatures();
        $startTime = microtime(true);
        $description = $this->describeCallback($callback);

        try {
            $this->event->dispatch(new ResolutionStarting($description));
            $result = $this->methodInjector->call($callback, $parameters, $this);
            $this->event->dispatch(new ResolutionDone(abstract: $description, instance: $result));
            $this->profiler->recordMethodInjection(microtime(true) - $startTime);
            return $result;
        } catch (Throwable $e) {
            $this->event->dispatch(new ResolutionFailed(abstract: $description, exception: $e));
            throw $e;
        }
    }

    /**
     * @param array<int, string> $parameters
     */
    public function callStatic(string $class, string $method, array $parameters = []): mixed
    {
        $this->initializeAdvancedFeatures();

        $startTime = microtime(true);

        try {
            $result = $this->methodInjector->callStatic($class, $method, $parameters, $this);
            $this->profiler->recordMethodInjection(microtime(true) - $startTime);
            return $result;
        } catch (Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to call static method {$class}::{$method}",
                "{$class}::{$method}",
                null,
                [
                    "Verify that the method exists and is static",
                    "Check that all required dependencies can be resolved",
                    "Ensure the method is public"
                ],
                $e
            );
        }
    }

    public function resolveAll(string $abstract): array
    {
        $this->initializeAdvancedFeatures();
        return $this->multiResolver->resolveAll($abstract, $this);
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function scoped(array $bindings, callable $callback): mixed
    {
        $this->initializeAdvancedFeatures();
        return $this->scopedContext->scoped($bindings, $callback, $this);
    }

    public function lazy(string $abstract): LazyProxy
    {
        $this->initializeAdvancedFeatures();
        return new LazyResolutionProxy($abstract, $this);
    }

    public function mock(string $abstract, mixed $mock): void
    {
        $this->initializeAdvancedFeatures();
        $this->mockManager->addMock($abstract, $mock);
    }

    /**
     * @throws ContainerResolutionException
     */
    public function spy(string $abstract): ServiceSpy
    {
        $this->initializeAdvancedFeatures();

        if (!isset($this->spies[$abstract])) {
            $instance = $this->get($abstract);
            $this->spies[$abstract] = new ServiceSpy($abstract, $instance);
        }

        return $this->spies[$abstract];
    }

    public function getResolutionHistory(): array
    {
        return $this->resolutionHistory;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getBindings(): array
    {
        $this->initializeAdvancedFeatures();

        $bindings = [];

        foreach ($this->bindings as $abstract => $binding) {
            $bindings[$abstract] = [
                'type' => 'binding',
                'shared' => $binding['shared'],
                'concrete_class' => $binding['concrete_class'] ?? null,
                'tags' => $this->taggedBindings->getTagsFor($abstract),
                'has_decorators' => $this->decoratorChain->hasDecorators($abstract),
                'has_contextual' => $this->contextualBindings->hasContextualBinding($abstract)
            ];
        }

        return $bindings;
    }

    /**
     * @return array{abstract: string, dependencies: array<int, array{name: string, type: string, optional: bool, has_default: bool}>, is_bound: bool, tags: array<string>}|array{abstract: string, error: string, dependencies: array<never>}
     */
    public function getDependencies(string $abstract): array
    {
        $this->initializeAdvancedFeatures();

        try {
            $dependencies = [];

            if (class_exists($abstract)) {
                $reflection = new ReflectionClass($abstract);
                $constructor = $reflection->getConstructor();

                if ($constructor) {
                    foreach ($constructor->getParameters() as $parameter) {
                        $type = $parameter->getType();
                        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                            $dependencies[] = [
                                'name' => $parameter->getName(),
                                'type' => $type->getName(),
                                'optional' => $parameter->isOptional(),
                                'has_default' => $parameter->isDefaultValueAvailable()
                            ];
                        }
                    }
                }
            }

            return [
                'abstract' => $abstract,
                'dependencies' => $dependencies,
                'is_bound' => $this->has($abstract),
                'tags' => $this->taggedBindings->getTagsFor($abstract)
            ];

        } catch (Throwable $e) {
            return [
                'abstract' => $abstract,
                'error' => $e->getMessage(),
                'dependencies' => []
            ];
        }
    }

    public function validate(): ValidationResult
    {
        $this->initializeAdvancedFeatures();
        return $this->validator->validate($this);
    }

    public function compile(string $path): void
    {
        $this->initializeAdvancedFeatures();

        $compiler = new ContainerCompiler($this);
        $compiler->compile($path);
    }

    public function compilationFingerprint(): string
    {
        $this->initializeAdvancedFeatures();

        $compiler = new ContainerCompiler($this, ['validate_bindings' => false]);
        return $compiler->fingerprint();
    }

    public function getPerformanceMetrics(): PerformanceReport
    {
        return $this->profiler->getReport();
    }

    public function decorate(string $abstract, callable $decorator, int $priority = 0): void
    {
        $this->initializeAdvancedFeatures();
        $this->decoratorChain->addDecorator($abstract, $decorator, $priority);
    }

    public function middleware(string $abstract, callable $middleware): void
    {
        $this->initializeAdvancedFeatures();
        $this->decoratorChain->addMiddleware($abstract, $middleware);
    }

    /**
     * Lazy initialization of advanced features to avoid overhead for simple usage.
     */
    private function initializeAdvancedFeatures(): void
    {
        if ($this->advancedFeaturesInitialized) {
            return;
        }

        $this->contextualBindings = new ContextualBindingManager();
        $this->taggedBindings = new TaggedBindingRegistry();
        $this->methodInjector = new MethodInjector($this->reflectionCache);
        $this->multiResolver = new MultiResolutionManager();
        $this->scopedContext = new ScopedBindingContext();
        $this->validator = new ContainerValidator();
        $this->mockManager = new MockBindingManager();
        $this->decoratorChain = new DecoratorChain();

        $this->advancedFeaturesInitialized = true;
    }

    /**
     * Record a service resolution for performance tracking and debugging.
     * Operates as a ring-buffer capped at $resolutionHistoryLimit entries.
     */
    private function recordResolution(string $abstract, float $startTime, bool $fromMock): void
    {
        $resolutionTime = (microtime(true) - $startTime) * 1000;

        if (count($this->resolutionHistory) >= $this->resolutionHistoryLimit) {
            array_shift($this->resolutionHistory);
        }

        $this->resolutionHistory[] = [
            'abstract' => $abstract,
            'resolution_time' => $resolutionTime,
            'from_mock' => $fromMock,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true),
        ];

        $this->profiler->recordResolution($abstract, $resolutionTime);

        if (isset($this->spies[$abstract])) {
            $this->spies[$abstract]->recordResolution($resolutionTime);
        }
    }

    /**
     * Get suggestions for resolution failures.
     *
     * @return array<string>
     */
    private function getSuggestionsForResolutionFailure(string $abstract, Throwable $exception): array
    {
        $suggestions = [];

        if (!class_exists($abstract) && !interface_exists($abstract)) {
            $suggestions[] = "Check that the class or interface '{$abstract}' exists and is autoloaded";
        }

        if (!$this->has($abstract)) {
            $suggestions[] = "Register a binding for '{$abstract}' using bind() or singleton()";
        }

        if (class_exists($abstract)) {
            try {
                $reflection = new ReflectionClass($abstract);
                if ($reflection->isAbstract()) {
                    $suggestions[] = "'{$abstract}' is abstract - bind it to a concrete implementation";
                }
                if ($reflection->isInterface()) {
                    $suggestions[] = "'{$abstract}' is an interface - bind it to a concrete implementation";
                }
            } catch (ReflectionException $e) {
                // Ignore reflection errors
            }
        }

        if (str_contains($exception->getMessage(), 'Circular dependency')) {
            $suggestions[] = "Break the circular dependency by using lazy loading or setter injection";
        }

        return $suggestions;
    }
}
