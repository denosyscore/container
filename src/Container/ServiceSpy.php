<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

/**
 * Service spy for monitoring and testing service interactions.
 */
class ServiceSpy
{
    /** @var array<int, array<string, mixed>> */
    private array $calls = [];
    private int $resolutionCount = 0;
    private float $totalResolutionTime = 0.0;

    /**
     * @param string $abstract The abstract identifier being spied on
     * @param mixed $instance The actual service instance
     */
    public function __construct(
        public readonly string $abstract,
        private mixed $instance
    ) {}

    /**
     * Record a method call on the spied service.
     * 
     * @param string $method The method name
     * @param array<mixed> $arguments The method arguments
     * @param mixed $result The method result
     * @param float $executionTime The execution time in milliseconds
     */
    public function recordCall(string $method, array $arguments, mixed $result, float $executionTime): void
    {
        $this->calls[] = [
            'method' => $method,
            'arguments' => $arguments,
            'result' => $result,
            'execution_time' => $executionTime,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Record a resolution of the spied service.
     * 
     * @param float $resolutionTime The time it took to resolve in milliseconds
     */
    public function recordResolution(float $resolutionTime): void
    {
        $this->resolutionCount++;
        $this->totalResolutionTime += $resolutionTime;
    }

    /**
     * Get all recorded method calls.
     * 
     * @return array<int, array<string, mixed>>
     */
    public function getCalls(): array
    {
        return $this->calls;
    }

    /**
     * Get calls for a specific method.
     * 
     * @param string $method The method name
     * @return array<array<string, mixed>>
     */
    public function getCallsFor(string $method): array
    {
        return array_filter($this->calls, fn($call) => $call['method'] === $method);
    }

    /**
     * Get the number of times a method was called.
     * 
     * @param string|null $method The method name, or null for all calls
     */
    public function getCallCount(?string $method = null): int
    {
        if ($method === null) {
            return count($this->calls);
        }

        return count($this->getCallsFor($method));
    }

    /**
     * Check if a method was called.
     * 
     * @param string $method The method name
     */
    public function wasCalled(string $method): bool
    {
        return $this->getCallCount($method) > 0;
    }

    /**
     * Check if a method was called with specific arguments.
     * 
     * @param string $method The method name
     * @param array<mixed> $arguments The expected arguments
     */
    public function wasCalledWith(string $method, array $arguments): bool
    {
        foreach ($this->getCallsFor($method) as $call) {
            if ($call['arguments'] === $arguments) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the number of times the service was resolved.
     */
    public function getResolutionCount(): int
    {
        return $this->resolutionCount;
    }

    /**
     * Get the average resolution time.
     */
    public function getAverageResolutionTime(): float
    {
        if ($this->resolutionCount === 0) {
            return 0.0;
        }

        return $this->totalResolutionTime / $this->resolutionCount;
    }

    /**
     * Get the total resolution time.
     */
    public function getTotalResolutionTime(): float
    {
        return $this->totalResolutionTime;
    }

    /**
     * Get the underlying service instance.
     */
    public function getInstance(): mixed
    {
        return $this->instance;
    }

    /**
     * Reset all recorded data.
     */
    public function reset(): void
    {
        $this->calls = [];
        $this->resolutionCount = 0;
        $this->totalResolutionTime = 0.0;
    }

    /**
     * Get a summary of spy statistics.
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $methodCounts = [];
        foreach ($this->calls as $call) {
            $method = $call['method'];
            $methodCounts[$method] = ($methodCounts[$method] ?? 0) + 1;
        }

        return [
            'abstract' => $this->abstract,
            'total_calls' => count($this->calls),
            'method_calls' => $methodCounts,
            'resolution_count' => $this->resolutionCount,
            'average_resolution_time' => $this->getAverageResolutionTime(),
            'total_resolution_time' => $this->totalResolutionTime
        ];
    }
}