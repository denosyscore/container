<?php

declare(strict_types=1);

namespace Denosys\Container\Performance;

use ReflectionClass;
use ReflectionMethod;
use ReflectionFunction;
use ReflectionException;

/**
 * Cache for reflection data to improve container performance.
 */
class ReflectionCache
{
    /**
     * @var array<string, ReflectionClass> Cached class reflections
     */
    /** @var array<string, mixed> */

    private array $classCache = [];

    /**
     * @var array<string, ReflectionMethod> Cached method reflections
     */
    /** @var array<string, mixed> */

    private array $methodCache = [];

    /**
     * @var array<string, ReflectionFunction> Cached function reflections
     */
    /** @var array<string, mixed> */

    private array $functionCache = [];

    /**
     * @var array<string, array> Cached constructor parameters
     */
    /** @var array<string, mixed> */

    private array $constructorCache = [];

    /**
     * @var array<string, array> Cached method parameters
     */
    /** @var array<string, mixed> */

    private array $methodParameterCache = [];

    /**
     * Cache statistics
     */
    private int $hits = 0;
    private int $misses = 0;

    /**
     * Get a cached ReflectionClass or create and cache it.
     *
     * @throws ReflectionException
     */
    public function getClass(string $className): ReflectionClass
    {
        if (isset($this->classCache[$className])) {
            $this->hits++;
            return $this->classCache[$className];
        }

        $this->misses++;
        $reflection = new ReflectionClass($className);
        $this->classCache[$className] = $reflection;

        return $reflection;
    }

    /**
     * Get a cached ReflectionMethod or create and cache it.
     */
    public function getMethod(string $className, string $methodName): ReflectionMethod
    {
        $key = "{$className}::{$methodName}";

        if (isset($this->methodCache[$key])) {
            $this->hits++;
            return $this->methodCache[$key];
        }

        $this->misses++;
        $reflection = new ReflectionMethod($className, $methodName);
        $this->methodCache[$key] = $reflection;

        return $reflection;
    }

    /**
     * Get a cached ReflectionFunction or create and cache it.
     */
    public function getFunction(string $functionName): ReflectionFunction
    {
        if (isset($this->functionCache[$functionName])) {
            $this->hits++;
            return $this->functionCache[$functionName];
        }

        $this->misses++;
        $reflection = new ReflectionFunction($functionName);
        $this->functionCache[$functionName] = $reflection;

        return $reflection;
    }

    /**
     * Get cached constructor parameters for a class.
     */
    /**
     * @return array<string, mixed>
     */
public function getConstructorParameters(string $className): array
    {
        if (isset($this->constructorCache[$className])) {
            $this->hits++;
            return $this->constructorCache[$className];
        }

        $this->misses++;
        $class = $this->getClass($className);
        $constructor = $class->getConstructor();

        $parameters = [];
        if ($constructor) {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                $parameters[] = [
                    'name' => $parameter->getName(),
                    'type' => $type,
                    'hasType' => $parameter->hasType(),
                    'isBuiltin' => $type instanceof \ReflectionNamedType && $type->isBuiltin(),
                    'allowsNull' => $parameter->allowsNull(),
                    'isOptional' => $parameter->isOptional(),
                    'hasDefaultValue' => $parameter->isDefaultValueAvailable(),
                    'defaultValue' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null
                ];
            }
        }

