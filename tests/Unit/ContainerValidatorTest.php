<?php

declare(strict_types=1);

namespace Denosys\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Denosys\Container\Container;
use Denosys\Container\ValidationIssue;
use Denosys\Container\Validation\ContainerValidator;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** A concrete class with no dependencies -- always valid. */
class ValidatorTestService {}

/** An interface used to test unbound-dependency warnings. */
interface ValidatorTestInterface {}

/** A service that depends on an interface -- triggers a warning when unbound. */
class ValidatorTestServiceWithInterface
{
    public function __construct(public readonly ValidatorTestInterface $dep) {}
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class ContainerValidatorTest extends TestCase
{
    private Container $container;
    private ContainerValidator $validator;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->validator = new ContainerValidator();
    }

    /**
     * A container that only holds resolvable concrete-class bindings should
     * pass validation with zero issues.
     */
    public function testValidContainerPassesWithNoIssues(): void
    {
        $this->container->bind(ValidatorTestService::class);

        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid());
        self::assertEmpty($result->getIssues());
        self::assertFalse($result->hasErrors());
    }

    /**
     * Binding a non-existent FQCN must produce an error-level issue and mark
     * the container as invalid.
     */
    public function testBindingToNonExistentClassIsFlaggedAsError(): void
    {
        $this->container->bind('Denosys\\Container\\Ghost\\NonExistent');

        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasErrors());

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertCount(1, $errors);
    }

    /**
     * When multiple non-existent classes are bound, the result must report the
     * right issue count and each error message must name the missing class.
     */
    public function testResultHasCorrectIssueCountAndMessage(): void
    {
        $ghost1 = 'Acme\\Missing\\ServiceAlpha';
        $ghost2 = 'Acme\\Missing\\ServiceBeta';

        $this->container->bind($ghost1);
        $this->container->bind($ghost2);

        $result = $this->validator->validate($this->container);

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        self::assertCount(2, $errors);

        $messages = array_map(fn(ValidationIssue $i) => $i->message, $errors);
        self::assertStringContainsString($ghost1, $messages[0]);
        self::assertStringContainsString($ghost2, $messages[1]);
    }

    /**
     * When a class is bound and one of its interface-typed dependencies has no
     * binding in the container, a WARNING (not an error) is produced and the
     * container is still considered valid.
     */
    public function testUnboundInterfaceDependencyIsWarning(): void
    {
        // Bind the class but NOT its interface dependency.
        $this->container->bind(ValidatorTestServiceWithInterface::class);

        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid(), 'Warnings must not mark the container invalid');
        self::assertTrue($result->hasWarnings());
        self::assertFalse($result->hasErrors());

        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        self::assertCount(1, $warnings);
        self::assertStringContainsString(
            ValidatorTestInterface::class,
            $warnings[0]->message
        );
    }
}
