# denosyscore/container

A PSR-11 compliant dependency injection container for PHP 8.2+. Supports autowiring, contextual bindings, tagged services, scoped contexts, lazy proxies, method injection, decorators, deferred providers, mocking, validation, compilation, and performance profiling.

## Requirements

- PHP 8.2+
- `psr/container ^2.0`

## Installation

```bash
composer require denosyscore/container
```

## Quick Start

```php
use Denosys\Container\Container;

$container = new Container();

$container->singleton(DatabaseConnection::class, fn() => new DatabaseConnection('mysql://localhost/mydb'));

$db = $container->get(DatabaseConnection::class);

var_dump($container->has(DatabaseConnection::class)); // true
```

## PSR-11 Compliance

`Container` implements `Psr\Container\ContainerInterface`. `get(string $id)` throws `Psr\Container\NotFoundExceptionInterface` (`NotFoundException`) when a binding cannot be resolved, and `Psr\Container\ContainerExceptionInterface` (`ContainerException`) for all other container errors. Any code that depends only on PSR-11 will work with this container without modification.

## Core Bindings

### `bind()`

Register a transient binding. A new instance is created on every resolution.

```php
$container->bind(LoggerInterface::class, FileLogger::class);

// Factory closure
$container->bind(LoggerInterface::class, function ($container) {
    return new FileLogger('/var/log/app.log');
});

$logger = $container->get(LoggerInterface::class);
```

### `singleton()`

Register a shared binding. The same instance is returned on every resolution.

```php
$container->singleton(DatabaseConnection::class, function ($container) {
    return new DatabaseConnection('mysql://localhost/mydb');
});

$a = $container->get(DatabaseConnection::class);
$b = $container->get(DatabaseConnection::class);

assert($a === $b); // true
```

### `instance()`

Register a pre-built object. Validates that the instance implements the given interface if one is provided.

```php
$connection = new DatabaseConnection('mysql://localhost/mydb');
$container->instance(DatabaseConnection::class, $connection);
```

### `alias()`

Create a short name for an existing binding. The target must already be bound before calling `alias()`.

```php
$container->singleton(EventDispatcher::class, MyDispatcher::class);
$container->alias('events', EventDispatcher::class);

$dispatcher = $container->get('events');
```

### `extend()`

Wrap or decorate an existing binding after it has been registered. Works on both instances and bindings.

```php
$container->singleton(CacheInterface::class, ArrayCache::class);

$container->extend(CacheInterface::class, function ($cache, $container) {
    return new LoggingCache($cache, $container->get(LoggerInterface::class));
});
```

### `removeBinding()`

Remove a binding and any cached instance from the container.

```php
$container->removeBinding(LoggerInterface::class);

var_dump($container->has(LoggerInterface::class)); // false (unless the class is auto-resolvable)
```

## Autowiring

The container resolves constructor dependencies automatically via reflection when no explicit binding is registered.

```php
class UserRepository
{
    public function __construct(private DatabaseConnection $db) {}
}

class UserService
{
    public function __construct(private UserRepository $repo) {}
}

// No bindings needed — full dependency tree is resolved automatically
$service = $container->get(UserService::class);
```

Union types are supported: the container tries each member in order and uses the first resolvable one. Nullable and defaulted parameters fall back gracefully when a dependency cannot be resolved.

## Contextual Bindings

Inject different implementations of the same interface depending on which class is being built.

```php
$container->when(OrderController::class)
    ->needs(PaymentGatewayInterface::class)
    ->give(StripeGateway::class);

$container->when(CheckoutController::class)
    ->needs(PaymentGatewayInterface::class)
    ->give(PayPalGateway::class);

// Factory closure
$container->when(ReportService::class)
    ->needs(StorageInterface::class)
    ->give(function ($container) {
        return new S3Storage($container->get(S3Client::class));
    });

// Inject all services tagged with a specific tag
$container->when(NotificationService::class)
    ->needs(ChannelInterface::class)
    ->giveTagged('notification.channels');
```

## Tagged Services

Group multiple bindings under a tag and resolve them all at once.

```php
$container->bind(EmailNotifier::class, EmailNotifier::class);
$container->bind(SmsNotifier::class, SmsNotifier::class);

$container->tag([EmailNotifier::class, SmsNotifier::class], 'notifiers');

foreach ($container->tagged('notifiers') as $notifier) {
    $notifier->send($message);
}
```

`tag()` accepts a single abstract or an array of abstracts, and a single tag name or an array of tag names.

## Scoped Bindings

Override bindings for the duration of a callback. The original state is restored automatically, even when an exception is thrown.

