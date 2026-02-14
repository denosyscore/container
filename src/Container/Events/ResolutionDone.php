<?php

declare(strict_types=1);

namespace Denosys\Container\Events;

class ResolutionDone
{
    public function __construct(
        public ?string $abstract = null,
        public mixed $instance = null
    ){
    }
}
