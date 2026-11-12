<?php

declare(strict_types=1);

namespace Denosys\Container;

use Closure;
use Psr\Container\ContainerInterface as PsrContainerInterface;

/**
 * A PSR-11 container with explicit bindings and constructor autowiring.
 */
interface ContainerInterface extends PsrContainerInterface
{
    /**
     * Bind an identifier to a class name or a factory. A factory receives the
     * container. Without a concrete, the identifier is autowired as a class.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void;

    /**
     * Bind an identifier that is built once and then reused.
     */
    public function singleton(string $abstract, Closure|string|null $concrete = null): void;

    /**
     * Register an existing value under an identifier.
     */
    public function instance(string $abstract, mixed $instance): mixed;

    /**
     * Make an alias resolve to another identifier.
     */
    public function alias(string $alias, string $abstract): void;

    /**
     * The identifier an alias points to, or the identifier itself.
     */
    public function getAlias(string $abstract): string;

    /**
     * Wrap a bound service: the closure receives the service and the container
     * and returns the service to use.
     */
    public function extend(string $abstract, Closure $concrete): void;

    /**
     * Called with an identifier the container does not know yet, so a host
     * can register a provider for it on demand.
     */
    public function setDeferredResolver(Closure $resolver): void;
}
