<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;

class TaggedA {}
class TaggedB {}
class TaggedC {}

class TaggedBindingTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testTagAndResolveTagged(): void
    {
        $this->container->bind(TaggedA::class);
        $this->container->bind(TaggedB::class);
        $this->container->tag([TaggedA::class, TaggedB::class], 'my-tag');

        $results = $this->container->tagged('my-tag');
        self::assertCount(2, $results);
    }

    public function testTaggedReturnsEmptyForUnknownTag(): void
    {
        $results = $this->container->tagged('nonexistent');
        self::assertIsArray($results);
        self::assertEmpty($results);
    }

    public function testMultipleTags(): void
    {
        $this->container->bind(TaggedA::class);
        $this->container->tag(TaggedA::class, ['tag1', 'tag2']);

        self::assertCount(1, $this->container->tagged('tag1'));
        self::assertCount(1, $this->container->tagged('tag2'));
    }

    public function testTaggedInstances(): void
    {
        $this->container->bind(TaggedA::class);
        $this->container->tag(TaggedA::class, 'group');

        $results = $this->container->tagged('group');
        self::assertInstanceOf(TaggedA::class, $results[0]);
    }
}
