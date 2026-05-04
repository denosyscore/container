<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\NotFoundException;

// Fixtures for alias validation tests
interface AliasTestUnboundInterface {}

class AliasTestConcreteClass
{
    public function __construct() {}
}

class AliasValidationTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testAliasToABoundAbstractSucceeds(): void
    {
        $this->container->bind(AliasTestUnboundInterface::class, AliasTestConcreteClass::class);
        $this->container->alias('my-alias', AliasTestUnboundInterface::class);

        $result = $this->container->get('my-alias');
        self::assertInstanceOf(AliasTestConcreteClass::class, $result);
    }

    public function testAliasToUnboundButInstantiableConcreteSucceeds(): void
    {
        // AliasTestConcreteClass is not bound, but it is instantiable
        $this->container->alias('concrete-alias', AliasTestConcreteClass::class);

        $result = $this->container->get('concrete-alias');
        self::assertInstanceOf(AliasTestConcreteClass::class, $result);
    }

    public function testAliasToUnboundInterfaceThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Cannot alias 'iface-alias' to '" . AliasTestUnboundInterface::class . "': target is not bound and not an instantiable class.");

        $this->container->alias('iface-alias', AliasTestUnboundInterface::class);
    }

    public function testAliasToNonExistentClassThrowsNotFoundException(): void
    {
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage("Cannot alias 'bad-alias' to 'NonExistent\\Class\\That\\DoesNotExist': target is not bound and not an instantiable class.");

        $this->container->alias('bad-alias', 'NonExistent\\Class\\That\\DoesNotExist');
    }
}
