<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Resolution;

use CFXP\Core\Container\LazyProxy;
use CFXP\Core\Container\ContainerInterface;

/**
 * Lazy-loading proxy implementation that defers service instantiation until first access.
 */
class LazyResolutionProxy implements LazyProxy
{
    private mixed $instance = null;
    private bool $resolved = false;

    public function __construct(
        private string $abstract,
        private ContainerInterface $container
    ) {}

    /**
     * Get the proxied instance, resolving it if necessary.
     */
    public function getInstance(): mixed
    {
        if (!$this->resolved) {
            $this->resolve();
        }

        return $this->instance;
    }

    /**
     * Check if the instance has been resolved yet.
     */
    public function isResolved(): bool
    {
        return $this->resolved;
    }

    /**
     * Get the abstract identifier this proxy represents.
     */
    public function getAbstract(): string
    {
        return $this->abstract;
    }

    /**
     * Force resolution of the proxied instance.
     */
    public function resolve(): mixed
    {
        if (!$this->resolved) {
            $this->instance = $this->container->get($this->abstract);
            $this->resolved = true;
        }

        return $this->instance;
    }

    /**
     * Magic method to proxy method calls to the resolved instance.
      * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->getInstance()->$method(...$arguments);
    }

    /**
     * Magic method to proxy property access to the resolved instance.
     */
    public function __get(string $property): mixed
    {
        return $this->getInstance()->$property;
    }

    /**
     * Magic method to proxy property setting to the resolved instance.
     */
    public function __set(string $property, mixed $value): void
    {
        $this->getInstance()->$property = $value;
    }

    /**
     * Magic method to proxy property existence checks to the resolved instance.
     */
    public function __isset(string $property): bool
    {
        return isset($this->getInstance()->$property);
    }

    /**
     * Magic method to proxy property unsetting to the resolved instance.
     */
    public function __unset(string $property): void
    {
        unset($this->getInstance()->$property);
    }

    /**
     * String representation of the proxy.
     */
    public function __toString(): string
    {
        return "LazyProxy[{$this->abstract}" . ($this->resolved ? ', resolved' : ', unresolved') . "]";
    }
}