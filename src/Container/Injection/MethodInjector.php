<?php

declare(strict_types=1);

namespace Denosys\Container\Injection;

use Denosys\Container\Performance\ReflectionCache;
use Denosys\Container\ContainerInterface;
use Denosys\Container\Exceptions\ContainerResolutionException;

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
     *
     * @param array<int|string, mixed> $parameters
     */
    public function call(callable $callback, array $parameters, ContainerInterface $container): mixed
    {
        if (is_array($callback) && count($callback) === 2) {
            [$object, $method] = $callback;

            if (is_string($object)) {
                return $this->callStatic($object, $method, $parameters, $container);
            }

            return $this->callInstanceMethod($object, $method, $parameters, $container);
        }

        if (is_string($callback) && str_contains($callback, '::')) {
            [$class, $method] = explode('::', $callback, 2);
            return $this->callStatic($class, $method, $parameters, $container);
        }

        if (is_string($callback) && function_exists($callback)) {
            return $this->callFunction($callback, $parameters, $container);
        }

        if ($callback instanceof \Closure) {
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
     *
     * @param array<int|string, mixed> $parameters
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
     * @param array<int|string, mixed> $parameters
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
     * @param array<int|string, mixed> $parameters
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
     * @param array<int|string, mixed> $parameters
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
                'Failed to call closure: ' . $e->getMessage(),
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
     * Resolve method parameters, checking explicit overrides by name or type before autowiring.
     *
     * @param array<int, array<string, mixed>> $methodParams
     * @param array<int|string, mixed> $providedParams
     * @return array<int, mixed>
     */
    private function resolveMethodParameters(array $methodParams, array $providedParams, ContainerInterface $container): array
    {
        $resolvedParams = [];

        foreach ($methodParams as $param) {
            $paramName = $param['name'];

            // 1. Explicit override by parameter name
            if (array_key_exists($paramName, $providedParams)) {
                $resolvedParams[] = $providedParams[$paramName];
                continue;
            }

            // 2. Explicit override by type/class name
            if ($param['hasType'] && !$param['isBuiltin']) {
                $typeName = $param['type']->getName();

                if (array_key_exists($typeName, $providedParams)) {
                    $resolvedParams[] = $providedParams[$typeName];
                    continue;
                }

                // 3. Autowire from container
                try {
                    $resolvedParams[] = $container->get($typeName);
                    continue;
                } catch (\Throwable) {
                    // Fall through to default/null handling
                }
            }

            // 4. Default value
            if ($param['hasDefaultValue']) {
                $resolvedParams[] = $param['defaultValue'];
                continue;
            }

            // 5. Nullable — resolve to null
            if ($param['allowsNull']) {
                $resolvedParams[] = null;
                continue;
            }

            throw new ContainerResolutionException(
                "Cannot resolve parameter '{$paramName}' — no type hint, no provided value, and no default value.",
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
     * @param array<int, \ReflectionParameter> $reflectionParams
     * @return array<int, array<string, mixed>>
     */
    private function extractParametersFromReflection(array $reflectionParams): array
    {
        $parameters = [];

        foreach ($reflectionParams as $param) {
            $parameters[] = [
                'name'            => $param->getName(),
                'type'            => $param->getType(),
                'hasType'         => $param->hasType(),
                'isBuiltin'       => $param->getType() && $param->getType()->isBuiltin(),
                'allowsNull'      => $param->allowsNull(),
                'isOptional'      => $param->isOptional(),
                'hasDefaultValue' => $param->isDefaultValueAvailable(),
                'defaultValue'    => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
            ];
        }

        return $parameters;
    }
}
