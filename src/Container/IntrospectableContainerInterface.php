<?php

declare(strict_types=1);

namespace Denosys\Container;

/**
 * Interface for containers that support introspection and profiling.
 */
interface IntrospectableContainerInterface
{
    /**
     * Get all registered bindings with their metadata.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getBindings(): array;

    /**
     * Get the dependency graph for a specific abstract.
     *
     * @param string $abstract
     *
     * @return array<string, mixed>
     */
    public function getDependencies(string $abstract): array;

    /**
     * Validate the container configuration without instantiating services.
     *
     * @return ValidationResult
     */
    public function validate(): ValidationResult;

    /**
     * Compile the container for optimized production performance.
     *
     * @param string $path
     */
    public function compile(string $path): void;

    /**
     * Get performance metrics for the container.
     *
     * @return PerformanceReport
     */
    public function getPerformanceMetrics(): PerformanceReport;
}
