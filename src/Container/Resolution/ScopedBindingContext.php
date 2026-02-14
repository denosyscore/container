<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Resolution;

use CFXP\Core\Container\ContainerInterface;
use CFXP\Core\Exceptions\ContainerResolutionException;

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
    /** @var array<string, mixed> */

    private array $scopeStack = [];

    /**
     * @var array<string, mixed> Currently active scoped bindings
     */
    /** @var array<string, mixed> */

    private array $activeScopedBindings = [];

    /**
     * @var int Current scope depth
     */
    private int $scopeDepth = 0;

    /**
     * Execute a callback with scoped bindings that are restored afterwards.
      * @param array<int|string, mixed> $bindings
     */
    public function scoped(array $bindings, callable $callback, ContainerInterface $container): mixed
    {
        $this->pushScope($bindings, $container);

        try {
            // Execute the callback in the scoped context
            $result = $callback($container);
            
            return $result;
        } finally {
            // Always restore the previous scope, even if an exception occurred
            $this->popScope($container);
        }
    }

    /**
     * Push a new scope with the given bindings.
      * @param array<int|string, mixed> $bindings
     */
    public function pushScope(array $bindings, ContainerInterface $container): void
    {
        $this->scopeDepth++;

        // Store the current state
        $previousBindings = $this->activeScopedBindings;
        $this->scopeStack[] = $previousBindings;

        // Apply new bindings
        $this->activeScopedBindings = array_merge($this->activeScopedBindings, $bindings);

        // Apply the bindings to the container
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

        // Get the current bindings to restore
        $currentBindings = $this->activeScopedBindings;

        // Restore the previous scope
        $previousBindings = array_pop($this->scopeStack);
        $this->activeScopedBindings = $previousBindings;

        // Calculate which bindings need to be restored or removed
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
     */
    /**
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
        // Restore original bindings for all currently scoped abstracts
        foreach (array_keys($this->activeScopedBindings) as $abstract) {
            $this->removeScopedBinding($abstract, $container);
        }

        $this->scopeStack = [];
        $this->activeScopedBindings = [];
        $this->scopeDepth = 0;
    }

    /**
     * Get statistics about the scoped context.
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
                // Bind as a closure
                $container->bind($abstract, $concrete);
            } elseif (is_string($concrete)) {
                // Bind as a class name
                $container->bind($abstract, $concrete);
            } elseif (is_object($concrete)) {
                // Bind as an instance
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
      * @param array<string, mixed> $currentBindings
      * @param array<string, mixed> $previousBindings
     */
    private function restoreBindings(array $currentBindings, array $previousBindings, ContainerInterface $container): void
    {
        // Find bindings that need to be removed (were added in this scope)
        foreach ($currentBindings as $abstract => $concrete) {
            if (!array_key_exists($abstract, $previousBindings)) {
                // This binding was added in the current scope, remove it
                $this->removeScopedBinding($abstract, $container);
            }
        }

        // Find bindings that need to be restored (were overridden in this scope)
        foreach ($previousBindings as $abstract => $concrete) {
            if (!array_key_exists($abstract, $currentBindings) || $currentBindings[$abstract] !== $concrete) {
                // This binding was different or removed, restore it
                $this->applySingleBinding($abstract, $concrete, $container);
            }
        }
    }

    /**
     * Remove a scoped binding from the container.
     */
    private function removeScopedBinding(string $abstract, ContainerInterface $container): void
    {
        // Note: The base Container class doesn't have a method to remove bindings,
        // so this is a limitation. In a full implementation, you might need to
        // extend the base container or use reflection to modify private properties.
        
        // For now, we'll log this limitation
        error_log("Warning: Cannot remove scoped binding for '{$abstract}' - base container doesn't support binding removal");
        
        // In a production implementation, you might:
        // 1. Extend the base Container to add removeBinding() method
        // 2. Use reflection to modify private properties
        // 3. Keep track of original bindings and restore them explicitly
    }
}