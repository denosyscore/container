<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class DecorableService {
    public string $label = 'original';
}

class DecoratorTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(DecorableService::class);
    }

    public function testDecoratorWrapsInstance(): void
    {
        $this->container->decorate(DecorableService::class, function (DecorableService $s, Container $c) {
            $s->label = 'decorated';
            return $s;
        });
        $result = $this->container->get(DecorableService::class);
        self::assertSame('decorated', $result->label);
    }

    public function testDecoratorPriorityOrder(): void
    {
        $log = [];
        $this->container->decorate(DecorableService::class, function (DecorableService $s) use (&$log) {
            $log[] = 'low';
            return $s;
        }, priority: 1);
        $this->container->decorate(DecorableService::class, function (DecorableService $s) use (&$log) {
            $log[] = 'high';
            return $s;
        }, priority: 10);

        $this->container->get(DecorableService::class);
        // Higher priority decorators run first
        self::assertSame(['high', 'low'], $log);
    }

    public function testNoDecoratorsIsTransparent(): void
    {
        $result = $this->container->get(DecorableService::class);
        self::assertSame('original', $result->label);
    }
}
