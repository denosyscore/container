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
 * Checks performed on every binding returned by Container::getBindings():
 *
 *   1. If the abstract key looks like a PHP class/interface name (FQCN or PascalCase)
 *      and neither class_exists() nor interface_exists() resolves it -> ERROR.
 *
 *   2. If the abstract key is a concrete class that exists, inspect its constructor
 *      via reflection.  For every interface-typed parameter that has no binding
 *      registered in the container -> WARNING.
 */
class ContainerValidator
{
    public function validate(Container $container): ValidationResult
    {
        $issues = [];

        try {
            $bindings = $container->getBindings();
            $statistics = ['total_bindings' => count($bindings)];

            foreach ($bindings as $abstract => $binding) {
                if (!$this->looksLikeClassName($abstract)) {
                    continue;
                }

                // Check 1 -- the named class or interface must exist.
                if (!class_exists($abstract) && !interface_exists($abstract)) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_ERROR,
                        "Binding '{$abstract}' references a non-existent class or interface.",
                        $abstract,
                        [
                            "Ensure '{$abstract}' is autoloaded",
                            "Check for typos in the class or interface name",
                        ]
                    );
                    continue;
                }

                // Check 2 -- for concrete classes, warn about interface-typed constructor
                // dependencies that are not registered in the container.
                if (class_exists($abstract)) {
                    foreach ($this->unboundInterfaceDeps($abstract, $container) as $issue) {
                        $issues[] = $issue;
                    }
                }
            }

            $isValid = empty(
                array_filter($issues, static fn(ValidationIssue $i) => $i->isError())
            );

            return new ValidationResult($isValid, $issues, $statistics);

        } catch (\Throwable $e) {
            $issues[] = new ValidationIssue(
                ValidationIssue::SEVERITY_ERROR,
                'Failed to validate container: ' . $e->getMessage(),
                null,
                ['Check container configuration and bindings']
            );

            return new ValidationResult(false, $issues, []);
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
                        "'{$class}' depends on interface '{$dep}' which is not registered in the container.",
                        $dep,
                        [
                            "Bind '{$dep}' to a concrete implementation:",
                            "\$container->bind({$dep}::class, ConcreteClass::class)",
                        ]
                    );
                }
            }
        } catch (ReflectionException) {
            // If reflection fails we skip -- can't warn without type info.
        }

        return $issues;
    }

    /**
     * True when $abstract looks like a PHP FQCN or a simple PascalCase identifier.
     * Returns false for arbitrary service-locator keys like 'db.connection'.
     */
    private function looksLikeClassName(string $abstract): bool
    {
        return str_contains($abstract, '\\')
            || (
                $abstract !== ''
                && ctype_upper($abstract[0])
                && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $abstract) === 1
            );
    }
}
