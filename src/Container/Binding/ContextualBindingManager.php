<?php

declare(strict_types=1);

namespace Denosys\Container\Binding;

use Denosys\Container\ContextualBindingBuilder;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerResolutionException;
use Closure;

/**
 * Manages contextual bindings for the enhanced container.
 * 
 * Contextual bindings allow different implementations to be resolved based on
 * the requesting class context, enabling fine-grained dependency control.
 */
class ContextualBindingManager
{
    /**
     * @var array<string, array<string, mixed>> Contextual bindings
     * Format: [concrete_class => [abstract => implementation]]
     */
    /** @var array<string, mixed> */

    private array $contextualBindings = [];

    /**
     * @var array<string> Current resolution stack for context tracking
     */
    /** @var array<string, mixed> */

    private array $resolutionContext = [];

    /**
     * Create a contextual binding builder.
     */
    public function when(string $concrete): ContextualBindingBuilder
    {
        return new ContextualBindingBuilderImplementation($concrete, $this);
    }

    /**
     * Register a contextual binding.
     */
    public function addContextualBinding(string $concrete, string $abstract, mixed $implementation): void
    {
        if (!isset($this->contextualBindings[$concrete])) {
            $this->contextualBindings[$concrete] = [];
        }
        
        $this->contextualBindings[$concrete][$abstract] = $implementation;
    }

    /**
     * Check if there's a contextual binding for the given abstract in current context.
     */
    public function hasContextualBinding(string $abstract): bool
    {
        $context = $this->getCurrentContext();
        
        if ($context === null) {
            return false;
        }

        return isset($this->contextualBindings[$context][$abstract]);
    }

    /**
     * Resolve using contextual binding.
     */
    public function resolve(string $abstract, Container $container): mixed
    {
        $context = $this->getCurrentContext();
        
        if ($context === null || !isset($this->contextualBindings[$context][$abstract])) {
            throw new ContainerResolutionException(
                "No contextual binding found for '{$abstract}' in context '{$context}'",
                $abstract,
                $this->resolutionContext,
                ['Register a contextual binding using when()->needs()->give()']
            );
        }

        $implementation = $this->contextualBindings[$context][$abstract];

        return $this->resolveImplementation($implementation, $container);
    }

    /**
     * Set the current resolution context.
     */
    public function pushContext(string $context): void
    {
        $this->resolutionContext[] = $context;
    }

    /**
     * Remove the current resolution context.
     */
    public function popContext(): void
    {
        array_pop($this->resolutionContext);
    }

    /**
     * Get the current resolution context.
     */
    public function getCurrentContext(): ?string
    {
        return end($this->resolutionContext) ?: null;
    }

    /**
     * Get all contextual bindings.
     */
    /**
     * @return array<string, mixed>
     */
public function getBindings(): array
    {
        return $this->contextualBindings;
    }

    /**
     * Get contextual bindings for a specific concrete class.
      * @return array<string, callable>
     */
    public function getBindingsFor(string $concrete): array
    {
        return $this->contextualBindings[$concrete] ?? [];
    }

    /**
     * Check if a concrete class has any contextual bindings.
     */
    public function hasBindingsFor(string $concrete): bool
    {
        return isset($this->contextualBindings[$concrete]) && !empty($this->contextualBindings[$concrete]);
    }

    /**
     * Remove all contextual bindings for a concrete class.
     */
    public function removeBindingsFor(string $concrete): void
    {
        unset($this->contextualBindings[$concrete]);
    }

    /**
     * Remove a specific contextual binding.
     */
    public function removeBinding(string $concrete, string $abstract): void
    {
        unset($this->contextualBindings[$concrete][$abstract]);
        
        // Clean up empty arrays
        if (empty($this->contextualBindings[$concrete])) {
            unset($this->contextualBindings[$concrete]);
        }
    }

    /**
     * Get statistics about contextual bindings.
      * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $totalConcreteClasses = count($this->contextualBindings);
        $totalBindings = 0;
        
        foreach ($this->contextualBindings as $bindings) {
            $totalBindings += count($bindings);
        }

        return [
            'total_concrete_classes' => $totalConcreteClasses,
            'total_contextual_bindings' => $totalBindings,
            'average_bindings_per_class' => $totalConcreteClasses > 0 ? $totalBindings / $totalConcreteClasses : 0,
            'current_context' => $this->getCurrentContext(),
            'context_depth' => count($this->resolutionContext)
        ];
    }

    /**
     * Resolve the actual implementation from the binding specification.
     */
    private function resolveImplementation(mixed $implementation, Container $container): mixed
    {
        // Handle different types of implementations
        if ($implementation instanceof Closure) {
            return $implementation($container);
        }

        if (is_string($implementation)) {
            return $container->get($implementation);
        }

        if (is_array($implementation)) {
            // Handle special implementations like tagged services
            if (isset($implementation['tagged'])) {
                return $container->tagged($implementation['tagged']);
            }

            if (isset($implementation['configured'])) {
                // Handle configured implementations
                $config = $implementation['configured'];
                $className = $config['class'] ?? null;
                
                if ($className === null) {
                    throw new ContainerResolutionException(
                        'Configured implementation must specify a class',
                        null,
                        $this->resolutionContext,
                        ['Add a "class" key to the configuration array']
                    );
                }

                // Create instance with configuration
                $instance = $container->get($className);
                
                // Apply configuration if the instance supports it
                if (method_exists($instance, 'configure')) {
                    $instance->configure($config);
                }

                return $instance;
            }
        }

        // If it's already an instance, return it
        if (is_object($implementation)) {
            return $implementation;
        }

        throw new ContainerResolutionException(
            'Invalid contextual binding implementation type',
            null,
            $this->resolutionContext,
            [
                'Use a string class name, Closure, or array configuration',
                'Ensure the implementation can be resolved by the container'
            ]
        );
    }
}

/**
 * Implementation of the contextual binding builder.
 */
class ContextualBindingBuilderImplementation implements ContextualBindingBuilder
{
    private ?string $needs = null;

    public function __construct(
        private string $concrete,
        private ContextualBindingManager $manager
    ) {}

    public function needs(string $abstract): ContextualBindingBuilder
    {
        $this->needs = $abstract;
        return $this;
    }

    public function give(string|Closure $implementation): void
    {
        if ($this->needs === null) {
            throw new \InvalidArgumentException('Must call needs() before give()');
        }

        $this->manager->addContextualBinding($this->concrete, $this->needs, $implementation);
    }

    public function giveTagged(string $tag): void
    {
        if ($this->needs === null) {
            throw new \InvalidArgumentException('Must call needs() before giveTagged()');
        }

        $this->manager->addContextualBinding($this->concrete, $this->needs, ['tagged' => $tag]);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function giveConfigured(array $configuration): void
    {
        if ($this->needs === null) {
            throw new \InvalidArgumentException('Must call needs() before giveConfigured()');
        }

        $this->manager->addContextualBinding($this->concrete, $this->needs, ['configured' => $configuration]);
    }
}