<?php

declare(strict_types=1);

namespace Denosys\Container\Validation;

use ReflectionClass;
use ReflectionNamedType;
use Denosys\Container\Container;
use Denosys\Container\ValidationResult;
use Denosys\Container\ValidationIssue;

/**
 * Container validator for pre-runtime validation.
 *
 * Checks each registered binding for:
 * - Concrete class names that do not exist (error)
 * - Interfaces bound only to themselves with no real implementation (warning)
 * - Circular dependencies detected via DFS (error)
 * - Constructor parameters typed as interfaces/abstract classes not bound in the container (warning)
 */
class ContainerValidator
{
    public function validate(Container $container): ValidationResult
    {
        $issues = [];
        $statistics = [];

        try {
            $bindings = $container->getBindings();
            $statistics['total_bindings'] = count($bindings);

            // Build a set of all bound abstracts for quick lookup.
            $boundAbstracts = array_keys($bindings);

            foreach ($bindings as $abstract => $binding) {
                $concreteClass = $binding['concrete_class'] ?? null;

                // Check 1: if the concrete is a string class name, verify it actually exists.
                if (
                    $concreteClass !== null
                    && !class_exists($concreteClass)
                    && !interface_exists($concreteClass)
                ) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_ERROR,
                        "Concrete class '{$concreteClass}' bound to '{$abstract}' does not exist.",
                        $abstract,
                        [
                            "Ensure '{$concreteClass}' is properly defined and autoloaded.",
                            "Check for typos in the class name or namespace.",
                        ]
                    );
                }

                // Check 2: if the abstract is an interface but has no real concrete registered
                // (i.e. it was bound to itself or without any concrete), flag as a warning.
                if (interface_exists($abstract) && ($concreteClass === null || $concreteClass === $abstract)) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_WARNING,
                        "Interface '{$abstract}' has no concrete implementation registered.",
                        $abstract,
                        ["Bind '{$abstract}' to a concrete class using bind() or singleton()."]
                    );
                }
            }

            // Check 3 & 4: For each binding with a concrete class, use reflection to walk
            // the constructor dependency graph.
            foreach ($bindings as $abstract => $binding) {
                $concreteClass = $binding['concrete_class'] ?? null;

                if ($concreteClass === null || !class_exists($concreteClass)) {
                    continue;
                }

                // DFS circular dependency detection.
                $circularIssues = $this->detectCircularDependencies(
                    $concreteClass,
                    $abstract,
                    $boundAbstracts,
                    [],
                    [$concreteClass]
                );
                $issues = array_merge($issues, $circularIssues);

                // Missing binding detection.
                $missingIssues = $this->detectMissingBindings(
                    $concreteClass,
                    $abstract,
                    $boundAbstracts
                );
                $issues = array_merge($issues, $missingIssues);
            }

            // Valid unless there are error-level issues (warnings are allowed).
            $isValid = empty(array_filter($issues, fn(ValidationIssue $i) => $i->isError()));

            return new ValidationResult($isValid, $issues, $statistics);

        } catch (\Throwable $e) {
            $issues[] = new ValidationIssue(
                ValidationIssue::SEVERITY_ERROR,
                'Failed to validate container: ' . $e->getMessage(),
                null,
                ['Check container configuration and bindings.']
            );

            return new ValidationResult(false, $issues, $statistics);
        }
    }

    /**
     * DFS walk of the constructor dependency graph to detect circular dependencies.
     *
     * @param string   $concreteClass  The class being inspected now.
     * @param string   $abstract       The original abstract this walk started from.
     * @param string[] $boundAbstracts All abstracts registered in the container.
     * @param string[] $visitedPath    Classes on the current DFS path (for cycle detection).
     * @param string[] $seen           Classes already visited in this entire DFS tree (prevent duplicate issues).
     * @return ValidationIssue[]
     */
    private function detectCircularDependencies(
        string $concreteClass,
        string $abstract,
        array $boundAbstracts,
        array $visitedPath,
        array $seen
    ): array {
        $issues = [];

        if (!class_exists($concreteClass)) {
            return $issues;
        }

        try {
            $reflection = new ReflectionClass($concreteClass);
        } catch (\ReflectionException) {
            return $issues;
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $issues;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $depType = $type->getName();

            // Only walk into types that are actually bound in the container.
            if (!in_array($depType, $boundAbstracts, true)) {
                continue;
            }

            // Circular dependency: this type is already on our current DFS path.
            if (in_array($depType, $visitedPath, true) || $depType === $concreteClass) {
                $cycle = implode(' -> ', array_merge($visitedPath, [$concreteClass, $depType]));
                $issues[] = new ValidationIssue(
                    ValidationIssue::SEVERITY_ERROR,
                    "Circular dependency detected for '{$abstract}': {$cycle}",
                    $abstract,
                    [
                        "Break the cycle by introducing an interface or using lazy loading.",
                        "Consider restructuring to remove the mutual dependency.",
                    ]
                );
                continue;
            }

            // Avoid re-visiting nodes we already walked in this subtree.
            if (in_array($depType, $seen, true)) {
                continue;
            }

            // If the dep type is itself a concrete class bound in the container, recurse into it.
            if (class_exists($depType)) {
                $newPath = array_merge($visitedPath, [$concreteClass]);
                $newSeen = array_merge($seen, [$depType]);
                $childIssues = $this->detectCircularDependencies(
                    $depType,
                    $abstract,
                    $boundAbstracts,
                    $newPath,
                    $newSeen
                );
                $issues = array_merge($issues, $childIssues);
            }
        }

        return $issues;
    }

    /**
     * Inspect a class's constructor and flag parameters typed as interfaces or abstract
     * classes that are not bound in the container and cannot be autowired.
     *
     * @param string   $concreteClass  The class to inspect.
     * @param string   $abstract       The abstract this class is bound to.
     * @param string[] $boundAbstracts All abstracts registered in the container.
     * @return ValidationIssue[]
     */
    private function detectMissingBindings(
        string $concreteClass,
        string $abstract,
        array $boundAbstracts
    ): array {
        $issues = [];

        if (!class_exists($concreteClass)) {
            return $issues;
        }

        try {
            $reflection = new ReflectionClass($concreteClass);
        } catch (\ReflectionException) {
            return $issues;
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $issues;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            // Skip optional / defaulted parameters - they won't cause resolution failures.
            if ($parameter->isOptional() || $parameter->allowsNull() || $parameter->isDefaultValueAvailable()) {
                continue;
            }

            $depType = $type->getName();

            // Already bound - fine.
            if (in_array($depType, $boundAbstracts, true)) {
                continue;
            }

            // Concrete instantiable class - autowirable without an explicit binding.
            if (class_exists($depType)) {
                try {
                    $depReflection = new ReflectionClass($depType);
                    if ($depReflection->isInstantiable()) {
                        continue;
                    }
                } catch (\ReflectionException) {
                    // Fall through to warn.
                }
            }

            // Interface or abstract class with no binding - warn.
            $kind = interface_exists($depType) ? 'interface' : 'abstract class';
            $issues[] = new ValidationIssue(
                ValidationIssue::SEVERITY_WARNING,
                "'{$abstract}' depends on {$kind} '{$depType}' which is not bound in the container.",
                $abstract,
                [
                    "Bind '{$depType}' to a concrete implementation using bind() or singleton().",
                    "Or make the parameter nullable/provide a default value if it is optional.",
                ]
            );
        }

        return $issues;
    }
}
