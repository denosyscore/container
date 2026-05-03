<?php

declare(strict_types=1);

namespace Denosys\Container\Resolution;

use Denosys\Container\ContainerInterface;
use Denosys\Container\Exceptions\ContainerResolutionException;

/**
 * Manages scoped binding contexts for temporary binding overrides.
 *
 * Allows temporary binding overrides that are automatically restored
 * after the scoped operation completes, enabling isolated execution contexts.
 */
class ScopedBindingContext
{
    /**
     * @var array<array<string, mixed>> Stack of scoped binding contexts
     */
    private array $scopeStack = [];

    /**
     * @var array<string, mixed> Currently active scoped bindings
     */
    private array $activeScopedBindings = [];

    /**
     * @var int Current scope depth
     */
    private int $scopeDepth = 0;

    /**
     * Execute a callback with scoped bindings that are restored afterwards.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function scoped(array $bindings, callable $callback, ContainerInterface $container): mixed
    {
        $this->pushScope($bindings, $container);

        try {
            $result = $callback($container);

            return $result;
        } finally {
            $this->popScope($container);
        }
    }

    /**
     * Push a new scope with the given bindings.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function pushScope(array $bindings, ContainerInterface $container): void
    {
        $this->scopeDepth++;

        $previousBindings = $this->activeScopedBindings;
        $this->scopeStack[] = $previousBindings;

        $this->activeScopedBindings = array_merge($this->activeScopedBindings, $bindings);

        $this->applyBindings($bindings, $container);
    }

    /**
     * Pop the current scope and restore the previous state.
     */
    public function popScope(ContainerInterface $container): void
    {
        if ($this->scopeDepth === 0) {
            throw new ContainerResolutionException(
                'Cannot pop scope: no active scopes',
                null,
                null,
                ['Ensure pushScope() is called before popScope()']
            );
        }

        $this->scopeDepth--;

        $currentBindings = $this->activeScopedBindings;

        $previousBindings = array_pop($this->scopeStack);
        $this->activeScopedBindings = $previousBindings;

        $this->restoreBindings($currentBindings, $previousBindings, $container);
    }

    /**
     * Get the current scope depth.
     */
    public function getScopeDepth(): int
    {
        return $this->scopeDepth;
    }

    /**
     * Check if currently in a scoped context.
     */
    public function inScope(): bool
    {
        return $this->scopeDepth > 0;
    }

    /**
     * Get the currently active scoped bindings.
     *
     * @return array<string, mixed>
     */
    public function getActiveScopedBindings(): array
    {
        return $this->activeScopedBindings;
    }

    /**
     * Check if a specific binding is currently scoped.
     */
    public function isScopedBinding(string $abstract): bool
    {
        return array_key_exists($abstract, $this->activeScopedBindings);
    }

    /**
     * Get a scoped binding value.
     */
    public function getScopedBinding(string $abstract): mixed
    {
        return $this->activeScopedBindings[$abstract] ?? null;
    }

    /**
     * Clear all scoped contexts (emergency reset).
     */
    public function clearScopes(ContainerInterface $container): void
    {
        foreach (array_keys($this->activeScopedBindings) as $abstract) {
            $this->removeScopedBinding($abstract, $container);
        }

        $this->scopeStack = [];
        $this->activeScopedBindings = [];
        $this->scopeDepth = 0;
    }

    /**
     * Get statistics about the scoped context.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return [
            'scope_depth' => $this->scopeDepth,
            'active_scoped_bindings' => count($this->activeScopedBindings),
            'scope_stack_size' => count($this->scopeStack),
            'in_scope' => $this->inScope(),
            'scoped_abstracts' => array_keys($this->activeScopedBindings)
        ];
    }

    /**
     * Apply bindings to the container.
     *
     * @param array<int|string, mixed> $bindings
     */
    private function applyBindings(array $bindings, ContainerInterface $container): void
    {
        foreach ($bindings as $abstract => $concrete) {
            $this->applySingleBinding($abstract, $concrete, $container);
        }
    }

    /**
     * Apply a single binding to the container.
     */
    private function applySingleBinding(string $abstract, mixed $concrete, ContainerInterface $container): void
    {
        try {
            if ($concrete instanceof \Closure) {
                $container->bind($abstract, $concrete);
            } elseif (is_string($concrete)) {
                $container->bind($abstract, $concrete);
            } elseif (is_object($concrete)) {
                $container->instance($abstract, $concrete);
            } else {
                throw new ContainerResolutionException(
                    "Invalid binding type for '{$abstract}' in scoped context",
                    $abstract,
                    null,
                    [
                        'Use a string class name, Closure, or object instance',
                        'Ensure the binding value is valid for container resolution'
                    ]
                );
            }
        } catch (\Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to apply scoped binding for '{$abstract}': " . $e->getMessage(),
                $abstract,
                null,
                [
                    'Check that the binding value is valid',
                    'Ensure the abstract identifier is correct',
                    'Verify that dependencies can be resolved'
                ],
                $e
            );
        }
    }

    /**
     * Restore bindings when popping a scope.
     *
     * @param array<string, mixed> $currentBindings
     * @param array<string, mixed> $previousBindings
     */
    private function restoreBindings(array $currentBindings, array $previousBindings, ContainerInterface $container): void
    {
        foreach ($currentBindings as $abstract => $concrete) {
            if (!array_key_exists($abstract, $previousBindings)) {
                $this->removeScopedBinding($abstract, $container);
            }
        }

        foreach ($previousBindings as $abstract => $concrete) {
            if (!array_key_exists($abstract, $currentBindings) || $currentBindings[$abstract] !== $concrete) {
                $this->applySingleBinding($abstract, $concrete, $container);
            }
        }
    }

    /**
     * Remove a scoped binding from the container.
     */
    private function removeScopedBinding(string $abstract, ContainerInterface $container): void
    {
        $container->removeBinding($abstract);
    }
}