```php
$result = $container->scoped(
    [LoggerInterface::class => NullLogger::class],
    function ($container) {
        // LoggerInterface resolves to NullLogger inside this callback
        return $container->get(SomeService::class)->run();
    }
);

// Outside, LoggerInterface resolves to the original binding
```

Binding values in the array can be class name strings, closures, or pre-built object instances.

## Lazy Proxies

Defer resolution of a service until the proxy is first accessed. The real service is not instantiated until a method is called on the proxy.

```php
$proxy = $container->lazy(ExpensiveAnalyticsService::class);

// The real service has not been built yet
$proxy->track($event); // instantiated here on first call
```

`lazy()` returns a `LazyProxy` backed by `LazyResolutionProxy`. Useful for optional, heavy dependencies that may never actually be used in a given request.

## Method Injection

### `call()`

Resolve a callable and inject its typed parameters from the container.

```php
$result = $container->call(function (UserRepository $repo, LoggerInterface $logger) {
    $users = $repo->findAll();
    $logger->info('Fetched ' . count($users) . ' users');
    return $users;
});

// Pass extra parameters to fill primitives or override specific arguments
$result = $container->call([SomeClass::class, 'handle'], ['id' => 42]);
```

Any callable form works: closures, `[object, 'method']`, `['ClassName', 'method']`, and named functions.

### `callStatic()`

Invoke a static method with injected dependencies.

```php
$result = $container->callStatic(ReportGenerator::class, 'generate', ['format' => 'pdf']);
```

## Decorators and Middleware

Both `decorate()` and `middleware()` transform a service instance after resolution, before it is returned to the caller.

```php
// Decorators run in descending priority order (higher number = applied first)
$container->decorate(LoggerInterface::class, function ($logger, $container) {
    return new TimingLogger($logger);
}, priority: 10);

$container->decorate(LoggerInterface::class, function ($logger, $container) {
    return new ContextLogger($logger, ['app' => 'biyalink']);
}, priority: 5);

// Middleware runs in registration order, after all decorators
$container->middleware(CacheInterface::class, function ($cache, $container) {
    return new MetricsCacheWrapper($cache);
});
```

Resolution order: `TimingLogger` wraps `ContextLogger` wraps the raw logger; then the middleware layer runs last.

## Deferred Providers

Register a callback that loads a service provider lazily — only when a specific abstract is first requested.

```php
$container->setDeferredResolver(function (string $abstract) use ($providerMap, $container) {
    if (isset($providerMap[$abstract])) {
        $provider = new $providerMap[$abstract]();
        $provider->register($container);
    }
});

// PaymentServiceProvider is not loaded until this line executes
$gateway = $container->get(PaymentGatewayInterface::class);
```

The resolver is called once per unbound abstract, before the normal binding lookup. Once the provider registers its binding, subsequent resolutions run the normal path.

## Testing Utilities

### `mock()`

Replace a binding with a mock object. The mock takes priority over any registered binding or autowiring.

```php
$mockLogger = $this->createMock(LoggerInterface::class);
$container->mock(LoggerInterface::class, $mockLogger);

// All resolutions of LoggerInterface return $mockLogger
$service = $container->get(SomeService::class);
```

### `spy()`

Wrap a real service to record resolution timing and count.

```php
$spy = $container->spy(UserRepository::class);

$container->get(UserService::class)->findUser(1);

echo $spy->getResolutionCount();       // number of times the service was resolved
echo $spy->getAverageResolutionTime(); // average time in ms
echo $spy->getTotalResolutionTime();   // cumulative time in ms

$spy->reset(); // clear recorded data
print_r($spy->getSummary()); // ['abstract', 'total_calls', 'method_calls', 'resolution_count', ...]
```

### `ContainerAssertions`

Static PHPUnit assertions for container state:

```php
use Denosys\Container\Testing\ContainerAssertions;

ContainerAssertions::assertBound($container, LoggerInterface::class);
ContainerAssertions::assertNotBound($container, SomeUnknownService::class);
ContainerAssertions::assertInstanceOf($container, LoggerInterface::class, FileLogger::class);
ContainerAssertions::assertSameInstance($container, DatabaseConnection::class);   // singleton check
ContainerAssertions::assertDifferentInstance($container, UserRepository::class);  // transient check
ContainerAssertions::assertResolved($container, LoggerInterface::class);
ContainerAssertions::assertResolvedTimes($container, LoggerInterface::class, 3);
ContainerAssertions::assertTagged($container, 'notifiers', [EmailNotifier::class, SmsNotifier::class]);
ContainerAssertions::assertValidContainer($container);
ContainerAssertions::assertPerformanceAcceptable($container, maxAverageTime: 5.0);
```

### `TestContainerBuilder`

Fluent builder for isolated test containers:

