<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class RealSingletonService
{
    public static int $constructCount = 0;

    public function __construct()
    {
        self::$constructCount++;
    }
}

class DecoratedWrapper
{
    public function __construct(public readonly RealSingletonService $inner)
    {
    }
}

class SingletonDecoratorCacheTest extends TestCase
{
    protected function setUp(): void
    {
        RealSingletonService::$constructCount = 0;
    }

    public function testBothResolutionsReturnDecoratedInstance(): void
    {
        $container = new Container();
        $container->singleton(RealSingletonService::class);
        $container->decorate(RealSingletonService::class, function (RealSingletonService $raw, Container $c) {
            return new DecoratedWrapper($raw);
        });

        $first  = $container->get(RealSingletonService::class);
        $second = $container->get(RealSingletonService::class);

        self::assertInstanceOf(DecoratedWrapper::class, $first,  'First resolution must return the decorated wrapper');
        self::assertInstanceOf(DecoratedWrapper::class, $second, 'Second resolution must return the decorated wrapper');
        self::assertSame($first, $second, 'Singleton must return the same decorated instance on every call');
    }

    public function testRealServiceConstructorRunsExactlyOnce(): void
    {
        $container = new Container();
        $container->singleton(RealSingletonService::class);
        $container->decorate(RealSingletonService::class, function (RealSingletonService $raw, Container $c) {
            return new DecoratedWrapper($raw);
        });

        $container->get(RealSingletonService::class);
        $container->get(RealSingletonService::class);
        $container->get(RealSingletonService::class);

        self::assertSame(1, RealSingletonService::$constructCount, 'Real service constructor must run exactly once for a singleton');
    }
}
