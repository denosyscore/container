<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerException;
use Denosys\Container\Exceptions\NotFoundException;
use Denosys\Container\Exceptions\ContainerResolutionException;

// Test fixtures
class SimpleService {}
class ServiceWithDep {
    public function __construct(public readonly SimpleService $dep) {}
}
class ServiceWithDefault {
    public function __construct(public readonly string $name = 'default') {}
}
interface TestInterface {}
class TestImplementation implements TestInterface {}
class AbstractDep {
    public function __construct(public readonly TestInterface $iface) {}
}

class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testBindAndGet(): void
    {
        $this->container->bind(SimpleService::class);
        $result = $this->container->get(SimpleService::class);
        self::assertInstanceOf(SimpleService::class, $result);
    }

    public function testBindWithClosure(): void
    {
        $this->container->bind(SimpleService::class, fn() => new SimpleService());
        self::assertInstanceOf(SimpleService::class, $this->container->get(SimpleService::class));
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $this->container->singleton(SimpleService::class);
        $a = $this->container->get(SimpleService::class);
        $b = $this->container->get(SimpleService::class);
        self::assertSame($a, $b);
    }

    public function testBindReturnsNewInstanceEachTime(): void
    {
        $this->container->bind(SimpleService::class);
        $a = $this->container->get(SimpleService::class);
        $b = $this->container->get(SimpleService::class);
        self::assertNotSame($a, $b);
    }

    public function testInstanceRegistration(): void
    {
        $instance = new SimpleService();
        $this->container->instance(SimpleService::class, $instance);
        self::assertSame($instance, $this->container->get(SimpleService::class));
    }

    public function testInstanceThrowsForWrongInterface(): void
    {
        $this->expectException(ContainerException::class);
        $this->container->instance(TestInterface::class, new SimpleService());
    }

    public function testAlias(): void
    {
        $this->container->singleton(SimpleService::class);
        $this->container->alias('simple', SimpleService::class);
        self::assertSame(
            $this->container->get(SimpleService::class),
            $this->container->get('simple')
        );
    }

    public function testAliasThrowsForUnboundAbstract(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->alias('foo', 'NonExistentClass');
    }

    public function testExtendModifiesBinding(): void
    {
        $this->container->bind(SimpleService::class);
        $this->container->extend(SimpleService::class, function (SimpleService $s, Container $c) {
            return $s; // in real use you'd wrap it
        });
        self::assertInstanceOf(SimpleService::class, $this->container->get(SimpleService::class));
    }

    public function testExtendThrowsForUnbound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->container->extend('Unbound', fn($s, $c) => $s);
    }

    public function testHasReturnsTrueForBound(): void
    {
        $this->container->bind(SimpleService::class);
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsTrueForInstantiableClass(): void
    {
        self::assertTrue($this->container->has(SimpleService::class));
    }

    public function testHasReturnsFalseForUnknown(): void
    {
        self::assertFalse($this->container->has('NonExistentClass'));
    }

    public function testAutowiringResolvesConstructorDependency(): void
    {
        $result = $this->container->get(ServiceWithDep::class);
        self::assertInstanceOf(ServiceWithDep::class, $result);
        self::assertInstanceOf(SimpleService::class, $result->dep);
    }

    public function testBindingInterfaceToImplementation(): void
    {
        $this->container->bind(TestInterface::class, TestImplementation::class);
        $result = $this->container->get(TestInterface::class);
        self::assertInstanceOf(TestImplementation::class, $result);
    }

    public function testCircularDependencyThrows(): void
    {
        $this->expectException(ContainerResolutionException::class);
        // Simulate circular dep via bindings
        $this->container->bind('A', fn(Container $c) => $c->get('B'));
        $this->container->bind('B', fn(Container $c) => $c->get('A'));
        $this->container->get('A');
    }

    public function testUnresolvableInterfaceThrows(): void
    {
        $this->expectException(ContainerResolutionException::class);
        $this->container->get(TestInterface::class);
    }

    public function testResolutionHistoryIsTracked(): void
    {
        $this->container->bind(SimpleService::class);
        $this->container->get(SimpleService::class);
        $history = $this->container->getResolutionHistory();
        self::assertNotEmpty($history);
        self::assertSame(SimpleService::class, $history[0]['abstract']);
    }

    public function testResolutionHistoryRingBuffer(): void
    {
        $this->container->setResolutionHistoryLimit(3);
        $this->container->bind(SimpleService::class);
        for ($i = 0; $i < 5; $i++) {
            $this->container->get(SimpleService::class);
        }
        self::assertCount(3, $this->container->getResolutionHistory());
    }

    public function testDropStaleInstancesOnRebind(): void
    {
        $this->container->singleton(SimpleService::class);
        $original = $this->container->get(SimpleService::class);

        // Rebind should drop the cached singleton
        $this->container->singleton(SimpleService::class);
        $new = $this->container->get(SimpleService::class);
        self::assertNotSame($original, $new);
    }

    public function testGetAliasReturnsOriginalForNonAlias(): void
    {
        self::assertSame(SimpleService::class, $this->container->getAlias(SimpleService::class));
    }
}
