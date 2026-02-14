<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

/**
 * Interface for containers that can invoke methods with automatic DI.
 */
interface MethodInvokingContainerInterface
{
    /**
     * Call a method with automatic dependency injection.
     *
     * @param callable $callback
     * @param array<int, mixed> $parameters
     *
     * @return mixed
     */
    public function call(callable $callback, array $parameters = []): mixed;

    /**
     * Call a static method with automatic dependency injection.
     *
     * @param string $class
     * @param string $method
     * @param array<int, string> $parameters
     *
     * @return mixed
     */
    public function callStatic(string $class, string $method, array $parameters = []): mixed;
}
