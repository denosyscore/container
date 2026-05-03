<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerException;
use Denosys\Container\Exceptions\ContainerResolutionException;

class NoDepsClass {}
class OneDep { public function __construct(public NoDepsClass $dep) {} }
class TwoDeps { public function __construct(public NoDepsClass $a, public OneDep $b) {} }
class WithBuiltinDefault { public function __construct(public string $name = 'hello') {} }
interface UnresolvableInterface {}
class WithNullable { public function __construct(public ?UnresolvableInterface $dep = null) {} }
class WithUnionType { public function __construct(public NoDepsClass|OneDep $dep) {} }

interface SomeInterface2 {}
class SomeImpl2 implements SomeInterface2 {}
class NeedsInterface { public function __construct(public SomeInterface2 $dep) {} }

class ReflectionAutowiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testNoDependencies(): void
    {
        self::assertInstanceOf(NoDepsClass::class, $this->container->get(NoDepsClass::class));
    }

    public function testOneDependency(): void
    {
        $result = $this->container->get(OneDep::class);
        self::assertInstanceOf(OneDep::class, $result);
        self::assertInstanceOf(NoDepsClass::class, $result->dep);
    }

    public function testDeepDependencyChain(): void
    {
        $result = $this->container->get(TwoDeps::class);
        self::assertInstanceOf(TwoDeps::class, $result);
        self::assertInstanceOf(NoDepsClass::class, $result->a);
        self::assertInstanceOf(OneDep::class, $result->b);
    }

    public function testBuiltinWithDefault(): void
    {
        $result = $this->container->get(WithBuiltinDefault::class);
        self::assertSame('hello', $result->name);
    }

    public function testNullableParameterResolvesToNull(): void
    {
        // UnresolvableInterface has no binding and cannot be autowired; nullable falls back to null
        $result = $this->container->get(WithNullable::class);
        self::assertNull($result->dep);
    }

    public function testUnionTypeResolvesFirstResolvable(): void
    {
        $result = $this->container->get(WithUnionType::class);
        // NoDepsClass is first and resolvable
        self::assertInstanceOf(NoDepsClass::class, $result->dep);
    }

    public function testUnboundInterfaceThrows(): void
    {
        $this->expectException(ContainerResolutionException::class);
        $this->container->get(NeedsInterface::class);
    }

    public function testBoundInterfaceResolves(): void
    {
        $this->container->bind(SomeInterface2::class, SomeImpl2::class);
        $result = $this->container->get(NeedsInterface::class);
        self::assertInstanceOf(SomeImpl2::class, $result->dep);
    }
}
