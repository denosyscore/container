<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

use Closure;

/**
 * Core dependency injection container interface.
 *
 * This is the primary interface for DI operations. Most code should
 * depend on this interface, not the concrete Container class.
 */
interface ContainerInterface extends \Psr\Container\ContainerInterface
{
    /**
     * Register a binding with the container.
     *
     * @param string $abstract
     * @param Closure|string|null $concrete
     * @param bool $shared
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void;

    /**
     * Register a shared binding (singleton) in the container.
     *
     * @param string $abstract
     * @param Closure|string|null $concrete
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void;

    /**
     * Register an instance as shared in the container.
     *
     * @param string $abstract
     * @param mixed $instance
     *
     * @return mixed
     */
    public function instance(string $abstract, mixed $instance): mixed;

    /**
     * Aliases a string to an existing abstract identifier.
     *
     * @param string $alias
     * @param string $abstract
     */
    public function alias(string $alias, string $abstract): void;

    /**
     * Get the target abstract for an alias if it exists.
     *
     * @param string $abstract
     *
     * @return string
     */
    public function getAlias(string $abstract): string;

    /**
     * "Extends" an existing abstract identifier in the container.
     *
     * @param string $abstract
     * @param Closure $concrete
     */
    public function extend(string $abstract, Closure $concrete): void;

    /**
     * Create a contextual binding builder for context-aware dependency resolution.
     *
     * @param string $concrete
     *
     * @return ContextualBindingBuilder
     */
    public function when(string $concrete): ContextualBindingBuilder;

    /**
     * Execute a callback with scoped bindings that are restored afterward.
     *
     * @param array<int|string, mixed> $bindings
     * @param callable $callback
     *
     * @return mixed
     */
    public function scoped(array $bindings, callable $callback): mixed;

    /**
     * Create a lazy proxy for the given abstract.
     *
     * @param string $abstract
     *
     * @return LazyProxy
     */
    public function lazy(string $abstract): LazyProxy;

    /**
     * Register a decorator for a service.
     *
     * @param string $abstract
     * @param callable $decorator
     * @param int $priority
     */
    public function decorate(string $abstract, callable $decorator, int $priority = 0): void;

    /**
     * Apply middleware to a service.
     *
     * @param string $abstract
     * @param callable $middleware
     */
    public function middleware(string $abstract, callable $middleware): void;

    /**
     * Resolve all implementations of an abstract.
     *
     * @param string $abstract
     *
     * @return array<string, mixed>
     */
    public function resolveAll(string $abstract): array;

    /**
     * Set a callback to resolve deferred providers.
     *
     * The callback is invoked when resolving a service that isn't bound.
     * This allows ServiceProviderBootstrapper to lazy-load deferred providers.
     *
     * @param Closure(string): void $resolver
     */
    public function setDeferredResolver(Closure $resolver): void;
}
