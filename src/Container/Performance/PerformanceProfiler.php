<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Performance;

use CFXP\Core\Container\PerformanceReport;

/**
 * Performance profiler for the enhanced container.
 */
class PerformanceProfiler
{
    /**
     * @var array<string, array> Resolution times by service
     */
    /** @var array<string, mixed> */

    private array $resolutionTimes = [];

    /**
     * @var array<string, int> Resolution counts by service
     */
    /** @var array<string, mixed> */

    private array $resolutionCounts = [];

    /**
     * @var array<string, float> Method injection times
     */
    /** @var array<string, mixed> */

    private array $methodInjectionTimes = [];

    /**
     * @var int Total number of resolutions
     */
    private int $totalResolutions = 0;

    /**
     * @var float Total resolution time
     */
    private float $totalResolutionTime = 0.0;

    /**
     * @var float Peak memory usage
     */
    private float $peakMemoryUsage = 0.0;

    /**
     * @var float Start time for profiling session
     */
    private float $sessionStartTime;

    /**
     * @var array<string, mixed> Custom metrics
     */
    /** @var array<string, mixed> */

    private array $customMetrics = [];

    public function __construct()
    {
        $this->sessionStartTime = microtime(true);
        $this->peakMemoryUsage = memory_get_peak_usage(true);
    }

    /**
     * Record a service resolution.
     */
    public function recordResolution(string $abstract, float $resolutionTime): void
    {
        $this->totalResolutions++;
        $this->totalResolutionTime += $resolutionTime;

        if (!isset($this->resolutionTimes[$abstract])) {
            $this->resolutionTimes[$abstract] = [];
            $this->resolutionCounts[$abstract] = 0;
        }

        $this->resolutionTimes[$abstract][] = $resolutionTime;
        $this->resolutionCounts[$abstract]++;

        // Update peak memory usage
        $currentMemory = memory_get_peak_usage(true);
        if ($currentMemory > $this->peakMemoryUsage) {
            $this->peakMemoryUsage = $currentMemory;
        }
    }

    /**
     * Record method injection time.
     */
    public function recordMethodInjection(float $injectionTime): void
    {
        $this->methodInjectionTimes[] = $injectionTime;
    }

