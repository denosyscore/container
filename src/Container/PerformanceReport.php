<?php

declare(strict_types=1);

namespace Denosys\Container;

/**
 * Performance analysis report for the container.
 */
class PerformanceReport
{
    /**
     * @param int $totalResolutions Total number of service resolutions
     * @param float $averageResolutionTime Average time per resolution in milliseconds
     * @param array<string, float> $slowestResolutions Slowest resolutions with their times
     * @param int $cacheHits Number of cache hits
     * @param int $cacheMisses Number of cache misses
     * @param array<string, mixed> $memoryUsage Memory usage statistics
     * @param array<string, int> $resolutionCounts Count of resolutions per service
     * @param array<string, mixed> $optimizationSuggestions Performance optimization suggestions
     */
    public function __construct(
        /**
         * @param array<string, mixed> $slowestResolutions
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly int $totalResolutions,
        /**
         * @param array<string, mixed> $slowestResolutions
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly float $averageResolutionTime,
        /**
         * @param array<string, mixed> $slowestResolutions
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly array $slowestResolutions,
        /**
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly int $cacheHits,
        /**
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly int $cacheMisses,
        /**
         * @param array<string, mixed> $memoryUsage
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly array $memoryUsage,
        /**
         * @param array<string, mixed> $resolutionCounts
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly array $resolutionCounts = [],
        /**
         * @param array<string, mixed> $optimizationSuggestions
         */
        public readonly array $optimizationSuggestions = []
    ) {}

    /**
     * Get the cache hit ratio as a percentage.
     */
    public function getCacheHitRatio(): float
    {
        $totalCacheAttempts = $this->cacheHits + $this->cacheMisses;
        
        if ($totalCacheAttempts === 0) {
            return 0.0;
        }

        return ($this->cacheHits / $totalCacheAttempts) * 100;
    }

    /**
     * Get the most frequently resolved services.
     * 
     * @param int $limit Maximum number of services to return
     * @return array<string, int>
     */
    /**
     * @return array<string>
     */
public function getMostResolvedServices(int $limit = 10): array
    {
        $counts = $this->resolutionCounts;
        arsort($counts);
        return array_slice($counts, 0, $limit, true);
    }

    /**
     * Get performance score (0-100, higher is better).
     */
    public function getPerformanceScore(): int
    {
        $score = 100;

        // Deduct points for slow average resolution time
        if ($this->averageResolutionTime > 10) {
            $score -= min(30, ($this->averageResolutionTime - 10) * 2);
        }

        // Deduct points for low cache hit ratio
        $cacheRatio = $this->getCacheHitRatio();
        if ($cacheRatio < 80) {
            $score -= (80 - $cacheRatio) / 2;
        }

        // Deduct points for high memory usage
        $memoryMB = ($this->memoryUsage['peak'] ?? 0) / 1024 / 1024;
        if ($memoryMB > 50) {
            $score -= min(20, ($memoryMB - 50) / 5);
        }

        return max(0, (int) $score);
    }

    /**
     * Get a summary of the performance report.
     */
    public function getSummary(): string
    {
        $score = $this->getPerformanceScore();
        $cacheRatio = number_format($this->getCacheHitRatio(), 1);
        $avgTime = number_format($this->averageResolutionTime, 2);
        $memoryMB = number_format(($this->memoryUsage['peak'] ?? 0) / 1024 / 1024, 2);

        return "Performance Score: {$score}/100\n" .
               "Total Resolutions: {$this->totalResolutions}\n" .
               "Average Resolution Time: {$avgTime}ms\n" .
               "Cache Hit Ratio: {$cacheRatio}%\n" .
               "Peak Memory Usage: {$memoryMB}MB";
    }

    /**
     * Check if performance is acceptable.
     */
    public function isPerformanceAcceptable(): bool
    {
        return $this->getPerformanceScore() >= 70;
    }

    /**
     * Get performance issues that need attention.
     * 
     * @return array<string>
     */
    public function getPerformanceIssues(): array
    {
        $issues = [];

        if ($this->averageResolutionTime > 15) {
            $issues[] = "High average resolution time ({$this->averageResolutionTime}ms)";
        }

        if ($this->getCacheHitRatio() < 70) {
            $ratio = number_format($this->getCacheHitRatio(), 1);
            $issues[] = "Low cache hit ratio ({$ratio}%)";
        }

        $memoryMB = ($this->memoryUsage['peak'] ?? 0) / 1024 / 1024;
        if ($memoryMB > 100) {
            $issues[] = "High memory usage (" . number_format($memoryMB, 2) . "MB)";
        }

        return $issues;
    }
}