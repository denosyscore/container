<?php

declare(strict_types=1);

namespace Tests\Unit;

use Denosys\Container\Container;
use PHPUnit\Framework\TestCase;

// Test interfaces and classes
interface LoggerInterface {}
class FileLogger implements LoggerInterface {}
class NullLogger implements LoggerInterface {}

class ServiceWithLogger {
    public function __construct(public readonly LoggerInterface $logger) {}
}

class AnotherServiceWithLogger {
    public function __construct(public readonly LoggerInterface $logger) {}
}

class ContextualBindingResolutionTest extends TestCase
{
    public function testContextualBindingIsUsedWhenResolvingDependency(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, NullLogger::class);

        $container->when(ServiceWithLogger::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        $service = $container->get(ServiceWithLogger::class);

        $this->assertInstanceOf(FileLogger::class, $service->logger);
    }

    public function testDefaultBindingUsedWhenNoContextualBindingMatches(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, NullLogger::class);

        $container->when(ServiceWithLogger::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        // AnotherServiceWithLogger has no contextual override — should get NullLogger
        $service = $container->get(AnotherServiceWithLogger::class);

        $this->assertInstanceOf(NullLogger::class, $service->logger);
    }

    public function testContextIsPoppedAfterResolution(): void
    {
        $container = new Container();
        $container->bind(LoggerInterface::class, NullLogger::class);

        $container->when(ServiceWithLogger::class)
            ->needs(LoggerInterface::class)
            ->give(FileLogger::class);

        // Resolve once
        $container->get(ServiceWithLogger::class);

        // After resolution, context stack must be empty — resolving again should still work
        $service2 = $container->get(AnotherServiceWithLogger::class);
        $this->assertInstanceOf(NullLogger::class, $service2->logger);
    }
}
