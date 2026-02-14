<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Injection;

use CFXP\Core\Container\Performance\ReflectionCache;
use CFXP\Core\Container\ContainerInterface;
use CFXP\Core\Exceptions\ContainerResolutionException;

/**
 * Method injector for automatic dependency injection into callable methods.
 */
class MethodInjector
{
    public function __construct(
        private ReflectionCache $reflectionCache
    ) {}

    /**
     * Call a method with automatic dependency injection.
      * @param array<int, mixed> $parameters
     */
    public function call(callable $callback, array $parameters, ContainerInterface $container): mixed
    {
        if (is_array($callback) && count($callback) === 2) {
            [$object, $method] = $callback;
            
            if (is_string($object)) {
                // Static method call
                return $this->callStatic($object, $method, $parameters, $container);
            }
            
            // Instance method call
            return $this->callInstanceMethod($object, $method, $parameters, $container);
        }
        
        if (is_string($callback) && str_contains($callback, '::')) {
            // Static method call in string format
            [$class, $method] = explode('::', $callback, 2);
            return $this->callStatic($class, $method, $parameters, $container);
        }
        
        if (is_string($callback) && function_exists($callback)) {
            // Function call
            return $this->callFunction($callback, $parameters, $container);
        }
        
        if ($callback instanceof \Closure) {
            // Closure call
            return $this->callClosure($callback, $parameters, $container);
        }
        
        throw new ContainerResolutionException(
            'Invalid callback provided to method injector',
            null,
            null,
            ['Ensure the callback is a valid callable (method, function, or closure)']
        );
    }

    /**
     * Call a static method with dependency injection.
      * @param array<int, mixed> $parameters
     */
    public function callStatic(string $class, string $method, array $parameters, ContainerInterface $container): mixed
    {
        try {
            $methodParams = $this->reflectionCache->getMethodParameters($class, $method);
            $resolvedParams = $this->resolveMethodParameters($methodParams, $parameters, $container);
            
            return $class::$method(...$resolvedParams);
        } catch (\Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to call static method {$class}::{$method}: " . $e->getMessage(),
                "{$class}::{$method}",
                null,
                [
                    'Verify the method exists and is static',
                    'Check that all dependencies can be resolved',
                    'Ensure provided parameters match expected types'
                ],
                $e
            );
        }
    }

    /**
     * Call an instance method with dependency injection.
      * @param array<int, mixed> $parameters
     */
    private function callInstanceMethod(object $instance, string $method, array $parameters, ContainerInterface $container): mixed
    {
        $className = get_class($instance);
        
        try {
            $methodParams = $this->reflectionCache->getMethodParameters($className, $method);
            $resolvedParams = $this->resolveMethodParameters($methodParams, $parameters, $container);
            
            return $instance->$method(...$resolvedParams);
        } catch (\Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to call instance method {$className}::{$method}: " . $e->getMessage(),
                "{$className}::{$method}",
                null,
                [
                    'Verify the method exists on the instance',
                    'Check that all dependencies can be resolved',
                    'Ensure provided parameters match expected types'
                ],
                $e
            );
        }
    }

    /**
     * Call a function with dependency injection.
      * @param array<int, mixed> $parameters
     */
    private function callFunction(string $function, array $parameters, ContainerInterface $container): mixed
    {
        try {
            $reflection = $this->reflectionCache->getFunction($function);
            $methodParams = $this->extractParametersFromReflection($reflection->getParameters());
            $resolvedParams = $this->resolveMethodParameters($methodParams, $parameters, $container);
            
            return $function(...$resolvedParams);
        } catch (\Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to call function {$function}: " . $e->getMessage(),
                $function,
                null,
                [
                    'Verify the function exists',
                    'Check that all dependencies can be resolved'
                ],
                $e
            );
        }
    }

    /**
     * Call a closure with dependency injection.
      * @param array<int, mixed> $parameters
     */
    private function callClosure(\Closure $closure, array $parameters, ContainerInterface $container): mixed
    {
        try {
            $reflection = new \ReflectionFunction($closure);
            $methodParams = $this->extractParametersFromReflection($reflection->getParameters());
            $resolvedParams = $this->resolveMethodParameters($methodParams, $parameters, $container);
            
            return $closure(...$resolvedParams);
        } catch (\Throwable $e) {
            throw new ContainerResolutionException(
                "Failed to call closure: " . $e->getMessage(),
                'Closure',
                null,
                [
                    'Check that all dependencies can be resolved',
                    'Verify closure parameter types'
                ],
                $e
            );
        }
    }

    /**
     * Resolve method parameters by injecting dependencies and using provided parameters.
     */
    /**
     * @return array<string, mixed>
      * @param array<string, mixed> $methodParams
      * @param array<string, mixed> $providedParams
     */
private function resolveMethodParameters(array $methodParams, array $providedParams, ContainerInterface $container): array
    {
        $resolvedParams = [];
        
        foreach ($methodParams as $param) {
            $paramName = $param['name'];
            
            // Check if parameter was explicitly provided
            if (array_key_exists($paramName, $providedParams)) {
                $resolvedParams[] = $providedParams[$paramName];
                continue;
            }
            
            // Try to resolve from container if it has a type
            if ($param['hasType'] && !$param['isBuiltin']) {
                $typeName = $param['type']->getName();
                
                try {
                    $resolvedParams[] = $container->get($typeName);
                    continue;
                } catch (\Throwable $e) {
                    // Fall through to default value handling
                }
            }
            
            // Use default value if available
            if ($param['hasDefaultValue']) {
                $resolvedParams[] = $param['defaultValue'];
                continue;
            }
            
            // Parameter cannot be resolved
            throw new ContainerResolutionException(
                "Cannot resolve parameter '{$paramName}' - no type hint, no provided value, and no default value",
                $paramName,
                null,
                [
                    'Provide a value for the parameter',
                    'Add a type hint for automatic injection',
                    'Add a default value to the parameter'
                ]
            );
        }
        
        return $resolvedParams;
    }

    /**
     * Extract parameter information from reflection parameters.
      * @param array<string, mixed> $reflectionParams
      * @return array<string, mixed>
     */
    private function extractParametersFromReflection(array $reflectionParams): array
    {
        $parameters = [];
        
        foreach ($reflectionParams as $param) {
            $parameters[] = [
                'name' => $param->getName(),
                'type' => $param->getType(),
                'hasType' => $param->hasType(),
                'isBuiltin' => $param->getType() && $param->getType()->isBuiltin(),
                'allowsNull' => $param->allowsNull(),
                'isOptional' => $param->isOptional(),
                'hasDefaultValue' => $param->isDefaultValueAvailable(),
                'defaultValue' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null
            ];
        }
        
        return $parameters;
    }
}