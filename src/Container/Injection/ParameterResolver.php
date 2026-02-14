<?php

declare(strict_types=1);

namespace Denosys\Container\Injection;

use Denosys\Container\ContainerInterface;
use Denosys\Container\Performance\ReflectionCache;
use Denosys\Container\Exceptions\ContainerResolutionException;
use ReflectionParameter;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Advanced parameter resolver with support for complex parameter types and resolution strategies.
 */
class ParameterResolver
{
    public function __construct(
        private ReflectionCache $reflectionCache
    ) {}

    /**
     * Resolve parameters for a method or constructor.
     * 
     * @param array<ReflectionParameter> $parameters
     * @param array<string, mixed> $providedParameters
     * @param ContainerInterface $container
     * @return array<mixed>
     */
    /**
     * @return array<string, mixed>
      * @param array<int, mixed> $parameters
      * @param array<string, mixed> $providedParameters
     */
public function resolveParameters(array $parameters, array $providedParameters, ContainerInterface $container): array
    {
        $resolvedParameters = [];

        foreach ($parameters as $index => $parameter) {
            $resolvedParameters[] = $this->resolveParameter($parameter, $providedParameters, $container, $index);
        }

        return $resolvedParameters;
    }

    /**
     * Resolve a single parameter.
      * @param array<string, mixed> $providedParameters
     */
    public function resolveParameter(ReflectionParameter $parameter, array $providedParameters, ContainerInterface $container, int $index): mixed
    {
        $paramName = $parameter->getName();

        // Check if parameter was provided by name
        if (array_key_exists($paramName, $providedParameters)) {
            return $this->validateAndCastParameter($parameter, $providedParameters[$paramName]);
        }

        // Check if parameter was provided by position
        if (array_key_exists($index, $providedParameters)) {
            return $this->validateAndCastParameter($parameter, $providedParameters[$index]);
        }

        // Try to resolve from container
        $resolved = $this->resolveFromContainer($parameter, $container);
        if ($resolved !== null) {
            return $resolved;
        }

        // Use default value if available
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        // Check if parameter is optional (nullable)
        if ($parameter->allowsNull()) {
            return null;
        }

        // Parameter cannot be resolved
        throw new ContainerResolutionException(
            "Cannot resolve parameter '{$paramName}' at position {$index}",
            $paramName,
            null,
            $this->generateParameterSuggestions($parameter)
        );
    }

    /**
     * Check if a parameter can be resolved.
     */
    public function canResolve(ReflectionParameter $parameter, ContainerInterface $container): bool
    {
        // Can always resolve if there's a default value or it's nullable
        if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
            return true;
        }

        // Check if we can resolve from container
        $type = $parameter->getType();
        if ($type === null) {
            return false;
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->canResolveNamedType($type, $container);
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->canResolveUnionType($type, $container);
        }

