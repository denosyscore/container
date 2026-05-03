# denosyscore/container

A PSR-11 compliant dependency injection container for PHP 8.2+. Supports autowiring, contextual bindings, tagged services, scoped contexts, lazy proxies, method injection, decorators, mocking, validation, and performance profiling.

## Requirements

- PHP 8.2+
- `psr/container ^2.0`

## Installation

```bash
composer require denosyscore/container
```

## Basic Usage

### Binding

Register an abstract (interface or class name) to a concrete implementation.

```php
use Denosys\Container\Container;

$container = new Container();

// Bind an interface to a concrete class
$container->bind(LoggerInterface::class, FileLogger::class);

// Bind with a factory closure
$container->bind(LoggerInterface::class, function ($container) {
    return new FileLogger('/var/log/app.log');
});

$logger = $container->get(LoggerInterface::class);
```

### Singletons

Use `singleton()` to share a single instance across all resolutions.

```php
$container->singleton(DatabaseConnection::class, function ($container) {
    return new DatabaseConnection('mysql://localhost/mydb');
});

$a = $container->get(DatabaseConnection::class);
$b = $container->get(DatabaseConnection::class);

assert($a === $b); // true — same instance
```

### Pre-built Instances

Register an already-constructed object with `instance()`.

```php
$connection = new DatabaseConnection('mysql://localhost/mydb');
$container->instance(DatabaseConnection::class, $connection);
```

### Aliases

Create a short name for an existing binding. The target must already be bound.

```php
$container->singleton(EventDispatcher::class, MyDispatcher::class);
$container->alias('events', EventDispatcher::class);

$dispatcher = $container->get('events');
```

### Extending Bindings

Wrap or modify an existing binding after it has been registered.

```php
$container->singleton(CacheInterface::class, ArrayCache::class);

$container->extend(CacheInterface::class, function ($cache, $container) {
    return new LoggingCache($cache, $container->get(LoggerInterface::class));
});
```

## Autowiring

The container resolves constructor dependencies automatically using reflection when no explicit binding is registered.

```php
class UserRepository
{
    public function __construct(private DatabaseConnection $db) {}
}

class UserService
{
    public function __construct(private UserRepository $repo) {}
}

// No bindings needed — the full dependency tree is wired automatically
$service = $container->get(UserService::class);
```

Union types are supported: the container tries each type in order and uses the first resolvable one. Nullable and defaulted parameters fall back gracefully when a dependency cannot be resolved.

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

`tag()` accepts a single abstract or an array, and a single tag name or an array of tag names.

## Scoped Bindings

Override bindings for the duration of a callback. The original state is restored automatically, even when an exception is thrown.

```php
$result = $container->scoped(
    [LoggerInterface::class => NullLogger::class],
    function ($container) {
        // Inside here, LoggerInterface resolves to NullLogger
        return $container->get(SomeService::class)->run();
    }
);

// Outside, LoggerInterface still resolves to the original binding
```

Bindings in the array can be class name strings, closures, or pre-built object instances.

## Lazy Proxies

Defer resolution of a service until the proxy is first accessed.

```php
$proxy = $container->lazy(ExpensiveAnalyticsService::class);

// The real service has not been built yet.
// It is instantiated the first time a method is called on the proxy.
$proxy->track($event);
```

`lazy()` returns a `LazyProxy` instance backed by `LazyResolutionProxy`.

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

Both `decorate()` and `middleware()` transform a service instance after it has been resolved, before it is returned to the caller.

```php
// Decorators run in descending priority order
$container->decorate(LoggerInterface::class, function ($logger, $container) {
    return new TimingLogger($logger);
}, priority: 10);

$container->decorate(LoggerInterface::class, function ($logger, $container) {
    return new ContextLogger($logger, ['app' => 'biyalink']);
}, priority: 5);

// Middleware runs in registration order after all decorators
$container->middleware(CacheInterface::class, function ($cache, $container) {
    return new MetricsCacheWrapper($cache);
});
```

Higher-priority decorators are applied first. Middleware is appended after the decorator chain.

## Testing Utilities

### Mocks

Replace a binding with a mock object. The mock takes priority over any registered binding or autowiring.

```php
$mockLogger = $this->createMock(LoggerInterface::class);
$container->mock(LoggerInterface::class, $mockLogger);

// Any resolution of LoggerInterface now returns $mockLogger
$service = $container->get(SomeService::class);
```

### Spies

Wrap a real service to record resolution timing and count.

```php
$spy = $container->spy(UserRepository::class);

$container->get(UserService::class)->findUser(1);

echo $spy->getResolutionCount();       // number of times the service was resolved
echo $spy->getAverageResolutionTime(); // average time per resolution in ms
print_r($spy->getSummary());           // full statistics array
```

## Container Validation

Check whether all registered bindings can be resolved.

```php
$result = $container->validate();

if (!$result->isValid()) {
    foreach ($result->getIssues() as $issue) {
        echo $issue->getDescription(); // severity, message, abstract, suggestions
    }
}

echo $result->getSummary();
// "Validation passed. No issues found."
// or "Validation failed. 3 issues found: 2 errors, 1 warning."
```

Issues carry a severity of `error`, `warning`, or `info`, and may include suggested fixes.

## Performance Profiling

### Metrics

`getPerformanceMetrics()` returns a `PerformanceReport` with aggregated data from every resolution since the container was created.

```php
$report = $container->getPerformanceMetrics();

echo $report->totalResolutions;
echo $report->averageResolutionTime; // milliseconds
echo $report->getPerformanceScore(); // 0-100

foreach ($report->slowestResolutions as $abstract => $ms) {
    echo "{$abstract}: {$ms}ms\n";
}

foreach ($report->optimizationSuggestions as $hint) {
    echo $hint . "\n";
}

echo $report->getSummary();
```

### Resolution History

The container maintains a ring-buffer of every resolution event (default limit: 1000 entries).

```php
foreach ($container->getResolutionHistory() as $entry) {
    // Keys: abstract, resolution_time (ms), from_mock, timestamp, memory_usage
    echo "{$entry['abstract']}: {$entry['resolution_time']}ms\n";
}

// Shrink the buffer to reduce memory overhead
$container->setResolutionHistoryLimit(200);
```

## Events

The container dispatches four events via `denosyscore/events` at key lifecycle points. All classes live in the `Denosys\Container\Events\` namespace.

`BindingRegistered` — fired after every call to `bind()` or `singleton()`. Carries `$abstract` (string), `$concrete` (mixed), and `$shared` (bool).

`ResolutionStarting` — fired before any service is resolved. Carries `$abstract` (string).

`ResolutionDone` — fired after a successful resolution. Carries `$abstract` (string) and `$instance` (the resolved object).

`ResolutionFailed` — fired when resolution throws. Carries `$abstract` (string) and `$exception` (Throwable).

## License

MIT
