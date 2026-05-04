<?php

declare(strict_types=1);

namespace Denosys\Container\Resolution;

use Closure;
use Throwable;
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
     * @var array<array<string, mixed>> Stack of scoped binding contexts.
     * Each frame has two keys:
     *   'previous'   => the activeScopedBindings array before this scope was pushed
     *   'snapshots'  => pre-scope container state keyed by abstract:
     *                   ['existed' => bool, 'binding' => array|null]
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
     *
     * For each abstract being overridden, snapshot its pre-scope container state
     * so that popScope() can accurately restore or remove it.
     *
     * @param array<int|string, mixed> $bindings
     */
    public function pushScope(array $bindings, ContainerInterface $container): void
    {
        $this->scopeDepth++;

        // Snapshot the actual pre-scope container state for every abstract we're about to override.
        $snapshots = [];
        foreach ($bindings as $abstract => $concrete) {
            $containerBindings = $container->getContainerBindings();
            if (array_key_exists($abstract, $containerBindings)) {
                $snapshots[$abstract] = [
                    'existed' => true,
                    'binding'  => $containerBindings[$abstract],
                ];
            } else {
                $snapshots[$abstract] = [
                    'existed' => false,
                    'binding'  => null,
                ];
            }
        }

        // Store the current state alongside the snapshots
        $this->scopeStack[] = [
            'previous'  => $this->activeScopedBindings,
            'snapshots' => $snapshots,
        ];

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

        // Retrieve the frame we saved in pushScope()
        $frame = array_pop($this->scopeStack);
        $previousBindings = $frame['previous'];
        $snapshots        = $frame['snapshots'];

        // Restore the activeScopedBindings tracking array
        $this->activeScopedBindings = $previousBindings;

        // Restore the container to its pre-scope state for every snapshotted abstract
        $this->restoreBindingsFromSnapshots($snapshots, $container);
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
            if ($concrete instanceof Closure) {
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
        } catch (Throwable $e) {
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
     * Restore the container to its pre-scope state using the snapshots captured in pushScope().
     *
     * For each snapshotted abstract:
     *   - If it existed before the scope: re-bind it with its original closure and shared flag.
     *   - If it did not exist before the scope: remove the binding so it is no longer present.
     *
     * @param array<string, array{existed: bool, binding: array|null}> $snapshots
     */
    private function restoreBindingsFromSnapshots(array $snapshots, ContainerInterface $container): void
    {
        foreach ($snapshots as $abstract => $snapshot) {
            if ($snapshot['existed']) {
                // Re-apply the original binding from the snapshot
                $binding = $snapshot['binding'];
                $container->bind(
                    $abstract,
                    $binding['concrete'],
                    $binding['shared']
                );
            } else {
                // This abstract did not exist before the scope; remove it entirely
                $container->removeBinding($abstract);
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
