<?php
declare(strict_types=1);
namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerResolutionException;

class ScopedService {}
class OuterService {}

/** Used for M-3 pre-scope snapshot tests */
class FooService {}
class FooServiceAlternative {}
class BarService {}

class ScopedBindingTest extends TestCase
{
    private Container $container;
    protected function setUp(): void { $this->container = new Container(); }

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
            ['scoped.service.test' => fn() => new ScopedService()],
            fn() => null
        );
        self::assertFalse($this->container->has('scoped.service.test'));
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

    /**
     * M-3: FooService is bound BEFORE the scope. The scope overrides it with
     * FooServiceAlternative. After scope exit the original FooService binding
     * must be restored — not deleted and not left as the scoped version.
     */
    public function testPreScopeBindingIsRestoredAfterScopeExit(): void
    {
        // Bind FooService to a closure that returns a tagged instance so we can
        // distinguish it from the scoped override.
        $original = new FooService();
        $this->container->bind(FooService::class, fn() => $original);

        // Verify original binding is active before scope
        self::assertSame($original, $this->container->get(FooService::class));

        $insideResult = null;
        $this->container->scoped(
            [FooService::class => fn() => new FooServiceAlternative()],
            function () use (&$insideResult) {
                $insideResult = $this->container->get(FooService::class);
            }
        );

        // Inside the scope we got the alternative
        self::assertInstanceOf(FooServiceAlternative::class, $insideResult);

        // After scope exit, the original FooService binding must be restored
        self::assertTrue(
            $this->container->has(FooService::class),
            'FooService should still be bound after scope exits'
        );
        $afterScope = $this->container->get(FooService::class);
        self::assertInstanceOf(
            FooService::class,
            $afterScope,
            'FooService should resolve to the original binding, not the scoped override'
        );
        self::assertNotInstanceOf(
            FooServiceAlternative::class,
            $afterScope,
            'FooService must not resolve to the scoped FooServiceAlternative after scope exit'
        );
    }

    /**
     * M-3: BarService is NOT bound before the scope. After the scope introduces
     * it and then exits, BarService must no longer be explicitly bound in the
     * container (it should have been removed, not left as a stale scoped binding).
     */
    public function testNewScopeBindingIsRemovedAfterScopeExit(): void
    {
        // Confirm BarService has no explicit binding before the scope
        self::assertFalse(
            isset($this->container->getContainerBindings()[BarService::class]),
            'BarService must not be bound before the scope'
        );

        $this->container->scoped(
            [BarService::class => fn() => new BarService()],
            fn() => $this->container->get(BarService::class)
        );

        // After scope exit, BarService must not remain in the explicit bindings
        self::assertFalse(
            isset($this->container->getContainerBindings()[BarService::class]),
            'BarService must not remain explicitly bound after scope exits'
        );

        // has() should return false for a non-bound interface; BarService is a
        // concrete class so has() returns true via class_exists, but it should
        // NOT be in the explicit $bindings array (verified above).
        // Resolving it via autowire still works because it is instantiable:
        $autowired = $this->container->get(BarService::class);
        self::assertInstanceOf(BarService::class, $autowired);
    }
}
