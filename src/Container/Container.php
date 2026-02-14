<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

use Throwable;
use ReflectionUnionType;
use ReflectionNamedType;
use CFXP\Core\Events\Dispatcher;
use CFXP\Core\Events\ListenerProvider;
use CFXP\Core\Container\Events\ResolutionDone;
use CFXP\Core\Container\Events\ResolutionFailed;
use CFXP\Core\Container\Events\BindingRegistered;
use CFXP\Core\Container\Events\ResolutionStarting;
use CFXP\Core\Container\Binding\ContextualBindingManager;
use CFXP\Core\Container\Binding\TaggedBindingRegistry;
use CFXP\Core\Container\Binding\ConditionalBindingResolver;
use CFXP\Core\Container\Injection\MethodInjector;
use CFXP\Core\Container\Resolution\MultiResolutionManager;
use CFXP\Core\Container\Resolution\ScopedBindingContext;
use CFXP\Core\Container\Resolution\LazyResolutionProxy;
use CFXP\Core\Container\Performance\ReflectionCache;
use CFXP\Core\Container\Performance\PerformanceProfiler;
use CFXP\Core\Container\Performance\ContainerCompiler;
use CFXP\Core\Container\Validation\ContainerValidator;
use CFXP\Core\Container\Testing\MockBindingManager;
use CFXP\Core\Container\Decorators\DecoratorChain;
use CFXP\Core\Exceptions\ContainerResolutionException;
use CFXP\Core\Exceptions\NotFoundException;
use CFXP\Core\Exceptions\ContainerException;
use ReflectionException;
use Closure;

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
     */
    /** @var array<string, mixed> */

    private array $resolutionHistory = [];

    /**
     * Service spies for testing.
     */
    /** @var array<string, mixed> */

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
     * Binds an abstract identifier to a concrete implementation.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $this->dropStaleInstances($abstract);

        if (null === $concrete) {
            $concrete = $abstract;
        }

        if (!$concrete instanceof Closure) {
            $concrete = fn() => $this->resolve($concrete);
        }

        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];

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

            $this->bindings[$abstract]['concrete'] = function($container) use ($extender, $originalConcrete) {
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
     * @throws ContainerException|ContainerResolutionException
     */
    /**
     * @return array<string, mixed>
      * @param array<int, mixed> $parameters
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
        $originalAbstract = $id;

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
     * @throws ContainerResolutionException|Throwable
      * @param array<int, mixed> $parameters
     */
    public function call(callable $callback, array $parameters = []): mixed
    {
        $this->initializeAdvancedFeatures();

        $startTime = microtime(true);

        try {
            $this->event->dispatch(new ResolutionStarting());

            $result = $this->methodInjector->call($callback, $parameters, $this);

            $this->event->dispatch(new ResolutionDone());

            $this->profiler->recordMethodInjection(microtime(true) - $startTime);

            return $result;
        } catch (Throwable $e) {
            $this->event->dispatch(new ResolutionFailed());

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
                'tags' => $this->taggedBindings->getTagsFor($abstract),
                'has_decorators' => $this->decoratorChain->hasDecorators($abstract),
                'has_contextual' => $this->contextualBindings->hasContextualBinding($abstract)
            ];
        }

        return $bindings;
    }

    /**
     * @return array<string>
     */
    public function getDependencies(string $abstract): array
    {
        $this->initializeAdvancedFeatures();

        try {
            $dependencies = [];

            if (class_exists($abstract)) {
                $reflection = new \ReflectionClass($abstract);
                $constructor = $reflection->getConstructor();

                if ($constructor) {
                    foreach ($constructor->getParameters() as $parameter) {
                        $type = $parameter->getType();
                        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
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
     */
    private function recordResolution(string $abstract, float $startTime, bool $fromMock): void
    {
        $resolutionTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        $this->resolutionHistory[] = [
            'abstract' => $abstract,
            'resolution_time' => $resolutionTime,
            'from_mock' => $fromMock,
            'timestamp' => microtime(true),
            'memory_usage' => memory_get_usage(true)
        ];

        $this->profiler->recordResolution($abstract, $resolutionTime);

        // Record in spy if one exists
        if (isset($this->spies[$abstract])) {
            $this->spies[$abstract]->recordResolution($resolutionTime);
        }
    }

    /**
     * Get suggestions for resolution failures.
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
                $reflection = new \ReflectionClass($abstract);
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