        $this->constructorCache[$className] = $parameters;
        return $parameters;
    }

    /**
     * Get cached method parameters.
      * @return array<string, mixed>
     */
    public function getMethodParameters(string $className, string $methodName): array
    {
        $key = "{$className}::{$methodName}";

        if (isset($this->methodParameterCache[$key])) {
            $this->hits++;
            return $this->methodParameterCache[$key];
        }

        $this->misses++;
        $method = $this->getMethod($className, $methodName);

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $parameters[] = [
                'name' => $parameter->getName(),
                'type' => $type,
                'hasType' => $parameter->hasType(),
                'isBuiltin' => $type instanceof \ReflectionNamedType && $type->isBuiltin(),
                'allowsNull' => $parameter->allowsNull(),
                'isOptional' => $parameter->isOptional(),
                'hasDefaultValue' => $parameter->isDefaultValueAvailable(),
                'defaultValue' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null
            ];
        }

        $this->methodParameterCache[$key] = $parameters;
        return $parameters;
    }

    /**
     * Check if a class is instantiable (cached).
     */
    public function isInstantiable(string $className): bool
    {
        return $this->getClass($className)->isInstantiable();
    }

    /**
     * Clear all caches.
     */
    public function clear(): void
    {
        $this->classCache = [];
        $this->methodCache = [];
        $this->functionCache = [];
        $this->constructorCache = [];
        $this->methodParameterCache = [];
        $this->hits = 0;
        $this->misses = 0;
    }

    /**
     * Clear cache for a specific class.
     */
    public function clearClass(string $className): void
    {
        unset($this->classCache[$className]);
        unset($this->constructorCache[$className]);

        // Clear method-related caches for this class
        foreach ($this->methodCache as $key => $method) {
            if (str_starts_with($key, $className . '::')) {
                unset($this->methodCache[$key]);
            }
        }

        foreach ($this->methodParameterCache as $key => $parameters) {
            if (str_starts_with($key, $className . '::')) {
                unset($this->methodParameterCache[$key]);
            }
        }
    }

    /**
     * Get cache statistics.
      * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $total = $this->hits + $this->misses;
        $hitRatio = $total > 0 ? ($this->hits / $total) * 100 : 0;

        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total_requests' => $total,
            'hit_ratio' => $hitRatio,
            'cached_classes' => count($this->classCache),
            'cached_methods' => count($this->methodCache),
            'cached_functions' => count($this->functionCache),
            'cached_constructors' => count($this->constructorCache),
            'cached_method_parameters' => count($this->methodParameterCache)
        ];
    }

    /**
     * Get memory usage of the cache.
      * @return array<string, int>
     */
    public function getMemoryUsage(): array
    {
        $beforeMemory = memory_get_usage(true);

        // Force garbage collection to get accurate memory usage
        gc_collect_cycles();

        $baseMemory = memory_get_usage(true);

        // Estimate cache memory usage
        $estimatedCacheMemory = (
            count($this->classCache) * 1024 +  // Rough estimate per ReflectionClass
            count($this->methodCache) * 512 +   // Rough estimate per ReflectionMethod
            count($this->functionCache) * 512 + // Rough estimate per ReflectionFunction
            count($this->constructorCache) * 256 + // Rough estimate per cached constructor
            count($this->methodParameterCache) * 256 // Rough estimate per cached method parameters
        );

        return [
            'estimated_cache_memory' => $estimatedCacheMemory,
            'current_memory_usage' => $baseMemory,
            'cache_efficiency' => $this->hits > 0 ? $estimatedCacheMemory / $this->hits : 0
        ];
    }

    /**
     * Optimize cache by removing least recently used items if cache is too large.
     */
    public function optimize(): void
    {
        $maxCacheSize = 1000; // Maximum number of cached items per type

        if (count($this->classCache) > $maxCacheSize) {
            $this->classCache = array_slice($this->classCache, -$maxCacheSize, null, true);
        }

        if (count($this->methodCache) > $maxCacheSize) {
            $this->methodCache = array_slice($this->methodCache, -$maxCacheSize, null, true);
        }

        if (count($this->constructorCache) > $maxCacheSize) {
            $this->constructorCache = array_slice($this->constructorCache, -$maxCacheSize, null, true);
        }

        if (count($this->methodParameterCache) > $maxCacheSize) {
            $this->methodParameterCache = array_slice($this->methodParameterCache, -$maxCacheSize, null, true);
        }
    }
}
