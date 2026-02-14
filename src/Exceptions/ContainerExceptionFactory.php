<?php

declare(strict_types=1);

namespace Denosys\Container\Exceptions;

use Throwable;

/**
 * Factory for creating container resolution exceptions with appropriate suggestions.
 */
class ContainerExceptionFactory
{
    /**
     * Create a container resolution exception with suggestions.
     *
     * @param string $message The exception message
     * @param string|null $abstract The abstract identifier
     * @param array<string>|null $resolutionStack The resolution stack
     * @param array<string, mixed> $containerState Container state for suggestion generation
     * @param Throwable|null $previous Previous exception
     * @return ContainerResolutionException
     */
    public static function createResolutionException(
        string $message,
        ?string $abstract = null,
        ?array $resolutionStack = null,
        array $containerState = [],
        ?Throwable $previous = null
    ): ContainerResolutionException {
        $suggestions = null;
        
        if ($abstract !== null) {
            $suggestions = SuggestionEngine::generateSuggestions(
                $abstract,
                $previous,
                $resolutionStack ?? [],
                $containerState
            );
        }

        return new ContainerResolutionException(
            $message,
            $abstract,
            $resolutionStack,
            $suggestions,
            $previous
        );
    }

    /**
     * Create a circular dependency exception.
     *
     * @param string $abstract The abstract that completed the circular dependency
     * @param array<string> $dependencyChain The dependency chain
     * @param array<string>|null $customSuggestions Custom suggestions
     * @param Throwable|null $previous Previous exception
     * @return CircularDependencyException
     */
    public static function createCircularDependencyException(
        string $abstract,
        array $dependencyChain,
        ?array $customSuggestions = null,
        ?Throwable $previous = null
    ): CircularDependencyException {
        return new CircularDependencyException(
            $abstract,
            $dependencyChain,
            $customSuggestions,
            $previous
        );
    }

    /**
     * Create a binding conflict exception.
     *
     * @param string $abstract The abstract with the binding conflict
     * @param string $conflictType The type of conflict
     * @param array<string, mixed> $conflictDetails Details about the conflict
     * @param array<string>|null $customSuggestions Custom suggestions
     * @param Throwable|null $previous Previous exception
     * @return BindingConflictException
     */
    public static function createBindingConflictException(
        string $abstract,
        string $conflictType,
        array $conflictDetails = [],
        ?array $customSuggestions = null,
        ?Throwable $previous = null
    ): BindingConflictException {
        return new BindingConflictException(
            $abstract,
            $conflictType,
            $conflictDetails,
            $customSuggestions,
            $previous
        );
    }

    /**
     * Create a not found exception with enhanced suggestions.
     *
     * @param string $abstract The abstract that was not found
     * @param array<string, mixed> $containerState Container state for suggestions
     * @param Throwable|null $previous Previous exception
     * @return ContainerResolutionException
     */
    public static function createNotFoundException(
        string $abstract,
        array $containerState = [],
        ?Throwable $previous = null
    ): ContainerResolutionException {
        $suggestions = SuggestionEngine::getSuggestionsForErrorType('not_found');
        $additionalSuggestions = SuggestionEngine::generateSuggestions(
            $abstract,
            $previous,
            [],
            $containerState
        );

        $allSuggestions = array_unique(array_merge($suggestions, $additionalSuggestions));

        return new ContainerResolutionException(
            "No binding found for '{$abstract}'",
            $abstract,
            null,
            $allSuggestions,
            $previous
        );
    }

    /**
     * Create a parameter resolution exception with suggestions.
     *
     * @param string $parameter The parameter that couldn't be resolved
     * @param string $class The class context
     * @param array<string> $resolutionStack The resolution stack
     * @param Throwable|null $previous Previous exception
     * @return ContainerResolutionException
     */
    public static function createParameterResolutionException(
        string $parameter,
        string $class,
        array $resolutionStack = [],
        ?Throwable $previous = null
    ): ContainerResolutionException {
        $message = "Cannot resolve parameter '{$parameter}' in {$class} constructor";
        $suggestions = SuggestionEngine::getSuggestionsForErrorType('parameter_resolution');

        return new ContainerResolutionException(
            $message,
            $class,
            $resolutionStack,
            $suggestions,
            $previous
        );
    }

    /**
     * Create an instantiation exception with suggestions.
     *
     * @param string $class The class that couldn't be instantiated
     * @param string $reason The reason for the failure
     * @param array<string> $resolutionStack The resolution stack
     * @param Throwable|null $previous Previous exception
     * @return ContainerResolutionException
     */
    public static function createInstantiationException(
        string $class,
        string $reason,
        array $resolutionStack = [],
        ?Throwable $previous = null
    ): ContainerResolutionException {
        $message = "Cannot instantiate '{$class}': {$reason}";
        $suggestions = SuggestionEngine::getSuggestionsForErrorType('not_instantiable');

        return new ContainerResolutionException(
            $message,
            $class,
            $resolutionStack,
            $suggestions,
            $previous
        );
    }
}