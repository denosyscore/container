<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Validation;

use CFXP\Core\Container\Container;
use CFXP\Core\Container\ValidationResult;
use CFXP\Core\Container\ValidationIssue;

/**
 * Container validator for pre-runtime validation.
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
            
            // For now, just return a basic validation result
            // In a full implementation, this would check for circular dependencies,
            // missing bindings, etc.
            
            $isValid = empty($issues);
            
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