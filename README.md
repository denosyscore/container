# denosyscore/container

> **Read-only split.** This package is developed in [denosyscore/framework](https://github.com/denosyscore/framework) under `packages/container`. Open issues and pull requests there.

A small PSR-11 container: explicit bindings, shared instances, aliases and
constructor autowiring. Constructor parameters can ask for values the
container cannot infer from a type, using attributes such as
`#[Config('app.key')]`.

## Installation

```bash
composer require denosyscore/container
```

## Quick Start

<!-- quickstart -->
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Denosys\Container\Container;

interface Clock
{
    public function now(): DateTimeImmutable;
}

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}

final class Greeter
{
    // Constructor dependencies are resolved automatically.
    public function __construct(private Clock $clock) {}

    public function greet(string $name): string
    {
        return ($this->clock->now()->format('H') < 12 ? 'Good morning' : 'Hello') . ", {$name}";
    }
}

$container = new Container();
$container->singleton(Clock::class, SystemClock::class);

echo $container->get(Greeter::class)->greet('Ada'), "\n";
var_dump($container->get(Clock::class) === $container->get(Clock::class));  // bool(true)
```

## Bindings

```php
$container->bind(Clock::class, SystemClock::class);        // a new instance each time
$container->singleton(Mailer::class, SmtpMailer::class);   // built once, then reused
$container->singleton(Report::class, fn (Container $c) => new Report($c->get(Clock::class)));
$container->instance(Settings::class, $settings);          // an existing object
$container->alias('mailer', Mailer::class);
$container->extend(Mailer::class, fn (Mailer $mailer, Container $c) => new LoggingMailer($mailer));
```

A class with no binding is autowired: constructor parameters with class or
interface types are resolved from the container; nullable parameters receive
`null` and other parameters their default when nothing can supply them.

## Parameter attributes

```php
use Denosys\Container\Attributes\Config;
use Denosys\Container\Attributes\Inject;

final readonly class SignsLinks
{
    public function __construct(
        #[Config('app.key')] private string $key,         // read through the bound ConfigReader
        #[Config('links.ttl')] private int $ttl = 3600,   // default when the setting is absent
        #[Inject('cache.redis')] private Cache $cache,    // a specific binding, not the type's default
    ) {}
}
```

Any attribute that implements `Denosys\Container\ParameterResolver` works the
same way, so applications can add their own.

## Errors

`get()` throws `NotFoundException` (PSR-11 `NotFoundExceptionInterface`) for
an identifier it cannot provide, so `has()` and `get()` agree. A failure while
building an entry it can provide throws `ContainerResolutionException`, which
names the entry, lists the resolution stack and keeps the cause. Circular
dependencies are reported the same way.

## License

MIT
