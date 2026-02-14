<?php

declare(strict_types=1);

namespace Denosys\Container\Binding;

/**
 * Placeholder for conditional binding resolver.
 * This would evaluate runtime conditions for binding selection.
 */
class ConditionalBindingResolver
{
    /** @var array<string, array<array{condition: callable, implementation: mixed}>> */
    private array $conditionalBindings = [];

    public function addConditionalBinding(string $abstract, callable $condition, mixed $implementation): void
    {
        $this->conditionalBindings[$abstract][] = [
            'condition' => $condition,
            'implementation' => $implementation
        ];
    }

    /**
     * @param \Denosys\Container\ContainerInterface $container
     */
    public function resolve(string $abstract, $container): mixed
    {
        if (!isset($this->conditionalBindings[$abstract])) {
            throw new \RuntimeException("No conditional bindings found for '{$abstract}'");
        }

        foreach ($this->conditionalBindings[$abstract] as $binding) {
            if (($binding['condition'])($container)) {
                return $binding['implementation'];
            }
        }

        throw new \RuntimeException("No conditional binding matched for '{$abstract}'");
    }
}