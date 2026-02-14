<?php

declare(strict_types=1);

namespace Denosys\Container\Events;

class ResolutionStarting
{
    public function __construct(public ?string $abstract = null)
    {
    }

}
