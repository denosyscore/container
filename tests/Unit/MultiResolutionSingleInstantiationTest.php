<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\Resolution\MultiResolutionManager;

/**
 * Regression test for M-5: double-instantiation of tagged services in resolveAll().
 *
 * Before the fix, MultiResolutionManager::getAllImplementations() called
 * $container->tagged($abstract), which triggered TaggedBindingRegistry::resolveTagged()
 * and instantiated every service. Those instances were discarded and resolveAll()
 * then called $container->get() a second time — constructing each service twice.
 *
 * The fix adds Container::getTaggedAbstracts() which returns raw class-name strings
 * without resolving them, so resolveAll() constructs each service exactly once.
 */

class SingleInstCounterA
{
    public static int $constructCount = 0;

    public function __construct()
    {
        self::$constructCount++;
    }
}

class SingleInstCounterB
{
    public static int $constructCount = 0;

    public function __construct()
    {
        self::$constructCount++;
    }
}

class MultiResolutionSingleInstantiationTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset static counters before each test
        SingleInstCounterA::$constructCount = 0;
        SingleInstCounterB::$constructCount = 0;
    }

    /**
     * M-5: resolveAll() must construct each tagged service exactly once.
     */
    public function testResolveAllInstantiatesEachTaggedServiceExactlyOnce(): void
    {
        $container = new Container();

        $container->bind(SingleInstCounterA::class);
        $container->bind(SingleInstCounterB::class);

        $tag = 'm5-single-inst-tag';
        $container->tag([SingleInstCounterA::class, SingleInstCounterB::class], $tag);

        // resolveAll() triggers the bug path: getAllImplementations() previously called
        // container->tagged($tag) which resolved all services, then resolveAll() called
        // get() on each — resulting in double construction for non-singletons.
        $results = $container->resolveAll($tag);

        // Two distinct instances must be returned
        self::assertCount(2, $results, 'resolveAll() must return one instance per tagged service');

        // Each class must have been constructed exactly once (M-5 guard)
        self::assertSame(
            1,
            SingleInstCounterA::$constructCount,
            'SingleInstCounterA was constructed more than once — M-5 double-build regression'
        );
        self::assertSame(
            1,
            SingleInstCounterB::$constructCount,
            'SingleInstCounterB was constructed more than once — M-5 double-build regression'
        );
    }

    /**
     * getTaggedAbstracts() must return raw class-name strings without instantiating them.
     */
    public function testGetTaggedAbstractsDoesNotInstantiateServices(): void
    {
        $container = new Container();

        $container->bind(SingleInstCounterA::class);
        $container->bind(SingleInstCounterB::class);

        $tag = 'm5-abstracts-tag';
        $container->tag([SingleInstCounterA::class, SingleInstCounterB::class], $tag);

        $abstracts = $container->getTaggedAbstracts($tag);

        self::assertCount(2, $abstracts);
        self::assertContains(SingleInstCounterA::class, $abstracts);
        self::assertContains(SingleInstCounterB::class, $abstracts);

        // No instances should have been created
        self::assertSame(0, SingleInstCounterA::$constructCount, 'getTaggedAbstracts() must not instantiate services');
        self::assertSame(0, SingleInstCounterB::$constructCount, 'getTaggedAbstracts() must not instantiate services');
    }
}
