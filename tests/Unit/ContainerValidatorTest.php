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


    // -----------------------------------------------------------------------
    // Mi-4 tests
    // -----------------------------------------------------------------------

    public function testCircularDependencyIsDetectedAsError(): void
    {
        $this->container->bind(Mi4ServiceA::class);
        $this->container->bind(Mi4ServiceB::class);
        $result = $this->validator->validate($this->container);

        self::assertFalse($result->isValid(), 'Container with circular dep should be invalid');
        self::assertTrue($result->hasErrors(), 'Circular dep should produce an ERROR issue');

        $errors = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_ERROR);
        $found = false;
        foreach ($errors as $issue) {
            if (stripos($issue->message, 'circular') !== false) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'At least one ERROR issue should mention "circular"');
    }

    public function testMissingBindingForInterfaceParameterIsWarning(): void
    {
        // Mi4NeedsUnbound depends on Mi4UnboundInterface which is NOT registered.
        $this->container->bind(Mi4NeedsUnbound::class);
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->hasWarnings(), 'Missing unbound interface dep should produce a WARNING');

        $warnings = $result->getIssuesBySeverity(ValidationIssue::SEVERITY_WARNING);
        $found = false;
        foreach ($warnings as $issue) {
            if (str_contains($issue->message, 'Mi4UnboundInterface')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'A WARNING issue should mention the unbound interface name');
    }

    public function testCleanFullyResolvableContainerIsValid(): void
    {
        // Mi4DependsOnSimple -> Mi4SimpleService (concrete, instantiable - no explicit binding needed)
        $this->container->bind(Mi4SimpleService::class);
        $this->container->bind(Mi4DependsOnSimple::class);
        $result = $this->validator->validate($this->container);

        self::assertTrue($result->isValid(), 'Fully resolvable container should be valid');
        self::assertFalse($result->hasErrors());
        self::assertFalse($result->hasWarnings());
    }
}


// ---------------------------------------------------------------------------
// Mi-4 fixtures: circular dependency A -> B -> A
// ---------------------------------------------------------------------------
class Mi4ServiceB {
    public function __construct(public readonly Mi4ServiceA $a) {}
}
class Mi4ServiceA {
    public function __construct(public readonly Mi4ServiceB $b) {}
}

// Mi-4 fixtures: missing binding - NeedsUnbound depends on UnboundIface (not registered)
interface Mi4UnboundInterface {}
class Mi4NeedsUnbound {
    public function __construct(public readonly Mi4UnboundInterface $dep) {}
}

// Mi-4 fixtures: clean fully-resolvable container
class Mi4SimpleService {}
class Mi4DependsOnSimple {
    public function __construct(public readonly Mi4SimpleService $svc) {}
}
