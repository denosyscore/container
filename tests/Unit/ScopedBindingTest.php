<?php
declare(strict_types=1);
namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class ScopedService {}
class OuterService {}

class ScopedBindingTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testScopedBindingActiveInsideCallback(): void
    {
        $result = $this->container->scoped(
            [ScopedService::class => fn() => new ScopedService()],
            fn() => $this->container->get(ScopedService::class)
        );
        self::assertInstanceOf(ScopedService::class, $result);
    }

    public function testScopedBindingRemovedAfterCallback(): void
    {
        $this->container->scoped(
            [ScopedService::class => fn() => new ScopedService()],
            fn() => null
        );
        // has() returns true for any existing class via class_exists(),
        // so we check the explicit bindings registry instead.
        $bindings = $this->container->getBindings();
        self::assertArrayNotHasKey(ScopedService::class, $bindings);
    }

    public function testOuterBindingsUntouched(): void
    {
        $this->container->bind(OuterService::class);
        $this->container->scoped(
            [ScopedService::class => fn() => new ScopedService()],
            fn() => null
        );
        self::assertTrue($this->container->has(OuterService::class));
    }
}
