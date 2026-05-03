<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ValidationIssue;
use Denosys\Container\Validation\ContainerValidator;

// ---------------------------------------------------------------------------
// Test fixtures — use unique names to avoid conflicts with other test files.
// ---------------------------------------------------------------------------
interface ValidatorFixtureInterface {}
class ValidatorFixtureConcrete implements ValidatorFixtureInterface {}

class ContainerValidatorTest extends TestCase
{
    private Container $container;
    private ContainerValidator $validator;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->validator = new ContainerValidator();
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function testEmptyContainerIsValid(): void
    {
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
        self::assertSame(0, $result->getStatistics()['total_bindings']);
    }

    public function testValidConcreteClassBindingPassesWithNoIssues(): void
    {
        $this->container->bind(ValidatorFixtureConcrete::class);

        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
    }

    public function testInterfaceWithExplicitConcretePassesWithNoErrors(): void
    {
        $this->container->bind(ValidatorFixtureInterface::class, ValidatorFixtureConcrete::class);

        $result = $this->validator->validate($this->container);

        // No errors — warnings about the self-bind check should not fire here.
        self::assertFalse($result->hasErrors());
    }

    // -----------------------------------------------------------------------
    // Check 1: non-existent class flagged as error
    // -----------------------------------------------------------------------

    public function testBindingToNonExistentClassFlagsError(): void
    {
        $this->container->bind('Denosys\\Does\\Not\\Exist\\GhostService');

        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertNotEmpty($errors);
        self::assertStringContainsString(
            'Denosys\\Does\\Not\\Exist\\GhostService',
            array_values($errors)[0]->message
        );
    }

    public function testResultHasCorrectIssueCountAndMessage(): void
    {
        $missing1 = 'Denosys\\Fake\\Alpha';
        $missing2 = 'Denosys\\Fake\\Beta';

        $this->container->bind($missing1);
        $this->container->bind($missing2);

        $result = $this->validator->validate($this->container);

        $errors = array_values($result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR));

        self::assertCount(2, $errors);
        self::assertStringContainsString($missing1, $errors[0]->message);
        self::assertStringContainsString($missing2, $errors[1]->message);
        self::assertSame(2, $result->getStatistics()['error_count']);
    }

    // -----------------------------------------------------------------------
    // Check 2: interface self-binding flagged as warning
    // -----------------------------------------------------------------------

    public function testInterfaceSelfBindingFlagsWarning(): void
    {
        // bind(Interface) without a concrete — defaults to self, which cannot be instantiated.
        $this->container->bind(ValidatorFixtureInterface::class);

        $result = $this->validator->validate($this->container);

        // No errors (the interface exists), but should warn.
        self::assertFalse($result->hasErrors());
        self::assertTrue($result->hasWarnings());

        $warnings = array_values($result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING));
        self::assertNotEmpty($warnings);
        self::assertStringContainsString(ValidatorFixtureInterface::class, $warnings[0]->message);
    }
}
