<?php

declare(strict_types=1);

namespace Denosys\Container\Exceptions;

use Throwable;

class ContainerExceptionFactory
{
    /**
     * @param array<string> $resolutionStack
     * @param array<string> $suggestions
     */
    public static function createResolutionException(
        string $abstract,
        array $resolutionStack = [],
        ?Throwable $previous = null,
        array $suggestions = []
    ): ContainerResolutionException {
        if (empty($suggestions)) {
            $suggestions = self::generateSuggestions($abstract, $previous);
        }

        return new ContainerResolutionException(
            "Failed to resolve '{$abstract}'." . ($previous ? ' ' . $previous->getMessage() : ''),
            $abstract,
            $resolutionStack,
            $suggestions,
            $previous
        );
    }

    public static function createCircularDependencyException(
        string $abstract,
        array $resolutionStack = []
    ): CircularDependencyException {
        return new CircularDependencyException(
            $abstract,
            $resolutionStack
        );
    }

    public static function createNotFoundException(string $abstract, ?Throwable $previous = null): NotFoundException
    {
        return new NotFoundException(
            "No binding found for '{$abstract}'.",
            0,
            $previous
        );
    }

    public static function createBindingConflictException(
        string $abstract,
        string $conflictReason
    ): BindingConflictException {
        return new BindingConflictException($abstract, $conflictReason);
    }

    /**
     * @return array<string>
     */
    private static function generateSuggestions(string $abstract, ?Throwable $exception): array
    {
        $suggestions = [];

        if (!class_exists($abstract) && !interface_exists($abstract)) {
            $suggestions[] = "Check that '{$abstract}' exists and is autoloaded correctly.";
            return $suggestions;
        }

        if (interface_exists($abstract)) {
            $suggestions[] = "Bind '{$abstract}' to a concrete implementation using \$container->bind() or \$container->singleton().";
            return $suggestions;
        }

        try {
            $reflection = new \ReflectionClass($abstract);
            if ($reflection->isAbstract()) {
                $suggestions[] = "'{$abstract}' is abstract — bind it to a concrete implementation.";
            }
            if (!$reflection->isInstantiable()) {
                $suggestions[] = "'{$abstract}' cannot be instantiated directly.";
            }
        } catch (\ReflectionException) {
            // ignore
        }

        if ($exception !== null && str_contains($exception->getMessage(), 'circular')) {
            $suggestions[] = 'Break the circular dependency using lazy loading or setter injection.';
        }

        if (empty($suggestions)) {
            $suggestions[] = "Register a binding for '{$abstract}' using bind() or singleton().";
        }

        return $suggestions;
    }
}
