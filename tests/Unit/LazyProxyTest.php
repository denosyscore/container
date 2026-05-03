<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\LazyProxy;

class LazyTarget {
    public bool $constructed = false;
    public function __construct() { $this->constructed = true; }
    public function getValue(): string { return 'lazy-value'; }
}

class LazyProxyTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(LazyTarget::class);
    }

    public function testLazyReturnsProxy(): void
    {
        $proxy = $this->container->lazy(LazyTarget::class);
        self::assertInstanceOf(LazyProxy::class, $proxy);
    }

    public function testProxyIsNotResolvedOnCreation(): void
    {
        $proxy = $this->container->lazy(LazyTarget::class);
        self::assertFalse($proxy->isResolved());
    }

    public function testProxyResolvesOnMethodCall(): void
    {
        $proxy = $this->container->lazy(LazyTarget::class);
        $result = $proxy->getValue();
        self::assertSame('lazy-value', $result);
        self::assertTrue($proxy->isResolved());
    }
}
