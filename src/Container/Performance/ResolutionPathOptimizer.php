<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Performance;

use CFXP\Core\Container\ContainerInterface;

/**
 * Resolution path optimizer for frequently accessed services.
 * 
 * Analyzes service resolution patterns and optimizes resolution paths
 * for frequently accessed services to improve overall performance.
 */
class ResolutionPathOptimizer
{
    /**
     * @var array<string, int> Resolution frequency counter
     */
    /** @var array<string, mixed> */

    private array $resolutionFrequency = [];

    /**
     * @var array<string, float> Average resolution times
     */
    /** @var array<string, mixed> */

    private array $averageResolutionTimes = [];

    /**
     * @var array<string, array> Optimized resolution paths
     */
    /** @var array<string, mixed> */

    private array $optimizedPaths = [];

    /**
     * @var array<string, mixed> Cached resolved instances for frequent services
     */
    /** @var array<string, mixed> */

    private array $frequentServiceCache = [];

    /**
     * @var int Minimum frequency threshold for optimization
     */
    private int $optimizationThreshold = 10;

    /**
     * @var float Time threshold for considering a service slow (in milliseconds)
     */
    private float $slowResolutionThreshold = 5.0;

    /**
     * @var int Maximum cache size to prevent memory bloat
     */
    private int $maxCacheSize = 100;

    /**
     * Record a service resolution for optimization analysis.
     */
    public function recordResolution(string $abstract, float $resolutionTime): void
    {
        // Update frequency
        $this->resolutionFrequency[$abstract] = ($this->resolutionFrequency[$abstract] ?? 0) + 1;

        // Update average resolution time
        $currentAverage = $this->averageResolutionTimes[$abstract] ?? 0;
        $frequency = $this->resolutionFrequency[$abstract];
        
        $this->averageResolutionTimes[$abstract] = (($currentAverage * ($frequency - 1)) + $resolutionTime) / $frequency;

        // Check if this service should be optimized
        $this->evaluateForOptimization($abstract);
    }

    /**
     * Get optimized resolution for a service if available.
     */
    public function getOptimizedResolution(string $abstract, ContainerInterface $container): mixed
    {
        // Check if we have a cached instance for frequent services
        if (isset($this->frequentServiceCache[$abstract])) {
            return $this->frequentServiceCache[$abstract];
        }

        // Check if we have an optimized path
        if (isset($this->optimizedPaths[$abstract])) {
            return $this->executeOptimizedPath($abstract, $this->optimizedPaths[$abstract], $container);
        }

        return null; // No optimization available
    }

    /**
     * Check if a service has optimization available.
     */
    public function hasOptimization(string $abstract): bool
    {
        return isset($this->optimizedPaths[$abstract]) || isset($this->frequentServiceCache[$abstract]);
    }

    /**
     * Get frequently accessed services.
     */
    /**
     * @return array<string>
     */
public function getFrequentServices(int $limit = 20): array
    {
        arsort($this->resolutionFrequency);
        return array_slice($this->resolutionFrequency, 0, $limit, true);
    }

    /**
     * Get slow resolution services.
      * @return array<string, float>
     */
    public function getSlowServices(int $limit = 20): array
    {
        $slowServices = array_filter(
            $this->averageResolutionTimes,
            fn($time) => $time > $this->slowResolutionThreshold
        );

        arsort($slowServices);
        return array_slice($slowServices, 0, $limit, true);
    }

    /**
     * Get optimization candidates.
      * @return array<string, array<string, mixed>>
     */
    public function getOptimizationCandidates(): array
    {
        $candidates = [];

        foreach ($this->resolutionFrequency as $abstract => $frequency) {
            $avgTime = $this->averageResolutionTimes[$abstract] ?? 0;
            
            $score = $this->calculateOptimizationScore($frequency, $avgTime);
            
            if ($score > 0) {
                $candidates[$abstract] = [
                    'frequency' => $frequency,
                    'average_time' => $avgTime,
                    'optimization_score' => $score,
                    'recommended_strategy' => $this->getRecommendedStrategy($frequency, $avgTime)
                ];
            }
        }

        // Sort by optimization score (highest first)
        uasort($candidates, fn($a, $b) => $b['optimization_score'] <=> $a['optimization_score']);

        return $candidates;
    }

    /**
     * Manually add a service to frequent cache.
     */
    public function cacheFrequentService(string $abstract, mixed $instance): void
    {
        // Ensure we don't exceed cache size limit
        if (count($this->frequentServiceCache) >= $this->maxCacheSize) {
            $this->evictLeastFrequent();
        }

        $this->frequentServiceCache[$abstract] = $instance;
    }

    /**
     * Remove a service from optimization.
     */
    public function removeOptimization(string $abstract): void
    {
        unset($this->optimizedPaths[$abstract]);
        unset($this->frequentServiceCache[$abstract]);
    }

    /**
     * Clear all optimizations.
     */
    public function clearOptimizations(): void
    {
        $this->optimizedPaths = [];
        $this->frequentServiceCache = [];
    }

    /**
     * Get optimization statistics.
      * @return array<string, mixed>
     */
    public function getOptimizationStatistics(): array
    {
        $totalResolutions = array_sum($this->resolutionFrequency);
        $optimizedServices = count($this->optimizedPaths) + count($this->frequentServiceCache);
        
        return [
            'total_services_tracked' => count($this->resolutionFrequency),
            'total_resolutions' => $totalResolutions,
            'optimized_services' => $optimizedServices,
            'cached_services' => count($this->frequentServiceCache),
            'optimized_paths' => count($this->optimizedPaths),
            'optimization_threshold' => $this->optimizationThreshold,
            'slow_resolution_threshold' => $this->slowResolutionThreshold,
            'average_resolution_frequency' => count($this->resolutionFrequency) > 0 
                ? $totalResolutions / count($this->resolutionFrequency) 
                : 0,
            'cache_utilization' => ($this->maxCacheSize > 0) 
                ? (count($this->frequentServiceCache) / $this->maxCacheSize) * 100 
                : 0
        ];
    }

