<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Resolution;

use CFXP\Core\Container\ContainerInterface;
use CFXP\Core\Exceptions\ContainerResolutionException;

/**
 * Manages resolution of multiple implementations for interfaces and abstract classes.
 * 
 * Enables dependency injection patterns where you need all implementations of an interface,
 * such as event listeners, middleware, or plugin systems.
 */
class MultiResolutionManager
{
    /** @var array<string, array<string>> Explicitly registered multiple implementations */
    private array $multiBindings = [];

    /** @var array<string, int> Priority order for implementations */
    private array $implementationPriorities = [];

    /** @var array<string, array<string, mixed>> Implementation metadata */
    private array $implementationMetadata = [];

    /**
     * @var bool Whether to auto-discover implementations
     */
    private bool $autoDiscovery = true;

    /**
     * Resolve all implementations of an abstract.
     *
     * @return array<object>
     */
    public function resolveAll(string $abstract, ContainerInterface $container): array
    {
        $implementations = $this->getAllImplementations($abstract, $container);
        $resolved = [];
        $errors = [];

        foreach ($implementations as $implementation) {
            try {
                $instance = $container->get($implementation);
                $resolved[] = $instance;
            } catch (\Throwable $e) {
                $errors[] = [
                    'implementation' => $implementation,
                    'error' => $e->getMessage()
                ];
                
                // Log the error but continue with other implementations
                error_log("Failed to resolve implementation '{$implementation}' for '{$abstract}': " . $e->getMessage());
            }
        }

        // If no implementations were resolved and we have errors, throw an exception
        if (empty($resolved) && !empty($errors)) {
            $errorMessages = array_map(fn($error) => (string) $error['implementation'] . ': ' . (string) $error['error'], $errors);
            
            throw new ContainerResolutionException(
                "Failed to resolve any implementations for '{$abstract}'",
                $abstract,
                null,
                [
                    'Check that implementations are properly bound',
                    'Verify that implementation classes exist and are autoloaded',
                    'Errors: ' . implode(', ', $errorMessages)
                ]
            );
        }

        return $resolved;
    }

    /**
     * Add an implementation for multi-resolution.
      * @param array<string, mixed> $metadata
     */
    public function addImplementation(string $abstract, string $implementation, int $priority = 0, array $metadata = []): void
    {
        if (!isset($this->multiBindings[$abstract])) {
            $this->multiBindings[$abstract] = [];
        }
        
        if (!in_array($implementation, $this->multiBindings[$abstract], true)) {
            $this->multiBindings[$abstract][] = $implementation;
        }

        // Set priority
        $this->implementationPriorities["{$abstract}::{$implementation}"] = $priority;
        
        // Set metadata
        if (!empty($metadata)) {
            $this->implementationMetadata["{$abstract}::{$implementation}"] = $metadata;
        }

        // Re-sort implementations by priority
        $this->sortImplementations($abstract);
    }

    /**
     * Remove an implementation.
     */
    public function removeImplementation(string $abstract, string $implementation): void
    {
        if (!isset($this->multiBindings[$abstract])) {
            return;
        }

        $key = array_search($implementation, $this->multiBindings[$abstract], true);
        if ($key !== false) {
            array_splice($this->multiBindings[$abstract], $key, 1);
        }

        // Clean up related data
        unset($this->implementationPriorities["{$abstract}::{$implementation}"]);
        unset($this->implementationMetadata["{$abstract}::{$implementation}"]);

        // Clean up empty arrays
        if (empty($this->multiBindings[$abstract])) {
            unset($this->multiBindings[$abstract]);
        }
    }

    /**
     * Get all registered implementations for an abstract.
      * @return array<string>
     */
    public function getImplementations(string $abstract): array
    {
        return $this->multiBindings[$abstract] ?? [];
    }

    /**
     * Check if an abstract has multiple implementations.
     */
    public function hasMultipleImplementations(string $abstract): bool
    {
        return isset($this->multiBindings[$abstract]) && count($this->multiBindings[$abstract]) > 1;
    }

    /**
     * Get implementations with their priorities and metadata.
      * @return array<string, mixed>
     */
    public function getImplementationDetails(string $abstract): array
    {
        $implementations = $this->getImplementations($abstract);
        $details = [];

        foreach ($implementations as $implementation) {
            $key = "{$abstract}::{$implementation}";
            $details[] = [
                'class' => $implementation,
                'priority' => $this->implementationPriorities[$key] ?? 0,
                'metadata' => $this->implementationMetadata[$key] ?? []
            ];
        }

        return $details;
    }

