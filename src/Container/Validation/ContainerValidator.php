<?php

declare(strict_types=1);

namespace Denosys\Container\Validation;

use Denosys\Container\Container;
use Denosys\Container\ValidationResult;
use Denosys\Container\ValidationIssue;

/**
 * Container validator for pre-runtime validation.
 *
 * Checks:
 *   - Bindings where the concrete is a string class name that does not exist
 *     are flagged as errors.
 *   - Abstracts that are interfaces bound to themselves (no real concrete) are
 *     flagged as warnings.
 */
class ContainerValidator
{
    public function validate(Container $container): ValidationResult
    {
        $issues = [];

        try {
            $bindings = $container->getBindings();
            $statistics = ['total_bindings' => count($bindings)];

            foreach ($bindings as $abstract => $info) {
                $concreteClass = $info['concrete_class'] ?? null;

                // Check 1: concrete is a named class/interface that doesn't exist.
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
                            "Ensure '{$concreteClass}' is defined and autoloaded",
                            "Check for typos in the class name",
                        ]
                    );
                    continue;
                }

                // Check 2: abstract is an interface bound to itself — no real implementation.
                if (
                    $concreteClass !== null
                    && $concreteClass === $abstract
                    && interface_exists($abstract)
                ) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_WARNING,
                        "Interface '{$abstract}' has no concrete implementation registered.",
                        $abstract,
                        [
                            "Bind '{$abstract}' to a concrete class: \$container->bind({$abstract}::class, ConcreteImpl::class)",
                        ]
                    );
                }
            }

            $statistics['error_count']   = count(array_filter($issues, fn($i) => $i->isError()));
            $statistics['warning_count'] = count(array_filter($issues, fn($i) => $i->isWarning()));

            $isValid = $statistics['error_count'] === 0;

            return new ValidationResult($isValid, $issues, $statistics);

        } catch (\Throwable $e) {
            $issues[] = new ValidationIssue(
                ValidationIssue::SEVERITY_ERROR,
                'Failed to validate container: ' . $e->getMessage(),
                null,
                ['Check container configuration and bindings']
            );

            return new ValidationResult(false, $issues, $statistics ?? []);
        }
    }
}
