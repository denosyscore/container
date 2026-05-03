<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerResolutionException;

interface LoggerInterface2 {}
class FileLogger implements LoggerInterface2 {}
class DatabaseLogger implements LoggerInterface2 {}

class UserRepository {
    public function __construct(public readonly LoggerInterface2 $logger) {}
}

class UserService {
    public function __construct(public readonly UserRepository $repo) {}
}

// Circular dep
class CircA { public function __construct(public CircB $b) {} }
class CircB { public function __construct(public CircA $a) {} }

class ContainerIntegrationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testImplementsPsr11(): void
    {
        self::assertInstanceOf(ContainerInterface::class, $this->container);
    }

    public function testPsr11NotFoundExceptionInterface(): void
    {
        try {
            $this->container->get('NonExistent\\Class\\That\\Does\\Not\\Exist');
            self::fail('Expected exception not thrown');
        } catch (\Throwable $e) {
            self::assertInstanceOf(ContainerExceptionInterface::class, $e);
        }
    }

    public function testDeepAutowiringChain(): void
    {
        $this->container->bind(LoggerInterface2::class, FileLogger::class);
        $result = $this->container->get(UserService::class);
        self::assertInstanceOf(UserService::class, $result);
        self::assertInstanceOf(UserRepository::class, $result->repo);
        self::assertInstanceOf(FileLogger::class, $result->repo->logger);
    }

    public function testCircularDependencyException(): void
    {
        $this->expectException(ContainerResolutionException::class);
        $this->container->get(CircA::class);
    }

    public function testSwitchingImplementation(): void
    {
        $this->container->bind(LoggerInterface2::class, FileLogger::class);
        $first = $this->container->get(LoggerInterface2::class);
        self::assertInstanceOf(FileLogger::class, $first);

        $this->container->bind(LoggerInterface2::class, DatabaseLogger::class);
        $second = $this->container->get(LoggerInterface2::class);
        self::assertInstanceOf(DatabaseLogger::class, $second);
    }

    public function testSingletonSharedAcrossResolutionChain(): void
    {
        $this->container->singleton(LoggerInterface2::class, FileLogger::class);
        $this->container->bind(UserRepository::class);

        $repo1 = $this->container->get(UserRepository::class);
        $repo2 = $this->container->get(UserRepository::class);

        self::assertSame($repo1->logger, $repo2->logger);
    }
}
