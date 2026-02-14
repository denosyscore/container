<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Events;

class ResolutionStarting
{
    public function __construct(public ?string $abstract = null)
    {
    }

}
