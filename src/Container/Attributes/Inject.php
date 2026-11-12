<?php

declare(strict_types=1);

namespace Denosys\Container\Attributes;

use Attribute;
use Denosys\Container\Container;
use Denosys\Container\ParameterResolver;
use ReflectionParameter;

/**
 * Inject the entry bound under a specific identifier instead of the one the
 * parameter's type would select.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Inject implements ParameterResolver
{
    public function __construct(public string $id)
    {
    }

    public function resolve(ReflectionParameter $parameter, Container $container): mixed
    {
        return $container->get($this->id);
    }
}