        return false;
    }

    /**
     * Get detailed information about parameter resolution.
      * @return array<string, mixed>
     */
    public function getParameterInfo(ReflectionParameter $parameter, ContainerInterface $container): array
    {
        $type = $parameter->getType();
        $info = [
            'name' => $parameter->getName(),
            'position' => $parameter->getPosition(),
            'has_type' => $type !== null,
            'type_name' => $type instanceof ReflectionNamedType ? $type->getName() : null,
            'is_builtin' => $type instanceof ReflectionNamedType && $type->isBuiltin(),
            'allows_null' => $parameter->allowsNull(),
            'is_optional' => $parameter->isOptional(),
            'has_default' => $parameter->isDefaultValueAvailable(),
            'default_value' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
            'can_resolve' => $this->canResolve($parameter, $container),
            'resolution_strategy' => $this->getResolutionStrategy($parameter, $container)
        ];

        if ($type instanceof ReflectionUnionType) {
            $info['union_types'] = [];
            foreach ($type->getTypes() as $unionType) {
                $info['union_types'][] = [
                    'name' => $unionType->getName(),
                    'is_builtin' => $unionType->isBuiltin(),
                    'can_resolve' => !$unionType->isBuiltin() && $container->has($unionType->getName())
                ];
            }
        }

        return $info;
    }

    /**
     * Resolve parameter from container using type information.
     */
    private function resolveFromContainer(ReflectionParameter $parameter, ContainerInterface $container): mixed
    {
        $type = $parameter->getType();
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->resolveNamedType($type, $container);
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->resolveUnionType($type, $container);
        }

        return null;
    }

    /**
     * Resolve a named type from the container.
     */
    private function resolveNamedType(ReflectionNamedType $type, ContainerInterface $container): mixed
    {
        // Skip built-in types
        if ($type->isBuiltin()) {
            return null;
        }

        $typeName = $type->getName();

        try {
            return $container->get($typeName);
        } catch (\Throwable $e) {
            // If we can't resolve it, return null to try other strategies
            return null;
        }
    }

    /**
     * Resolve a union type by trying each type in order.
     */
    private function resolveUnionType(ReflectionUnionType $type, ContainerInterface $container): mixed
    {
        foreach ($type->getTypes() as $unionType) {
            if ($unionType->isBuiltin()) {
                continue;
            }

            try {
                return $container->get($unionType->getName());
            } catch (\Throwable $e) {
                // Try the next type in the union
                continue;
            }
        }

        return null;
    }

    /**
     * Check if a named type can be resolved.
     */
    private function canResolveNamedType(ReflectionNamedType $type, ContainerInterface $container): bool
    {
        if ($type->isBuiltin()) {
            return false;
        }

        return $container->has($type->getName());
    }

    /**
     * Check if any type in a union can be resolved.
     */
    private function canResolveUnionType(ReflectionUnionType $type, ContainerInterface $container): bool
    {
        foreach ($type->getTypes() as $unionType) {
            if (!$unionType->isBuiltin() && $container->has($unionType->getName())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate and optionally cast a provided parameter value.
     */
    private function validateAndCastParameter(ReflectionParameter $parameter, mixed $value): mixed
    {
        $type = $parameter->getType();
        
        // If no type constraint, return as-is
        if ($type === null) {
            return $value;
        }

        // Handle null values
        if ($value === null) {
            if ($parameter->allowsNull()) {
                return null;
            }
            
            throw new ContainerResolutionException(
                "Cannot pass null to non-nullable parameter '{$parameter->getName()}'",
                $parameter->getName(),
                null,
                [
                    'Provide a non-null value for the parameter',
                    'Make the parameter nullable by adding ? before the type',
                    'Add a default value to the parameter'
                ]
            );
        }

        if ($type instanceof ReflectionNamedType) {
            return $this->validateNamedType($type, $value, $parameter);
        }

        if ($type instanceof ReflectionUnionType) {
            return $this->validateUnionType($type, $value, $parameter);
        }

        return $value;
    }

    /**
     * Validate a value against a named type.
     */
    private function validateNamedType(ReflectionNamedType $type, mixed $value, ReflectionParameter $parameter): mixed
    {
        $typeName = $type->getName();
        
        if ($type->isBuiltin()) {
            return $this->castBuiltinType($typeName, $value, $parameter);
        }

        // For class/interface types, check instanceof
        if (!($value instanceof $typeName)) {
            throw new ContainerResolutionException(
                "Parameter '{$parameter->getName()}' expects {$typeName}, got " . get_debug_type($value),
                $parameter->getName(),
                null,
                [
                    "Provide an instance of {$typeName}",
                    'Check that the value implements the required interface',
                    'Verify that the value extends the required class'
                ]
            );
        }

        return $value;
    }

    /**
     * Validate a value against a union type.
     */
    private function validateUnionType(ReflectionUnionType $type, mixed $value, ReflectionParameter $parameter): mixed
    {
        foreach ($type->getTypes() as $unionType) {
            try {
                return $this->validateNamedType($unionType, $value, $parameter);
            } catch (ContainerResolutionException $e) {
                // Try the next type in the union
                continue;
            }
        }

        $typeNames = array_map(fn($t) => $t->getName(), $type->getTypes());
        
        throw new ContainerResolutionException(
            "Parameter '{$parameter->getName()}' expects " . implode('|', $typeNames) . ", got " . get_debug_type($value),
            $parameter->getName(),
            null,
            [
                'Provide a value that matches one of the union types: ' . implode(', ', $typeNames),
                'Check the type of the provided value',
                'Ensure the value is compatible with at least one union type'
            ]
        );
    }

    /**
     * Cast a value to a built-in type.
     */
    private function castBuiltinType(string $typeName, mixed $value, ReflectionParameter $parameter): mixed
    {
        return match ($typeName) {
            'string' => (string) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => (bool) $value,
            'array' => (array) $value,
            'object' => (object) $value,
            default => $value
        };
    }

    /**
     * Get the resolution strategy for a parameter.
     */
    private function getResolutionStrategy(ReflectionParameter $parameter, ContainerInterface $container): string
    {
        if ($parameter->isDefaultValueAvailable()) {
            return 'default_value';
        }

        if ($parameter->allowsNull()) {
            return 'nullable';
        }

        $type = $parameter->getType();
        if ($type === null) {
            return 'untyped';
        }

        if (!$type instanceof ReflectionNamedType) {
            return 'unresolvable';
        }

        if ($type->isBuiltin()) {
            return 'builtin_type';
        }

        if ($container->has($type->getName())) {
            return 'container_resolution';
        }

        return 'unresolvable';
    }

    /**
     * Generate helpful suggestions for parameter resolution failures.
      * @return array<string>
     */
    private function generateParameterSuggestions(ReflectionParameter $parameter): array
    {
        $suggestions = [];
        $type = $parameter->getType();

        if ($type === null) {
            $suggestions[] = "Add a type hint to enable automatic injection";
            $suggestions[] = "Provide the parameter value explicitly";
            $suggestions[] = "Add a default value to make the parameter optional";
        } elseif (!$type instanceof ReflectionNamedType) {
            $suggestions[] = "Complex type declarations may not be auto-injectable";
            $suggestions[] = "Provide the parameter value explicitly";
            $suggestions[] = "Consider using a simpler type hint";
        } elseif ($type->isBuiltin()) {
            $suggestions[] = "Built-in types cannot be auto-injected - provide the value explicitly";
            $suggestions[] = "Add a default value for the parameter";
            $suggestions[] = "Make the parameter nullable if appropriate";
        } else {
            $typeName = $type->getName();
            $suggestions[] = "Register a binding for '{$typeName}' in the container";
            $suggestions[] = "Provide the parameter value explicitly";
            $suggestions[] = "Check that the class '{$typeName}' exists and can be instantiated";
        }

        return $suggestions;
    }
}