<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Events;

class ResolutionDone
{
    public function __construct(
        public ?string $abstract = null,
        public mixed $instance = null
    ){
    }
}
