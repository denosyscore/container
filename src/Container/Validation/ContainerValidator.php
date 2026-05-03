<?php

declare(strict_types=1);

namespace Denosys\Container\Validation;

use ReflectionClass;
use ReflectionException;
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
 * - Concrete classes whose interface-typed constructor parameters are not
 *   registered in the container (warning)
 */
class ContainerValidator
{
    public function validate(Container $container): ValidationResult
    {
        $issues = [];
        $statistics = [];

        try {
            $bindings = $container->getBindings();
            $statistics["total_bindings"] = count($bindings);

            foreach ($bindings as $abstract => $binding) {
                $concreteClass = $binding["concrete_class"] ?? null;

                // Check 1: if the concrete is a string class name, verify it actually exists.
                if (
                    $concreteClass !== null
                    && !class_exists($concreteClass)
                    && !interface_exists($concreteClass)
                ) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_ERROR,
                        "Concrete class " . $concreteClass . " bound to " . $abstract . " does not exist.",
                        $abstract,
                        [
                            "Ensure " . $concreteClass . " is properly defined and autoloaded.",
                            "Check for typos in the class name or namespace.",
                        ]
                    );
                    continue;
                }

                // Check 2: if the abstract is an interface but has no real concrete registered
                // (i.e. it was bound to itself or without any concrete), flag as a warning.
                if (interface_exists($abstract) && $concreteClass !== null && $concreteClass === $abstract) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_WARNING,
                        "Interface " . $abstract . " has no concrete implementation registered.",
                        $abstract,
                        ["Bind " . $abstract . " to a concrete class using bind() or singleton()."]
                    );
                    continue;
                }

                // Check 3: for concrete classes that exist, warn about interface-typed
                // constructor parameters that have no binding in the container.
                if ($concreteClass !== null && class_exists($concreteClass)) {
                    foreach ($this->unboundInterfaceDeps($concreteClass, $container) as $issue) {
                        $issues[] = $issue;
                    }
                }
            }

            $statistics["error_count"]   = count(array_filter($issues, fn($i) => $i->isError()));
            $statistics["warning_count"] = count(array_filter($issues, fn($i) => $i->isWarning()));

            // Valid unless there are error-level issues (warnings are allowed).
            $isValid = $statistics["error_count"] === 0;

            return new ValidationResult($isValid, $issues, $statistics);

        } catch (\Throwable $e) {
            $issues[] = new ValidationIssue(
                ValidationIssue::SEVERITY_ERROR,
                "Failed to validate container: " . $e->getMessage(),
                null,
                ["Check container configuration and bindings."]
            );

            return new ValidationResult(false, $issues, $statistics);
        }
    }

    /**
     * Return a warning for every interface-typed constructor parameter of $class
     * that has no binding registered in $container.
     *
     * @return list<ValidationIssue>
     */
    private function unboundInterfaceDeps(string $class, Container $container): array
    {
        $issues = [];

        try {
            $ctor = (new ReflectionClass($class))->getConstructor();

            if ($ctor === null) {
                return [];
            }

            foreach ($ctor->getParameters() as $param) {
                $type = $param->getType();

                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $dep = $type->getName();

                if (interface_exists($dep) && !$container->has($dep)) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_WARNING,
                        "Class " . $class . " depends on interface " . $dep . " which is not registered in the container.",
                        $dep,
                        [
                            "Bind " . $dep . " to a concrete implementation.",
                        ]
                    );
                }
            }
        } catch (ReflectionException) {
            // If reflection fails we skip -- cannot warn without type info.
        }

        return $issues;
    }
}
