<?php

declare(strict_types=1);

namespace Denosys\Container\Attributes;

use Attribute;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerException;
use Denosys\Container\ParameterResolver;
use Denosys\Contracts\Config\ConfigReader;
use ReflectionParameter;

/**
 * Inject a configuration value by dot path, read through the bound
 * ConfigReader. A missing value uses the parameter's default or fails.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Config implements ParameterResolver
{
    public function __construct(public string $path)
    {
    }

    public function resolve(ReflectionParameter $parameter, Container $container): mixed
    {
        $config = $container->get(ConfigReader::class);
        if (!$config instanceof ConfigReader) {
            throw new ContainerException('No ConfigReader is bound, so #[Config] cannot be resolved.');
        }

        $missing = new \stdClass();
        $value = $config->get($this->path, $missing);
        if ($value !== $missing) {
            return $value;
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new ContainerException("Configuration [{$this->path}] is not set for \${$parameter->getName()}.");
    }
}
