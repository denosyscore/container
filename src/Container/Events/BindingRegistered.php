<?php

declare(strict_types=1);

namespace Denosys\Container\Events;

readonly class BindingRegistered
{
    /**
     * @param array<string> $tags
     */
    public function __construct(
        /**
         * @param array<string> $tags
         */
        public string $abstract,
        /**
         * @param array<string> $tags
         */
        public mixed $concrete,
        /**
         * @param array<string> $tags
         */
        public bool $shared = false,
        /**
         * @param array<string> $tags
         */
        public array $tags = [],
        public ?float $timestamp = null
    ) {
    }
}