    /**
     * Configure optimization parameters.
      * @param array<string, mixed> $options
     */
    public function configure(array $options): void
    {
        $this->optimizationThreshold = $options['optimization_threshold'] ?? $this->optimizationThreshold;
        $this->slowResolutionThreshold = $options['slow_resolution_threshold'] ?? $this->slowResolutionThreshold;
        $this->maxCacheSize = $options['max_cache_size'] ?? $this->maxCacheSize;
    }

    /**
     * Export optimization data for analysis.
      * @return array<string, mixed>
     */
    public function exportOptimizationData(): array
    {
        return [
            'resolution_frequency' => $this->resolutionFrequency,
            'average_resolution_times' => $this->averageResolutionTimes,
            'optimized_paths' => array_keys($this->optimizedPaths),
            'frequent_service_cache' => array_keys($this->frequentServiceCache),
            'configuration' => [
                'optimization_threshold' => $this->optimizationThreshold,
                'slow_resolution_threshold' => $this->slowResolutionThreshold,
                'max_cache_size' => $this->maxCacheSize
            ]
        ];
    }

    /**
     * Import optimization data from previous analysis.
      * @param array<string, mixed> $data
     */
    public function importOptimizationData(array $data): void
    {
        $this->resolutionFrequency = $data['resolution_frequency'] ?? [];
        $this->averageResolutionTimes = $data['average_resolution_times'] ?? [];
        
        if (isset($data['configuration'])) {
            $this->configure($data['configuration']);
        }

        // Re-evaluate optimizations based on imported data
        foreach ($this->resolutionFrequency as $abstract => $frequency) {
            $this->evaluateForOptimization($abstract);
        }
    }

    /**
     * Evaluate if a service should be optimized.
     */
    private function evaluateForOptimization(string $abstract): void
    {
        $frequency = $this->resolutionFrequency[$abstract] ?? 0;
        $avgTime = $this->averageResolutionTimes[$abstract] ?? 0;

        // Don't optimize if below threshold
        if ($frequency < $this->optimizationThreshold) {
            return;
        }

        $strategy = $this->getRecommendedStrategy($frequency, $avgTime);

        switch ($strategy) {
            case 'cache':
                // Will be cached when next resolved
                break;
                
            case 'optimize_path':
                $this->createOptimizedPath($abstract);
                break;
                
            case 'preload':
                // Mark for preloading in compiled container
                $this->optimizedPaths[$abstract] = ['type' => 'preload'];
                break;
        }
    }

    /**
     * Calculate optimization score for a service.
     */
    private function calculateOptimizationScore(int $frequency, float $avgTime): float
    {
        if ($frequency < $this->optimizationThreshold) {
            return 0;
        }

        // Base score from frequency
        $score = $frequency * 0.1;

        // Bonus for slow services
        if ($avgTime > $this->slowResolutionThreshold) {
            $score += ($avgTime / $this->slowResolutionThreshold) * 5;
        }

        // Bonus for very frequent services
        if ($frequency > $this->optimizationThreshold * 3) {
            $score += 10;
        }

        return $score;
    }

    /**
     * Get recommended optimization strategy.
     */
    private function getRecommendedStrategy(int $frequency, float $avgTime): string
    {
        // Very frequent services should be cached
        if ($frequency > $this->optimizationThreshold * 2) {
            return 'cache';
        }

        // Slow services should have optimized paths
        if ($avgTime > $this->slowResolutionThreshold) {
            return 'optimize_path';
        }

        // Moderately frequent services can be preloaded
        if ($frequency > $this->optimizationThreshold) {
            return 'preload';
        }

        return 'none';
    }

    /**
     * Create an optimized resolution path for a service.
     */
    private function createOptimizedPath(string $abstract): void
    {
        // For now, this is a placeholder for path optimization
        // In a full implementation, this would analyze the dependency graph
        // and create a fast resolution path
        
        $this->optimizedPaths[$abstract] = [
            'type' => 'optimized',
            'created_at' => microtime(true),
            'frequency' => $this->resolutionFrequency[$abstract] ?? 0
        ];
    }

    /**
     * Execute an optimized resolution path.
      * @param array<string> $path
     */
    private function executeOptimizedPath(string $abstract, array $path, ContainerInterface $container): mixed
    {
        // For now, fall back to normal resolution
        // In a full implementation, this would execute the optimized path
        return $container->get($abstract);
    }

    /**
     * Evict least frequently used service from cache.
     */
    private function evictLeastFrequent(): void
    {
        if (empty($this->frequentServiceCache)) {
            return;
        }

        $leastFrequent = null;
        $lowestFrequency = PHP_INT_MAX;

        foreach (array_keys($this->frequentServiceCache) as $abstract) {
            $frequency = $this->resolutionFrequency[$abstract] ?? 0;
            if ($frequency < $lowestFrequency) {
                $lowestFrequency = $frequency;
                $leastFrequent = $abstract;
            }
        }

        if ($leastFrequent !== null) {
            unset($this->frequentServiceCache[$leastFrequent]);
        }
    }
}