<?php

declare(strict_types=1);

namespace Denosys\Container;

use Closure;

/**
 * Builder for creating contextual bindings in the container.
 * 
 * Allows different implementations to be resolved based on the requesting context,
 * enabling fine-grained control over dependency resolution.
 */
interface ContextualBindingBuilder
{
    /**
     * Specify what needs to be injected in this context.
     * 
     * @param string $abstract The abstract identifier that is needed
     * @return ContextualBindingBuilder For method chaining
     */
    public function needs(string $abstract): ContextualBindingBuilder;

    /**
     * Specify the concrete implementation to use in this context.
     * 
     * @param string|Closure $implementation The implementation to use
     */
    public function give(string|Closure $implementation): void;

    /**
     * Use all services tagged with the specified tag.
     * 
     * @param string $tag The tag identifying the services to use
     */
    public function giveTagged(string $tag): void;

    /**
     * Use a specific configured implementation.
     * 
     * @param array<string, mixed> $configuration Configuration for the implementation
     */
    public function giveConfigured(array $configuration): void;
}