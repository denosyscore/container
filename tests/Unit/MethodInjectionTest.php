<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class InjectableService {
    public string $value = 'injected';
}

class MethodInjectionTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(InjectableService::class);
    }

    public function testCallWithClosure(): void
    {
        $result = $this->container->call(function (InjectableService $s) {
            return $s->value;
        });
        self::assertSame('injected', $result);
    }

    public function testCallWithExplicitParameter(): void
    {
        $svc = new InjectableService();
        $svc->value = 'explicit';
        $result = $this->container->call(function (InjectableService $s) {
            return $s->value;
        }, [InjectableService::class => $svc]);
        self::assertSame('explicit', $result);
    }

    public function testCallWithInstanceMethod(): void
    {
        $obj = new class {
            public function handle(InjectableService $s): string {
                return 'handled:' . $s->value;
            }
        };
        $result = $this->container->call([$obj, 'handle']);
        self::assertSame('handled:injected', $result);
    }
}
