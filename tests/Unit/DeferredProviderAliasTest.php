<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

interface CacheInterface {}

class ConcreteCache implements CacheInterface {}

class DeferredProviderAliasTest extends TestCase
{
    public function testDeferredProviderFiresOnAliasedGet(): void
    {
        $callCount = 0;
        $container = new Container();

        $container->setDeferredResolver(function (string $abstract) use ($container, &$callCount): void {
            if ($abstract === 'cache') {
                $callCount++;
                $container->bind(CacheInterface::class, ConcreteCache::class);
                $container->alias('cache', CacheInterface::class);
            }
        });

        $result = $container->get('cache');

        self::assertSame(1, $callCount, 'Deferred provider must fire exactly once for alias resolution');
        self::assertInstanceOf(ConcreteCache::class, $result);
    }

    public function testDeferredProviderNotFiredAgainOnDirectAbstractGet(): void
    {
        $callCount = 0;
        $container = new Container();

        $container->setDeferredResolver(function (string $abstract) use ($container, &$callCount): void {
            if ($abstract === 'cache') {
                $callCount++;
                $container->bind(CacheInterface::class, ConcreteCache::class);
                $container->alias('cache', CacheInterface::class);
            }
        });

        // First: resolve via alias — fires the provider
        $container->get('cache');

        // Second: resolve directly by abstract — provider must NOT fire again
        $result = $container->get(CacheInterface::class);

        self::assertSame(1, $callCount, 'Provider must not fire a second time when abstract is already bound');
        self::assertInstanceOf(ConcreteCache::class, $result);
    }
}
