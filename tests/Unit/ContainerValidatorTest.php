<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ValidationIssue;
use Denosys\Container\Validation\ContainerValidator;

// Fixtures — prefixed to avoid collisions with other test files in the same namespace.
class ValidatorTestService {}
interface ValidatorTestInterface {}
class ValidatorTestConcreteImpl implements ValidatorTestInterface {}

class ContainerValidatorTest extends TestCase
{
    private Container $container;
    private ContainerValidator $validator;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->validator = new ContainerValidator();
    }

    public function testValidContainerPassesWithNoIssues(): void
    {
        $this->container->bind(ValidatorTestService::class);
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
    }

    public function testBindingToNonExistentClassIsError(): void
    {
        $this->container->bind('SomeAbstract', 'Acme\\Ghost\\ClassDoesNotExist');
        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());
    }

    public function testIssueCountAndMessage(): void
    {
        $this->container->bind('AnotherAbstract', 'Ghost\\NoSuch\\Implementation');
        $result = $this->validator->validate($this->container);

        self::assertCount(1, $result->getIssues());
        $issue = $result->getIssues()[0];
        self::assertSame(ValidationIssue::SEVERITY_ERROR, $issue->severity);
        self::assertStringContainsString('Ghost\\NoSuch\\Implementation', $issue->message);
    }

    public function testInterfaceWithSelfBindingIsWarning(): void
    {
        // bind(Interface::class) with no concrete — defaults to binding the interface to itself.
        $this->container->bind(ValidatorTestInterface::class);
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->hasWarnings());
        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        self::assertNotEmpty($warnings);
        // A self-bound interface produces only a warning, not an error — container is still "valid".
        self::assertTrue($result->isValid());
    }

    public function testInterfaceBoundToConcreteIsValid(): void
    {
        $this->container->bind(ValidatorTestInterface::class, ValidatorTestConcreteImpl::class);
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertFalse($result->hasErrors());
        self::assertFalse($result->hasWarnings());
    }
}
