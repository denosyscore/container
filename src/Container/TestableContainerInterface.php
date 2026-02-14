<?php

declare(strict_types=1);

namespace Denosys\Container;

/**
 * Interface for containers that support testing utilities.
 */
interface TestableContainerInterface
{
    /**
     * Register a mock implementation for testing.
     *
     * @param string $abstract
     * @param mixed $mock
     */
    public function mock(string $abstract, mixed $mock): void;

    /**
     * Create a spy for monitoring service interactions.
     *
     * @param string $abstract
     *
     * @return ServiceSpy
     */
    public function spy(string $abstract): ServiceSpy;

    /**
     * Get the resolution history for debugging.
     *
     * @return array<array<string, mixed>>
     */
    public function getResolutionHistory(): array;
}
