<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Decorators;

use CFXP\Core\Container\ContainerInterface;

/**
 * Manages decorator chains for services.
 */
class DecoratorChain
{
    /** @var array<string, mixed> */

    private array $decorators = [];
    /** @var array<string, mixed> */

    private array $middleware = [];

    public function addDecorator(string $abstract, callable $decorator, int $priority = 0): void
    {
        if (!isset($this->decorators[$abstract])) {
            $this->decorators[$abstract] = [];
        }

        $this->decorators[$abstract][] = [
            'decorator' => $decorator,
            'priority' => $priority
        ];

        // Sort by priority (higher priority = executed later)
        usort($this->decorators[$abstract], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    public function addMiddleware(string $abstract, callable $middleware): void
    {
        if (!isset($this->middleware[$abstract])) {
            $this->middleware[$abstract] = [];
        }
        $this->middleware[$abstract][] = $middleware;
    }

    public function hasDecorators(string $abstract): bool
    {
        return isset($this->decorators[$abstract]) || isset($this->middleware[$abstract]);
    }

    public function decorate(string $abstract, mixed $instance, ContainerInterface $container): mixed
    {
        // Apply decorators
        if (isset($this->decorators[$abstract])) {
            foreach ($this->decorators[$abstract] as $decoratorInfo) {
                $instance = $decoratorInfo['decorator']($instance, $container);
            }
        }

        // Apply middleware
        if (isset($this->middleware[$abstract])) {
            foreach ($this->middleware[$abstract] as $middleware) {
                $instance = $middleware($instance, $container);
            }
        }

        return $instance;
    }

    /**

     * @return array<string, mixed>

     */

public function getDecorators(string $abstract): array

    {
        return $this->decorators[$abstract] ?? [];
    }

    /**
     * @return array<class-string>
     */
    public function getMiddleware(string $abstract): array
    {
        return $this->middleware[$abstract] ?? [];
    }

    public function clearDecorators(string $abstract): void
    {
        unset($this->decorators[$abstract]);
        unset($this->middleware[$abstract]);
    }
}