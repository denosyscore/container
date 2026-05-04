<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ServiceSpy;
use Denosys\Container\ServiceSpyProxy;

class SpiedCalculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function greet(string $name): string
    {
        return "Hello, {$name}!";
    }
}

class ServiceSpyProxyTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->bind(SpiedCalculator::class);
    }

    public function testWasCalledReturnsTrueAfterMethodInvocation(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $proxy->add(1, 2);

        self::assertTrue($spy->wasCalled('add'));
    }

    public function testWasCalledReturnsFalseForUncalledMethod(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        self::assertFalse($spy->wasCalled('add'));
    }

    public function testGetCallsContainsMethodNameAndArgs(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $proxy->add(3, 4);

        $calls = $spy->getCalls();
        self::assertCount(1, $calls);
        self::assertSame('add', $calls[0]['method']);
        self::assertSame([3, 4], $calls[0]['arguments']);
    }

    public function testWasCalledWithMatchesExactArgs(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $proxy->add(5, 6);

        self::assertTrue($spy->wasCalledWith('add', [5, 6]));
        self::assertFalse($spy->wasCalledWith('add', [5, 7]));
    }

    public function testProxyForwardsReturnValue(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $result = $proxy->add(10, 20);

        self::assertSame(30, $result);
    }

    public function testProxyRecordsMultipleCalls(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $proxy->add(1, 2);
        $proxy->add(3, 4);
        $proxy->greet('World');

        self::assertSame(3, $spy->getCallCount());
        self::assertSame(2, $spy->getCallCount('add'));
        self::assertSame(1, $spy->getCallCount('greet'));
    }

    public function testProxyForwardsStringMethodReturnValue(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);

        /** @var SpiedCalculator $proxy */
        $proxy = $this->container->get(SpiedCalculator::class);
        $result = $proxy->greet('Alice');

        self::assertSame('Hello, Alice!', $result);
        self::assertTrue($spy->wasCalledWith('greet', ['Alice']));
    }

    public function testGetReturnsProxyAfterSpyCreation(): void
    {
        $this->container->spy(SpiedCalculator::class);
        $proxy = $this->container->get(SpiedCalculator::class);

        self::assertInstanceOf(ServiceSpyProxy::class, $proxy);
    }

    public function testSpyInstanceOfServiceSpy(): void
    {
        $spy = $this->container->spy(SpiedCalculator::class);
        self::assertInstanceOf(ServiceSpy::class, $spy);
    }
}
