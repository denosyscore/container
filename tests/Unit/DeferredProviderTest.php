<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class DeferredService {}

class DeferredProviderTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testDeferredResolverIsCalledWhenBindingMissing(): void
    {
        $called = false;
        $this->container->setDeferredResolver(function (string $abstract) use (&$called) {
            $called = true;
            // Simulate a service provider registering the binding
        });

        try {
            $this->container->get(DeferredService::class);
        } catch (\Throwable) {
            // May fail if deferred resolver doesn't actually bind — that's fine
        }

        self::assertTrue($called);
    }

    public function testDeferredResolverNotCalledWhenAlreadyBound(): void
    {
        $called = false;
        $this->container->bind(DeferredService::class);
        $this->container->setDeferredResolver(function () use (&$called) {
            $called = true;
        });

        $this->container->get(DeferredService::class);
        self::assertFalse($called);
    }

    public function testDeferredResolverCanRegisterBinding(): void
    {
        $this->container->setDeferredResolver(function (string $abstract) {
            if ($abstract === DeferredService::class) {
                $this->container->bind(DeferredService::class);
            }
        });

        $result = $this->container->get(DeferredService::class);
        self::assertInstanceOf(DeferredService::class, $result);
    }
}
