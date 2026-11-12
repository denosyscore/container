<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use Attribute;
use Denosys\Container\Attributes\Config;
use Denosys\Container\Attributes\Inject;
use Denosys\Container\Container;
use Denosys\Container\Exceptions\ContainerResolutionException;
use Denosys\Container\ParameterResolver;
use Denosys\Contracts\Config\ConfigReader;
use PHPUnit\Framework\TestCase;
use ReflectionParameter;

final class ArrayConfig implements ConfigReader
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
    }
}

final readonly class SignsTokens
{
    public function __construct(
        #[Config('app.key')] public string $key,
        #[Config('app.ttl')] public int $ttl = 3600,
    ) {}
}

interface Store {}
final class FileStore implements Store {}
final class RedisStore implements Store {}

final readonly class UsesRedis
{
    public function __construct(#[Inject('store.redis')] public Store $store) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Answer implements ParameterResolver
{
    public function resolve(ReflectionParameter $parameter, Container $container): mixed
    {
        return 42;
    }
}

final readonly class WantsAnswer
{
    public function __construct(#[Answer] public int $answer) {}
}

/** Constructors ask for what they need at the point of use. */
final class ParameterAttributeTest extends TestCase
{
    public function testConfigAttributeInjectsAConfiguredValue(): void
    {
        $container = new Container();
        $container->instance(ConfigReader::class, new ArrayConfig(['app.key' => 'secret']));

        $service = $container->get(SignsTokens::class);

        self::assertSame('secret', $service->key);
        self::assertSame(3600, $service->ttl);
    }

    public function testConfigAttributeFailsClearlyWhenTheValueIsMissing(): void
    {
        $container = new Container();
        $container->instance(ConfigReader::class, new ArrayConfig([]));

        $this->expectException(ContainerResolutionException::class);
        $this->expectExceptionMessage('app.key');
        $container->get(SignsTokens::class);
    }

    public function testInjectAttributeChoosesABindingById(): void
    {
        $container = new Container();
        $container->bind(Store::class, FileStore::class);
        $container->singleton('store.redis', RedisStore::class);

        self::assertInstanceOf(RedisStore::class, $container->get(UsesRedis::class)->store);
    }

    public function testApplicationsCanDefineTheirOwnParameterAttributes(): void
    {
        self::assertSame(42, (new Container())->get(WantsAnswer::class)->answer);
    }
}
