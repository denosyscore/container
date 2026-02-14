<?php

declare(strict_types=1);

namespace CFXP\Core\Exceptions;

use Throwable;

/**
 * Exception thrown when a circular dependency is detected during resolution.
 * Provides detailed information about the dependency chain and suggestions for resolution.
 */
class CircularDependencyException extends ContainerResolutionException
{
    /**
     * @param string $abstract The abstract identifier that completed the circular dependency
     * @param array<string> $dependencyChain The chain of dependencies leading to the circular reference
     * @param array<string>|null $suggestions Optional custom suggestions for resolving the circular dependency
     * @param Throwable|null $previous Previous exception in the chain
      * @param array<string, mixed> $suggestions
     */
    public function __construct(
        string $abstract,
        /**
         * @param array<string, mixed> $dependencyChain
         * @param array<string, mixed> $suggestions
         */
        public readonly array $dependencyChain,
        ?array $suggestions = null,
        ?Throwable $previous = null
    ) {
        $chain = implode(' -> ', $dependencyChain) . ' -> ' . $abstract;
        
        $defaultSuggestions = [
            'Consider using lazy loading for one of the dependencies',
            'Break the circular dependency by introducing an interface or abstraction',
            'Use setter injection instead of constructor injection for one of the dependencies',
            'Consider if the circular dependency indicates a design issue that should be refactored'
        ];

        $finalSuggestions = $suggestions ?? $defaultSuggestions;

        parent::__construct(
            "Circular dependency detected: {$chain}",
            $abstract,
            $dependencyChain,
            $finalSuggestions,
            $previous
        );
    }

    /**
     * Get the complete dependency chain that led to the circular dependency.
     *
     * @return array<string>
     */
    /**
     * @return array<string, mixed>
     */
public function getDependencyChain(): array
    {
        return $this->dependencyChain;
    }

    /**
     * Get the full circular dependency path including the completing abstract.
     */
    public function getFullCircularPath(): string
    {
        return implode(' -> ', $this->dependencyChain) . ' -> ' . $this->abstract;
    }

    /**
     * Check if a specific abstract is part of the circular dependency chain.
     */
    public function isInCircularChain(string $abstract): bool
    {
        return in_array($abstract, $this->dependencyChain, true) || $abstract === $this->abstract;
    }

    /**
     * Get the length of the circular dependency chain.
     */
    public function getChainLength(): int
    {
        return count($this->dependencyChain) + 1; // +1 for the completing abstract
    }
}