    /**
     * Record a custom metric.
     */
    public function recordMetric(string $name, mixed $value): void
    {
        if (!isset($this->customMetrics[$name])) {
            $this->customMetrics[$name] = [];
        }

        $this->customMetrics[$name][] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Get the average resolution time for a specific service.
     */
    public function getAverageResolutionTime(string $abstract): float
    {
        if (!isset($this->resolutionTimes[$abstract])) {
            return 0.0;
        }

        $times = $this->resolutionTimes[$abstract];
        return array_sum($times) / count($times);
    }

    /**
     * Get the slowest resolutions.
     */
    /**
     * @return array<string, mixed>
     */
public function getSlowestResolutions(int $limit = 10): array
    {
        $slowest = [];

        foreach ($this->resolutionTimes as $abstract => $times) {
            $maxTime = max($times);
            $slowest[$abstract] = $maxTime;
        }

        arsort($slowest);
        return array_slice($slowest, 0, $limit, true);
    }

    /**
     * Get the most frequently resolved services.
      * @return array<string, int>
     */
    public function getMostResolvedServices(int $limit = 10): array
    {
        $counts = $this->resolutionCounts;
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    /**
     * Calculate cache hit ratio from external cache.
     */
    public function setCacheMetrics(int $hits, int $misses): void
    {
        $this->customMetrics['cache_hits'] = $hits;
        $this->customMetrics['cache_misses'] = $misses;
    }

    /**
     * Get performance bottlenecks.
      * @return array<string, mixed>
     */
    public function getBottlenecks(): array
    {
        $bottlenecks = [];

        // Slow resolution times
        $averageTime = $this->totalResolutions > 0 ? $this->totalResolutionTime / $this->totalResolutions : 0;
        foreach ($this->resolutionTimes as $abstract => $times) {
            $serviceAverage = array_sum($times) / count($times);
            if ($serviceAverage > $averageTime * 2) { // More than 2x the average
                $bottlenecks[] = [
                    'type' => 'slow_resolution',
                    'service' => $abstract,
                    'average_time' => $serviceAverage,
                    'severity' => $serviceAverage > $averageTime * 5 ? 'high' : 'medium'
                ];
            }
        }

        // Frequently resolved services (might benefit from singleton)
        foreach ($this->resolutionCounts as $abstract => $count) {
            if ($count > 10) { // Resolved more than 10 times
                $bottlenecks[] = [
                    'type' => 'frequent_resolution',
                    'service' => $abstract,
                    'count' => $count,
                    'severity' => $count > 50 ? 'high' : 'medium'
                ];
            }
        }

        // High memory usage
        $memoryMB = $this->peakMemoryUsage / 1024 / 1024;
        if ($memoryMB > 100) {
            $bottlenecks[] = [
                'type' => 'high_memory',
                'memory_mb' => $memoryMB,
                'severity' => $memoryMB > 500 ? 'high' : 'medium'
            ];
        }

        return $bottlenecks;
    }

    /**
     * Get optimization suggestions based on collected data.
      * @return array<string, mixed>
     */
    public function getOptimizationSuggestions(): array
    {
        $suggestions = [];
        $bottlenecks = $this->getBottlenecks();

        foreach ($bottlenecks as $bottleneck) {
            switch ($bottleneck['type']) {
                case 'slow_resolution':
                    $suggestions[] = "Consider optimizing '{$bottleneck['service']}' resolution (avg: {$bottleneck['average_time']}ms)";
                    break;
                case 'frequent_resolution':
                    $suggestions[] = "Consider making '{$bottleneck['service']}' a singleton (resolved {$bottleneck['count']} times)";
                    break;
                case 'high_memory':
                    $suggestions[] = "High memory usage detected ({$bottleneck['memory_mb']}MB) - consider lazy loading";
                    break;
            }
        }

        // Cache-specific suggestions
        $cacheHits = $this->customMetrics['cache_hits'] ?? 0;
        $cacheMisses = $this->customMetrics['cache_misses'] ?? 0;
        $totalCache = $cacheHits + $cacheMisses;

        if ($totalCache > 0) {
            $hitRatio = ($cacheHits / $totalCache) * 100;
            if ($hitRatio < 70) {
                $suggestions[] = "Low cache hit ratio ({$hitRatio}%) - consider cache optimization";
            }
        }

        return $suggestions;
    }

    /**
     * Generate a comprehensive performance report.
     */
    public function getReport(): PerformanceReport
    {
        $averageResolutionTime = $this->totalResolutions > 0 
            ? $this->totalResolutionTime / $this->totalResolutions 
            : 0.0;

        $cacheHits = (int) ($this->customMetrics['cache_hits'] ?? 0);
        $cacheMisses = (int) ($this->customMetrics['cache_misses'] ?? 0);

        $memoryUsage = [
            'peak' => $this->peakMemoryUsage,
            'current' => memory_get_usage(true),
            'session_duration' => microtime(true) - $this->sessionStartTime
        ];

        return new PerformanceReport(
            totalResolutions: $this->totalResolutions,
            averageResolutionTime: $averageResolutionTime,
            slowestResolutions: $this->getSlowestResolutions(),
            cacheHits: $cacheHits,
            cacheMisses: $cacheMisses,
            memoryUsage: $memoryUsage,
            resolutionCounts: $this->resolutionCounts,
            optimizationSuggestions: $this->getOptimizationSuggestions()
        );
    }

    /**
     * Reset all profiling data.
     */
    public function reset(): void
    {
        $this->resolutionTimes = [];
        $this->resolutionCounts = [];
        $this->methodInjectionTimes = [];
        $this->totalResolutions = 0;
        $this->totalResolutionTime = 0.0;
        $this->peakMemoryUsage = memory_get_usage(true);
        $this->sessionStartTime = microtime(true);
        $this->customMetrics = [];
    }

    /**
     * Export profiling data for analysis.
      * @return array<string, mixed>
     */
    public function exportData(): array
    {
        return [
            'session_start' => $this->sessionStartTime,
            'total_resolutions' => $this->totalResolutions,
            'total_resolution_time' => $this->totalResolutionTime,
            'resolution_times' => $this->resolutionTimes,
            'resolution_counts' => $this->resolutionCounts,
            'method_injection_times' => $this->methodInjectionTimes,
            'peak_memory_usage' => $this->peakMemoryUsage,
            'custom_metrics' => $this->customMetrics,
            'bottlenecks' => $this->getBottlenecks(),
            'optimization_suggestions' => $this->getOptimizationSuggestions()
        ];
    }
}