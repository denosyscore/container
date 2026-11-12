<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use Denosys\Container\Container;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

final class PsrNotFoundTest extends TestCase
{
    /** PSR-11: get() throws NotFoundExceptionInterface when has() is false. */
    public function testUnknownIdentifiersThrowNotFound(): void
    {
        $container = new Container();
        self::assertFalse($container->has('no.such.service'));

        try {
            $container->get('no.such.service');
            self::fail('An unknown identifier resolved.');
        } catch (NotFoundExceptionInterface $exception) {
            self::assertStringContainsString('no.such.service', $exception->getMessage());
            self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
        }
    }
}
