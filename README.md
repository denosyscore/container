# denosyscore/container

A PSR-11 compliant dependency injection container for PHP 8.2+. Supports autowiring, contextual bindings, tagged services, lazy proxies, scoped lifetimes, method injection, decorators, and built-in testing and profiling utilities.

![PHP ^8.2](https://img.shields.io/badge/PHP-%5E8.2-blue)
![License MIT](https://img.shields.io/badge/license-MIT-green)

## Requirements

- PHP ^8.2

## Installation

```bash
composer require denosyscore/container
```

## Quick Start

```php
use Denosys\Container\Container;

$container = new Container();

// Bind an interface to a concrete class
$container->bind(LoggerInterface::class, FileLogger::class);

// Bind as a singleton
$container->singleton(CacheInterface::class, RedisCache::class);

// Resolve — dependencies are injected automatically
$logger = $container->get(LoggerInterface::class);
$cache  = $container->get(CacheInterface::class);

assert($cache === $container->get(CacheInterface::class)); // same instance
```

## Autowiring

The container inspects constructor type-hints and resolves dependencies automatically. No explicit registration is needed for concrete classes:

```php
class OrderService
{
    public function __construct(
        private readonly PaymentGateway $payment,
        private readonly InventoryRepository $inventory,
    ) {}
}

// PaymentGateway and InventoryRepository are built and injected automatically
$service = $container->get(OrderService::class);
```

Union-typed parameters are tried in order. Nullable and defaulted parameters fall back gracefully without throwing.

## Explicit Bindings

### bind()

Register an abstract (interface or string key) to a concrete implementation or factory closure:

```php
// Interface to concrete class
$container->bind(LoggerInterface::class, FileLogger::class);

// Factory closure
$container->bind(DatabaseConnection::class, function (Container $c) {
    return new DatabaseConnection(config('db.dsn'));
});
```

### singleton()

Resolved once; the same instance is returned on every subsequent call:

```php
$container->singleton(CacheManager::class, RedisCacheManager::class);

$a = $container->get(CacheManager::class);
$b = $container->get(CacheManager::class);

assert($a === $b); // true
```

### instance()

Register a pre-built object as a shared instance:

```php
$container->instance(Config::class, new Config(['debug' => true]));
```

### alias()

Create a short name for a bound abstract. The abstract must already be bound or be an instantiable class:

```php
$container->bind(PaymentGateway::class, StripeGateway::class);
$container->alias('stripe', PaymentGateway::class);

$gateway = $container->get('stripe');
```

### extend()

Wrap an existing binding or resolved instance with additional behavior:

```php
$container->singleton(MailerInterface::class, SmtpMailer::class);

$container->extend(MailerInterface::class, function (MailerInterface $mailer, Container $c) {
    return new LoggingMailerDecorator($mailer);
});
```

## Contextual Bindings

Inject different implementations of the same interface depending on which class is requesting it:

```php
$container->when(OrderController::class)
    ->needs(LoggerInterface::class)
    ->give(OrderLogger::class);

$container->when(PaymentController::class)
    ->needs(LoggerInterface::class)
    ->give(PaymentLogger::class);
```

Supply all services tagged with a given tag to a context:

```php
$container->when(ReportBuilder::class)
    ->needs(FormatterInterface::class)
    ->giveTagged('report.formatters');
```

## Tagged Services

Group related bindings under a shared tag and resolve all of them at once:

```php
$container->bind(CsvFormatter::class, CsvFormatter::class);
$container->bind(JsonFormatter::class, JsonFormatter::class);

$container->tag(
    [CsvFormatter::class, JsonFormatter::class],
    'report.formatters'
);

$formatters = $container->tagged('report.formatters');
// Returns resolved instances of all tagged services

$abstracts = $container->resolveAll(FormatterInterface::class);
// Keyed by abstract name
```

A single abstract can carry multiple tags:

```php
$container->tag(AuditLogger::class, ['loggers', 'auditors']);
```

## Scoped Bindings

Override bindings within a bounded scope. The original bindings are automatically restored when the callable returns:

```php
$result = $container->scoped([
    LoggerInterface::class => TestLogger::class,
], function () use ($container, $order) {
    // OrderService receives TestLogger inside this scope
    return $container->get(OrderService::class)->process($order);
});
// LoggerInterface is back to its original binding here
```

## Lazy Loading

Defer resolution until the first method call. Useful for expensive services that may not be needed on every request path:

```php
$proxy = $container->lazy(ReportGenerator::class);

// ReportGenerator is NOT instantiated yet

$report = $proxy->generate($data);
// Resolved on first call, then reused
```

The proxy implements `Denosys\Container\LazyProxy` and transparently forwards all method calls to the underlying instance once triggered.

## Method Injection

### call()

Invoke any callable with automatic injection of type-hinted parameters:

```php
$result = $container->call(function (MailerInterface $mailer, string $subject = 'Hello') {
    return $mailer->send($subject);
});

// Object method
$result = $container->call([$controller, 'index']);

// Pass extra parameters — these override or supplement resolved ones
$result = $container->call([OrderService::class, 'process'], ['orderId' => 42]);
```

### callStatic()

Call a static method with automatic injection:

```php
$result = $container->callStatic(ReportGenerator::class, 'buildSummary');
```

## Decorators

### decorate()

Wrap a resolved service. Multiple decorators are applied in descending `$priority` order (higher runs first):

```php
$container->bind(CacheInterface::class, RedisCache::class);

$container->decorate(CacheInterface::class, function (CacheInterface $cache, Container $c) {
    return new MetricsCollectingCache($cache);
}, priority: 10);

$container->decorate(CacheInterface::class, function (CacheInterface $cache, Container $c) {
    return new LoggingCache($cache);
}, priority: 5);

// Chain: RedisCache -> MetricsCollectingCache -> LoggingCache
$cache = $container->get(CacheInterface::class);
```

### middleware()

Middleware behaves like a decorator without priority ordering. Applied in registration order, after all `decorate()` layers:

```php
$container->middleware(RepositoryInterface::class, function (RepositoryInterface $repo, Container $c) {
    return new CachingRepository($repo, $c->get(CacheInterface::class));
});
```

## Testing Utilities

### mock()

Replace a binding with a test double. Mocks take priority over all other bindings and are never decorated:

```php
$mockMailer = $this->createMock(MailerInterface::class);
$mockMailer->expects($this->once())->method('send');

$container->mock(MailerInterface::class, $mockMailer);

$container->get(OrderService::class)->checkout($order);
```

### spy()

Wrap a real service instance to track its resolution count and timing without replacing it:

```php
$spy = $container->spy(PaymentGateway::class);

// ... exercise code that uses PaymentGateway ...

echo $spy->getResolutionCount();        // number of times resolved
echo $spy->getAverageResolutionTime();  // average ms per resolution
echo $spy->getTotalResolutionTime();    // total ms across all resolutions

$summary = $spy->getSummary();
// ['abstract' => ..., 'resolution_count' => ..., 'average_resolution_time' => ...]
```

## Container Validation

Check for misconfigured or unresolvable bindings without instantiating anything:

```php
$result = $container->validate();

if (!$result->isValid()) {
    foreach ($result->getIssues() as $issue) {
        echo $issue->getDescription();
        // "[error] Cannot resolve 'PaymentGateway' (Abstract: ...) Suggestions: ..."
    }
}

$errors   = $result->getIssuesBySeverity('error');
$warnings = $result->getIssuesBySeverity('warning');

echo $result->getSummary();
// "Validation failed. 2 issues found: 1 errors, 1 warnings."
// or: "Validation passed. No issues found."
```

`ValidationIssue` severity levels: `error`, `warning`, `info`.

## Performance

### getPerformanceMetrics()

Returns a `PerformanceReport` with timing, cache ratios, and memory stats accumulated since the container was constructed:

```php
$report = $container->getPerformanceMetrics();

echo $report->getSummary();
// Performance Score: 95/100
// Total Resolutions: 342
// Average Resolution Time: 0.42ms
// Cache Hit Ratio: 87.4%
// Peak Memory Usage: 12.30MB

$score  = $report->getPerformanceScore();      // 0-100
$slow   = $report->slowestResolutions;         // array<string, float> abstract => ms
$top    = $report->getMostResolvedServices(5); // top 5 most-resolved services
$issues = $report->getPerformanceIssues();     // array<string> human-readable warnings
```

### getResolutionHistory()

Returns a ring-buffer of recent resolution records (default limit: 1000 entries):

```php
$history = $container->getResolutionHistory();

foreach ($history as $entry) {
    printf(
        "%s resolved in %.2fms (mock: %s)\n",
        $entry['abstract'],
        $entry['resolution_time'],
        $entry['from_mock'] ? 'yes' : 'no'
    );
}
// Keys per entry: abstract, resolution_time, from_mock, timestamp, memory_usage
```

Lower the ring-buffer size to reduce memory overhead:

```php
$container->setResolutionHistoryLimit(200);
```

## License

MIT
