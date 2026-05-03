<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ValidationIssue;
use Denosys\Container\Validation\ContainerValidator;

// Fixtures used exclusively by this test file
interface ValidatorTestInterface {}
class ValidatorConcreteImpl implements ValidatorTestInterface {}
class ValidatorSimpleService {}

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
        $this->container->bind(ValidatorSimpleService::class);
        $this->container->bind(ValidatorTestInterface::class, ValidatorConcreteImpl::class);

        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
        self::assertFalse($result->hasErrors());
    }

    public function testBindingToNonExistentClassIsError(): void
    {
        $this->container->bind(
            ValidatorTestInterface::class,
            'Denosys\\Container\\Tests\\Unit\\NonExistentConcreteClass'
        );

        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertCount(1, $errors);

        $error = reset($errors);
        self::assertStringContainsString('NonExistentConcreteClass', $error->message);
        self::assertSame(ValidatorTestInterface::class, $error->abstract);
    }

    public function testResultHasCorrectIssueCountAndMessage(): void
    {
        $this->container->bind(
            ValidatorSimpleService::class,
            'Denosys\\Container\\Tests\\Unit\\AlsoMissing'
        );
        $this->container->bind(
            ValidatorTestInterface::class,
            'Denosys\\Container\\Tests\\Unit\\StillMissing'
        );

        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->getIssues());

        $stats = $result->getStatistics();
        self::assertSame(2, $stats['error_count']);
        self::assertSame(0, $stats['warning_count']);

        $summary = $result->getSummary();
        self::assertStringContainsString('2 issues', $summary);
        self::assertStringContainsString('2 errors', $summary);
    }

    public function testInterfaceBoundToItselfIsWarning(): void
    {
        // bind() with no second arg → concrete_class = abstract itself
        $this->container->bind(ValidatorTestInterface::class);

        $result = $this->validator->validate($this->container);

        // A warning does not make the container invalid (no errors)
        self::assertTrue($result->isValid());
        self::assertTrue($result->hasWarnings());
        self::assertFalse($result->hasErrors());

        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(ValidatorTestInterface::class, reset($warnings)->message);
    }

    public function testEmptyContainerIsValid(): void
    {
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
        self::assertSame(0, $result->getStatistics()['total_bindings']);
    }

    public function testClosureBindingIsNotChecked(): void
    {
        // Closures have no string concrete to validate — should always pass
        $this->container->bind(
            ValidatorTestInterface::class,
            fn() => new ValidatorConcreteImpl()
        );

        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
    }
}