```php
use Denosys\Container\Testing\TestContainerBuilder;

$container = (new TestContainerBuilder())
    ->withMocks([
        LoggerInterface::class => $this->createMock(LoggerInterface::class),
        CacheInterface::class  => $this->createMock(CacheInterface::class),
    ])
    ->withSingletons([
        DatabaseConnection::class => new DatabaseConnection(':memory:'),
    ])
    ->withBindings([
        PaymentGatewayInterface::class => FakeGateway::class,
    ])
    ->build();

// Or a bare container with no extra wiring
$container = TestContainerBuilder::createQuick();
```

## Container Validation

Check whether all registered bindings can be resolved before deploying or compiling.

```php
$result = $container->validate();

if (!$result->isValid()) {
    foreach ($result->getIssues() as $issue) {
        // $issue->severity: 'error' | 'warning' | 'info'
        // $issue->message, $issue->abstract, $issue->suggestions
        echo $issue->getDescription();
    }
}

echo $result->getSummary();
// "Validation passed. No issues found."
// "Validation failed. 3 issues found: 2 errors, 1 warning."

$result->hasErrors();
$result->hasWarnings();
$result->getIssuesBySeverity('warning');
$result->getStatistics();
```

Only errors flip `isValid()` to `false`. Warnings are surfaced but the container is still considered valid.

## Container Compilation

Compile the current container configuration to optimized PHP code that eliminates reflection overhead in production.

```php
// Validates bindings, then writes a compiled class to disk
$container->compile('/var/cache/container/CompiledContainer.php');

// Detect whether the configuration has changed since last compilation
$fingerprint = $container->compilationFingerprint(); // deterministic sha256 hash
```

The generated class extends `Container` and pre-builds factory methods for every binding that can be statically analyzed. Closures, decorators, and contextual bindings are compiled where possible and fall back to the parent implementation otherwise.

## Performance Profiling

### Metrics

`getPerformanceMetrics()` returns a `PerformanceReport` aggregating every resolution since the container was instantiated.

```php
$report = $container->getPerformanceMetrics();

echo $report->totalResolutions;
echo $report->averageResolutionTime; // milliseconds
echo $report->cacheHits;
echo $report->cacheMisses;
echo $report->getCacheHitRatio();    // percentage
echo $report->getPerformanceScore(); // 0-100

foreach ($report->slowestResolutions as $abstract => $ms) {
    echo "{$abstract}: {$ms}ms\n";
}

foreach ($report->optimizationSuggestions as $hint) {
    echo $hint . "\n";
}

echo $report->getSummary();
// "Performance Score: 95/100\nTotal Resolutions: 142\nAverage Resolution Time: 0.08ms\n..."

$report->isPerformanceAcceptable(); // true if score >= 70
$report->getMostResolvedServices(5);
$report->getPerformanceIssues();    // ['High average resolution time (15ms)', ...]
```

### Resolution History

The container maintains a ring-buffer of every resolution event. Default limit is 1000 entries.

```php
foreach ($container->getResolutionHistory() as $entry) {
    // Keys: abstract, resolution_time (ms), from_mock, timestamp, memory_usage
    echo "{$entry['abstract']}: {$entry['resolution_time']}ms\n";
}

// Reduce memory overhead in high-throughput scenarios
$container->setResolutionHistoryLimit(200);
```

## Events

The container dispatches four events via `denosyscore/events`. All classes live in the `Denosys\Container\Events\` namespace.

`BindingRegistered` — fired after every call to `bind()` or `singleton()`.
Properties: `string $abstract`, `mixed $concrete`, `bool $shared`, `array $tags`, `?float $timestamp`.

`ResolutionStarting` — fired before a service is resolved.
Properties: `string $abstract`.

`ResolutionDone` — fired after a successful resolution.
Properties: `string $abstract`, `mixed $instance`.

`ResolutionFailed` — fired when resolution throws.
Properties: `string $abstract`, `Throwable $exception`.

## Exception Hierarchy

| Class | Extends | When thrown |
|---|---|---|
| `ContainerException` | `Psr\Container\ContainerExceptionInterface` | General container errors (circular dependency, non-instantiable class, unresolvable builtin) |
| `NotFoundException` | `ContainerException`, `Psr\Container\NotFoundExceptionInterface` | Abstract not bound and class does not exist |
| `ContainerResolutionException` | `ContainerException` | Wraps any throwable that escapes the resolution pipeline; carries `$abstract`, `$resolutionChain`, and `$suggestions` |
| `BindingConflictException` | `ContainerException` | Reserved for binding conflict detection |
| `CircularDependencyException` | `ContainerException` | Raised when a circular dependency is detected |

In practice, `get()` wraps all resolution failures into a `ContainerResolutionException`, so catch sites need only handle one type.

## License

MIT
