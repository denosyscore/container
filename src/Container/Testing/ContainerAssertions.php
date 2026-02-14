<?php

declare(strict_types=1);

namespace Denosys\Container\Testing;

use Denosys\Container\Container;
use PHPUnit\Framework\Assert;

/**
 * Container-specific assertions for testing.
 */
class ContainerAssertions
{
    public static function assertBound(Container $container, string $abstract, string $message = ''): void
    {
        Assert::assertTrue(
            $container->has($abstract),
            $message ?: "Failed asserting that '{$abstract}' is bound in the container"
        );
    }

    public static function assertNotBound(Container $container, string $abstract, string $message = ''): void
    {
        Assert::assertFalse(
            $container->has($abstract),
            $message ?: "Failed asserting that '{$abstract}' is not bound in the container"
        );
    }

    public static function assertResolved(Container $container, string $abstract, string $message = ''): void
    {
        $history = $container->getResolutionHistory();
        $resolved = array_filter($history, fn($entry) => $entry['abstract'] === $abstract);
        
        Assert::assertNotEmpty(
            $resolved,
            $message ?: "Failed asserting that '{$abstract}' was resolved from the container"
        );
    }

    public static function assertNotResolved(Container $container, string $abstract, string $message = ''): void
    {
        $history = $container->getResolutionHistory();
        $resolved = array_filter($history, fn($entry) => $entry['abstract'] === $abstract);
        
        Assert::assertEmpty(
            $resolved,
            $message ?: "Failed asserting that '{$abstract}' was not resolved from the container"
        );
    }

    public static function assertResolvedTimes(Container $container, string $abstract, int $expectedTimes, string $message = ''): void
    {
        $history = $container->getResolutionHistory();
        $resolved = array_filter($history, fn($entry) => $entry['abstract'] === $abstract);
        $actualTimes = count($resolved);
        
        Assert::assertEquals(
            $expectedTimes,
            $actualTimes,
            $message ?: "Failed asserting that '{$abstract}' was resolved {$expectedTimes} times (actual: {$actualTimes})"
        );
    }

    public static function assertInstanceOf(Container $container, string $abstract, string $expectedClass, string $message = ''): void
    {
        $instance = $container->get($abstract);
        
        Assert::assertInstanceOf(
            $expectedClass,
            $instance,
            $message ?: "Failed asserting that '{$abstract}' resolves to an instance of '{$expectedClass}'"
        );
    }

    public static function assertSameInstance(Container $container, string $abstract, string $message = ''): void
    {
        $instance1 = $container->get($abstract);
        $instance2 = $container->get($abstract);
        
        Assert::assertSame(
            $instance1,
            $instance2,
            $message ?: "Failed asserting that '{$abstract}' returns the same instance (singleton behavior)"
        );
    }

    public static function assertDifferentInstance(Container $container, string $abstract, string $message = ''): void
    {
        $instance1 = $container->get($abstract);
        $instance2 = $container->get($abstract);
        
        Assert::assertNotSame(
            $instance1,
            $instance2,
            $message ?: "Failed asserting that '{$abstract}' returns different instances (transient behavior)"
        );
    }

    /**
     * @param array<string, mixed> $expectedServices
     */
    public static function assertTagged(Container $container, string $tag, array $expectedServices, string $message = ''): void
    {
        $taggedServices = $container->tagged($tag);
        $serviceClasses = array_map('get_class', $taggedServices);
        
        Assert::assertEquals(
            $expectedServices,
            $serviceClasses,
            $message ?: "Failed asserting that tag '{$tag}' contains expected services"
        );
    }

    public static function assertValidContainer(Container $container, string $message = ''): void
    {
        $validation = $container->validate();
        
        Assert::assertTrue(
            $validation->isValid(),
            $message ?: "Container validation failed: " . $validation->getSummary()
        );
    }

    public static function assertPerformanceAcceptable(Container $container, float $maxAverageTime = 10.0, string $message = ''): void
    {
        $metrics = $container->getPerformanceMetrics();
        
        Assert::assertLessThanOrEqual(
            $maxAverageTime,
            $metrics->averageResolutionTime,
            $message ?: "Average resolution time ({$metrics->averageResolutionTime}ms) exceeds acceptable threshold ({$maxAverageTime}ms)"
        );
    }
}