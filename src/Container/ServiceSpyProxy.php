<?php

declare(strict_types=1);

namespace Denosys\Container;

class ServiceSpyProxy
{
    public function __construct(
        private readonly object $target,
        private readonly ServiceSpy $spy
    ) {}

    public function __call(string $method, array $args): mixed
    {
        $this->spy->recordCall($method, $args);
        return $this->target->$method(...$args);
    }
}
