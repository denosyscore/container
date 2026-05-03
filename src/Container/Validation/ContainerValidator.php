<?php

declare(strict_types=1);

namespace Denosys\Container\Validation;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use Denosys\Container\Container;
use Denosys\Container\ValidationResult;
use Denosys\Container\ValidationIssue;

/**
 * Container validator for pre-runtime validation.
 *
 * Checks:
 *   1. Every bound abstract resolves to a real PHP class or interface — flagged as error if not.
 *   2. Every interface abstract that has no explicit concrete (self-bound) is flagged as a warning.
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

            // Access the private $bindings property to inspect raw concrete closures.
            // getBindings() exposes metadata only (shared, tags, etc.), not the concrete class name,
            // so reflection is the only way to introspect what was actually registered.
            $reflection = new ReflectionClass($container);
            $bindingsProp = $reflection->getProperty('bindings');
            $bindingsProp->setAccessible(true);
            /** @var array<string, array{concrete: Closure, shared: bool}> $rawBindings */
            $rawBindings = $bindingsProp->getValue($container);

            foreach ($bindings as $abstract => $info) {
                // Check 1: Abstract must exist as a real PHP class or interface.
                if (!class_exists($abstract) && !interface_exists($abstract)) {
                    $issues[] = new ValidationIssue(
                        ValidationIssue::SEVERITY_ERROR,
                        "Abstract '{$abstract}' references a non-existent class or interface.",
                        $abstract,
                        [
                            "Ensure '{$abstract}' is defined and autoloaded",
                            "Check for typos in the class or interface name",
                        ]
                    );
                    continue;
                }

                // Check 2: Interface bound to itself — no concrete implementation registered.
                //
                // When bind(SomeInterface::class) is called without an explicit concrete,
                // bind() sets concrete = abstract and wraps it as:
                //   fn() => $this->resolve($concrete)   where $concrete === $abstract
                // That closure will fail at runtime because interfaces are not instantiable.
                if (interface_exists($abstract) && isset($rawBindings[$abstract])) {
                    $concrete = $rawBindings[$abstract]['concrete'];

                    if ($concrete instanceof Closure) {
                        $rf = new ReflectionFunction($concrete);
                        $used = $rf->getClosureUsedVariables();

                        if (isset($used['concrete']) && $used['concrete'] === $abstract) {
                            $issues[] = new ValidationIssue(
                                ValidationIssue::SEVERITY_WARNING,
                                "Interface '{$abstract}' is bound to itself with no concrete implementation.",
                                $abstract,
                                ["Bind '{$abstract}' to a concrete class that implements it"]
                            );
                        }
                    }
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

            return new ValidationResult(false, $issues, $statistics);
        }
    }
}