    /**
     * Set priority for an implementation.
     */
    public function setPriority(string $abstract, string $implementation, int $priority): void
    {
        $this->implementationPriorities["{$abstract}::{$implementation}"] = $priority;
        $this->sortImplementations($abstract);
    }

    /**
     * Set metadata for an implementation.
      * @param array<string, mixed> $metadata
     */
    public function setMetadata(string $abstract, string $implementation, array $metadata): void
    {
        $this->implementationMetadata["{$abstract}::{$implementation}"] = $metadata;
    }

    /**
     * Enable or disable auto-discovery of implementations.
     */
    public function setAutoDiscovery(bool $enabled): void
    {
        $this->autoDiscovery = $enabled;
    }

    /**
     * Get statistics about multi-resolution.
      * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $totalAbstracts = count($this->multiBindings);
        $totalImplementations = 0;
        $maxImplementations = 0;
        
        foreach ($this->multiBindings as $implementations) {
            $count = count($implementations);
            $totalImplementations += $count;
            $maxImplementations = max($maxImplementations, $count);
        }

        return [
            'total_abstracts' => $totalAbstracts,
            'total_implementations' => $totalImplementations,
            'average_implementations_per_abstract' => $totalAbstracts > 0 ? $totalImplementations / $totalAbstracts : 0,
            'max_implementations_per_abstract' => $maxImplementations,
            'auto_discovery_enabled' => $this->autoDiscovery
        ];
    }

    /**
     * Clear all multi-resolution data.
     */
    public function clear(): void
    {
        $this->multiBindings = [];
        $this->implementationPriorities = [];
        $this->implementationMetadata = [];
    }

    /**
     * Get all implementations including auto-discovered ones.
     *
     * @return array<string>
     */
    private function getAllImplementations(string $abstract, ContainerInterface $container): array
    {
        $implementations = $this->getImplementations($abstract);

        // Add auto-discovered implementations if enabled
        if ($this->autoDiscovery) {
            $discovered = $this->autoDiscoverImplementations($abstract, $container);
            $implementations = array_unique(array_merge($implementations, $discovered));
        }

        // Add tagged implementations if the container supports it
        if (method_exists($container, 'tagged')) {
            try {
                $taggedServices = $container->tagged($abstract);
                foreach ($taggedServices as $service) {
                    $className = get_class($service);
                    if (!in_array($className, $implementations, true)) {
                        $implementations[] = $className;
                    }
                }
            } catch (\Throwable $e) {
                // Ignore errors from tagged resolution
            }
        }

        return $implementations;
    }

    /**
     * Auto-discover implementations of an interface or abstract class.
     *
     * @return array<class-string>
     */
    private function autoDiscoverImplementations(string $abstract, ContainerInterface $container): array
    {
        $implementations = [];

        // Only auto-discover for interfaces and abstract classes
        if (!interface_exists($abstract) && !$this->isAbstractClass($abstract)) {
            return $implementations;
        }

        // Get declared classes and check which ones implement the interface/extend the abstract
        $declaredClasses = get_declared_classes();
        
        foreach ($declaredClasses as $class) {
            try {
                $reflection = new \ReflectionClass($class);
                
                // Skip abstract classes and interfaces
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }

                // Check if the class implements the interface or extends the abstract class
                if ($reflection->implementsInterface($abstract) || $reflection->isSubclassOf($abstract)) {
                    // Only include if the container can resolve it
                    if ($container->has($class)) {
                        $implementations[] = $class;
                    }
                }
            } catch (\ReflectionException $e) {
                // Skip classes that can't be reflected
                continue;
            }
        }

        return $implementations;
    }

    /**
     * Check if a class is abstract.
     */
    private function isAbstractClass(string $class): bool
    {
        try {
            $reflection = new \ReflectionClass($class);
            return $reflection->isAbstract() && !$reflection->isInterface();
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * Sort implementations by priority (higher priority first).
     */
    private function sortImplementations(string $abstract): void
    {
        if (!isset($this->multiBindings[$abstract])) {
            return;
        }

        usort($this->multiBindings[$abstract], function ($a, $b) use ($abstract) {
            $priorityA = $this->implementationPriorities["{$abstract}::{$a}"] ?? 0;
            $priorityB = $this->implementationPriorities["{$abstract}::{$b}"] ?? 0;
            
            // Higher priority comes first
            return $priorityB <=> $priorityA;
        });
    }
}