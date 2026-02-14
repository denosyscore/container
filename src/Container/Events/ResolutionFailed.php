<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Events;

use Throwable;

class ResolutionFailed
{
    public function __construct(
        public ?string $abstract = null,
        public ?Throwable $exception = null
    ) {
    }
}
