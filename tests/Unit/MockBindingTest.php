<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ServiceSpy;

class MockableService {
    public function doWork(): string { return 'real'; }
}

class MockBindingTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(MockableService::class);
    }

    public function testMockOverridesRealBinding(): void
    {
        $mock = new class extends MockableService {
            public function doWork(): string { return 'mock'; }
        };
        $this->container->mock(MockableService::class, $mock);
        $result = $this->container->get(MockableService::class);
        self::assertSame('mock', $result->doWork());
    }

    public function testSpyCreation(): void
    {
        $spy = $this->container->spy(MockableService::class);
        self::assertInstanceOf(ServiceSpy::class, $spy);
    }
}
