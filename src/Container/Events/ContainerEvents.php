<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Events;

/**
 * Container event types and event classes.
 */
class ContainerEvents
{
    // Event types
    public const RESOLUTION_BEFORE = 'container.resolution.before';
    public const RESOLUTION_AFTER = 'container.resolution.after';
    public const RESOLUTION_FAILED = 'container.resolution.failed';
    public const BINDING_REGISTERED = 'container.binding.registered';
    public const BINDING_EXTENDED = 'container.binding.extended';
    public const METHOD_INJECTION_BEFORE = 'container.method_injection.before';
    public const METHOD_INJECTION_AFTER = 'container.method_injection.after';
    public const METHOD_INJECTION_FAILED = 'container.method_injection.failed';
    public const CONTAINER_BOOTED = 'container.booted';
    public const CONTAINER_COMPILED = 'container.compiled';
}

/**
 * Base container event.
 */
abstract class ContainerEvent
{
    public readonly float $timestamp;

    public function __construct(
        public readonly string $abstract,
        ?float $timestamp = null
    ) {
        $this->timestamp = $timestamp ?? microtime(true);
    }
}

/**
 * Service resolution event.
 */
class ResolutionEvent extends ContainerEvent
{
    public function __construct(
        string $abstract,
        public readonly mixed $instance = null,
        public readonly float $resolutionTime = 0.0,
        public readonly bool $fromCache = false,
        ?float $timestamp = null
    ) {
        parent::__construct($abstract, $timestamp);
    }
}

/**
 * Binding registration event.
 */
class BindingEvent extends ContainerEvent
{
    /**
     * @param array<string> $tags
     */
    public function __construct(
        string $abstract,
        /**
         * @param array<string> $tags
         */
        public readonly mixed $concrete,
        /**
         * @param array<string> $tags
         */
        public readonly bool $shared = false,
        /**
         * @param array<string> $tags
         */
        public readonly array $tags = [],
        ?float $timestamp = null
    ) {
        parent::__construct($abstract, $timestamp);
    }
}

/**
 * Method injection event.
 *
 * @property-read mixed $callback
 */
class MethodInjectionEvent extends ContainerEvent
{
    /**
     * @param array<int, string> $parameters
     */
    public readonly mixed $callback;

    /**
     * @param array<int, string> $parameters
     */
    public function __construct(
        mixed $callback,
        /**
         * @param array<int, string> $parameters
         */
        public readonly array $parameters = [],
        public readonly mixed $result = null,
        public readonly float $executionTime = 0.0,
        ?float $timestamp = null
    ) {
        $this->callback = $callback;
        parent::__construct('method_injection', $timestamp);
    }
}