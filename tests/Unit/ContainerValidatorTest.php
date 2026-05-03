<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ValidationIssue;
use Denosys\Container\Validation\ContainerValidator;

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------
interface ValidatorDummyInterface {}

class ValidatorDummyImpl implements ValidatorDummyInterface {}

class ValidatorServiceNoDeps {}

class ValidatorServiceWithInterfaceDep
{
    public function __construct(public readonly ValidatorDummyInterface $dep) {}
}

// ---------------------------------------------------------------------------

class ContainerValidatorTest extends TestCase
{
    private ContainerValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ContainerValidator();
    }

    // -----------------------------------------------------------------------
    // Valid container
    // -----------------------------------------------------------------------

    public function testValidContainerPassesWithNoIssues(): void
    {
        $container = new Container();
        $container->bind(ValidatorServiceNoDeps::class);

        $result = $this->validator->validate($container);

        self::assertTrue($result->isValid());
        self::assertCount(0, $result->getIssues());
        self::assertFalse($result->hasErrors());
    }

    public function testValidInterfaceBindingPassesWithNoIssues(): void
    {
        $container = new Container();
        $container->bind(ValidatorDummyInterface::class, ValidatorDummyImpl::class);

        $result = $this->validator->validate($container);

        self::assertTrue($result->isValid());
        self::assertFalse($result->hasErrors());
    }

    public function testClosureBindingIsNotFlaggedAsError(): void
    {
        $container = new Container();
        $container->bind('some.service', fn() => new ValidatorServiceNoDeps());

        $result = $this->validator->validate($container);

        self::assertTrue($result->isValid());
        self::assertFalse($result->hasErrors());
    }

    // -----------------------------------------------------------------------
    // Error: binding to non-existent class
    // -----------------------------------------------------------------------

    public function testBindingToNonExistentClassIsFlaggedAsError(): void
    {
        $container = new Container();
        $container->bind('App\\NonExistent\\MissingService');

        $result = $this->validator->validate($container);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertCount(1, $errors);
        self::assertStringContainsString('MissingService', reset($errors)->message);
    }

    public function testResultHasCorrectIssueCountAndMessage(): void
    {
        $container = new Container();
        $container->bind('App\\Missing\\ServiceAlpha');
        $container->bind('App\\Missing\\ServiceBeta');

        $result = $this->validator->validate($container);

        self::assertFalse($result->isValid());
        self::assertCount(2, $result->getIssues());
        self::assertSame(2, $result->getStatistics()['error_count']);

        $messages = array_map(fn($i) => $i->message, $result->getIssues());
        $hasAlpha = (bool) array_filter($messages, fn($m) => str_contains($m, 'ServiceAlpha'));
        $hasBeta  = (bool) array_filter($messages, fn($m) => str_contains($m, 'ServiceBeta'));

        self::assertTrue($hasAlpha, 'Expected an error message mentioning ServiceAlpha');
        self::assertTrue($hasBeta,  'Expected an error message mentioning ServiceBeta');
    }

    public function testExplicitConcreteNonExistentClassIsFlaggedAsError(): void
    {
        $container = new Container();
        $container->bind(ValidatorDummyInterface::class, 'App\\Missing\\ConcreteImpl');

        $result = $this->validator->validate($container);

        self::assertFalse($result->isValid());
        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertNotEmpty($errors);
        self::assertStringContainsString('ConcreteImpl', reset($errors)->message);
    }

    // -----------------------------------------------------------------------
    // Warning: interface self-bound with no concrete
    // -----------------------------------------------------------------------

    public function testSelfBoundInterfaceProducesWarning(): void
    {
        $container = new Container();
        $container->bind(ValidatorDummyInterface::class);  // no concrete — self-bound

        $result = $this->validator->validate($container);

        // Warnings don't make the container invalid.
        self::assertTrue($result->isValid());
        self::assertTrue($result->hasWarnings());

        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('ValidatorDummyInterface', reset($warnings)->message);
    }

    public function testInterfaceBoundToConcreteProducesNoWarning(): void
    {
        $container = new Container();
        $container->bind(ValidatorDummyInterface::class, ValidatorDummyImpl::class);
        $container->bind(ValidatorServiceWithInterfaceDep::class);

        $result = $this->validator->validate($container);

        self::assertTrue($result->isValid());
        self::assertFalse($result->hasWarnings());
    }

    // -----------------------------------------------------------------------
    // Warning: interface constructor dep not registered
    // -----------------------------------------------------------------------

    public function testUnboundInterfaceDepProducesWarning(): void
    {
        $container = new Container();
        $container->bind(ValidatorServiceWithInterfaceDep::class);
        // ValidatorDummyInterface is NOT bound

        $result = $this->validator->validate($container);

        self::assertTrue($result->isValid());  // warnings don't fail validation
        self::assertTrue($result->hasWarnings());

        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('ValidatorDummyInterface', reset($warnings)->message);
    }

    // -----------------------------------------------------------------------
    // Statistics
    // -----------------------------------------------------------------------

    public function testStatisticsReflectBindingCount(): void
    {
        $container = new Container();
        $container->bind(ValidatorServiceNoDeps::class);
        $container->bind(ValidatorDummyImpl::class);

        $result = $this->validator->validate($container);

        self::assertSame(2, $result->getStatistics()['total_bindings']);
    }
}
