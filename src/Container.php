<?php

declare(strict_types=1);

namespace CFXP\Container;

class Container extends \CFXP\Core\Container\Container implements
    ContainerInterface,
    MethodInvokingContainerInterface,
    TaggingContainerInterface,
    IntrospectableContainerInterface,
    TestableContainerInterface
{
}
