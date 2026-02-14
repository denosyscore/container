<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Testing;

class MockBindingManager
{
    /** @var array<string, mixed> */

    private array $mocks = [];

    public function addMock(string $abstract, mixed $mock): void
    {
        $this->mocks[$abstract] = $mock;
    }

    public function hasMock(string $abstract): bool
    {
        return isset($this->mocks[$abstract]);
    }

    public function getMock(string $abstract): mixed
    {
        return $this->mocks[$abstract] ?? null;
    }

    public function removeMock(string $abstract): void
    {
        unset($this->mocks[$abstract]);
    }

    public function clearMocks(): void
    {
        $this->mocks = [];
    }

    /**

     * @return array<string, mixed>

     */

public function getAllMocks(): array

    {
        return $this->mocks;
    }
}
