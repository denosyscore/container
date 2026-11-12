<?php

declare(strict_types=1);

namespace Denosys\Container;

use ReflectionParameter;

/**
 * A constructor parameter attribute that supplies the parameter's value.
 *
 * Implement this on an attribute class to let constructors ask for values
 * the container cannot infer from a type, such as a configuration setting.
 */
interface ParameterResolver
{
    public function resolve(ReflectionParameter $parameter, Container $container): mixed;
}
