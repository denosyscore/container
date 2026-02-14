<?php

declare(strict_types=1);

namespace Denosys\Container\Testing;

use Denosys\Container\Container;
use Denosys\Container\ContainerInterface;

/**
 * Test container builder for creating isolated test environments.
 */
class TestContainerBuilder
{
    /** @var array<string, mixed> */

    private array $mocks = [];
    /** @var array<string, mixed> */

    private array $bindings = [];
    /** @var array<string, mixed> */

    private array $singletons = [];
    /** @var array<string, mixed> */

    private array $eventListeners = [];

    /**
     * Add mocks to the test container.
      * @param array<string, mixed> $mocks
     */
    public function withMocks(array $mocks): self
    {
        $this->mocks = array_merge($this->mocks, $mocks);
        return $this;
    }

    /**
     * Add test-specific bindings.
      * @param array<int|string, mixed> $bindings
     */
    public function withBindings(array $bindings): self
    {
        $this->bindings = array_merge($this->bindings, $bindings);
        return $this;
    }

    /**
     * Add test-specific singletons.
      * @param array<string, mixed> $singletons
     */
    public function withSingletons(array $singletons): self
    {
        $this->singletons = array_merge($this->singletons, $singletons);
        return $this;
    }

    /**
     * Add event listeners for testing.
      * @param array<string, mixed> $listeners
     */
    public function withEventListeners(array $listeners): self
    {
        $this->eventListeners = array_merge($this->eventListeners, $listeners);
        return $this;
    }

    /**
     * Build the test container.
     */
    public function build(): ContainerInterface
    {
        $container = new Container();

        // Apply test-specific bindings
        foreach ($this->bindings as $abstract => $concrete) {
            $container->bind($abstract, $concrete);
        }

        // Apply test-specific singletons
        foreach ($this->singletons as $abstract => $concrete) {
            if (is_string($concrete)) {
                $container->singleton($abstract, $concrete);
            } else {
                $container->singleton($abstract, fn() => $concrete);
            }
        }

        // Apply mocks
        foreach ($this->mocks as $abstract => $mock) {
            $container->mock($abstract, $mock);
        }

        return $container;
    }

    /**
     * Create a quick test container with common test services.
     */
    public static function createQuick(): ContainerInterface
    {
        return (new self())->build();
    }
}