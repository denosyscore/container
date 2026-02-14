<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

/**
 * Result of container validation containing any issues found.
 */
class ValidationResult
{
    /**
     * @param bool $isValid Whether the container configuration is valid
     * @param array<ValidationIssue> $issues Array of validation issues found
     * @param array<string, mixed> $statistics Validation statistics
     */
    public function __construct(
        /**
         * @param array<string, mixed> $issues
         * @param array<string, mixed> $statistics
         */
        public readonly bool $isValid,
        /**
         * @param array<string, mixed> $issues
         * @param array<string, mixed> $statistics
         */
        public readonly array $issues = [],
        /**
         * @param array<string, mixed> $statistics
         */
        public readonly array $statistics = []
    ) {}

    /**
     * Check if the validation passed without issues.
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Get all validation issues.
     * 
     * @return array<ValidationIssue>
     */
    /**
     * @return array<string, mixed>
     */
public function getIssues(): array
    {
        return $this->issues;
    }

    /**
     * Get issues by severity level.
     * 
     * @param string $severity The severity level (error, warning, info)
     * @return array<ValidationIssue>
     */
    public function getIssuesBySeverity(string $severity): array
    {
        return array_filter($this->issues, fn($issue) => $issue->severity === $severity);
    }

    /**
     * Get validation statistics.
     * 
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Check if there are any errors (as opposed to warnings).
     */
    public function hasErrors(): bool
    {
        return !empty($this->getIssuesBySeverity('error'));
    }

    /**
     * Check if there are any warnings.
     */
    public function hasWarnings(): bool
    {
        return !empty($this->getIssuesBySeverity('warning'));
    }

    /**
     * Get a summary of the validation results.
     */
    public function getSummary(): string
    {
        $totalIssues = count($this->issues);
        $errors = count($this->getIssuesBySeverity('error'));
        $warnings = count($this->getIssuesBySeverity('warning'));

        if ($this->isValid) {
            return "Validation passed. No issues found.";
        }

        return "Validation failed. {$totalIssues} issues found: {$errors} errors, {$warnings} warnings.";
    }
}

/**
 * Represents a single validation issue.
 */
class ValidationIssue
{
    /**
     * @param array<string, mixed> $suggestions
     */
    public const SEVERITY_ERROR = 'error';
    /**
     * @param array<string, mixed> $suggestions
     */
    public const SEVERITY_WARNING = 'warning';
    /**
     * @param array<string, mixed> $suggestions
     */
    public const SEVERITY_INFO = 'info';

    /**
     * @param string $severity The severity level
     * @param string $message The issue description
     * @param string|null $abstract The abstract identifier related to this issue
     * @param array<string> $suggestions Suggested fixes for the issue
     */
    public function __construct(
        /**
         * @param array<string, mixed> $suggestions
         */
        public readonly string $severity,
        /**
         * @param array<string, mixed> $suggestions
         */
        public readonly string $message,
        /**
         * @param array<string, mixed> $suggestions
         */
        public readonly ?string $abstract = null,
        /**
         * @param array<string, mixed> $suggestions
         */
        public readonly array $suggestions = []
    ) {}

    /**
     * Check if this is an error-level issue.
     */
    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    /**
     * Check if this is a warning-level issue.
     */
    public function isWarning(): bool
    {
        return $this->severity === self::SEVERITY_WARNING;
    }

    /**
     * Check if this is an info-level issue.
     */
    public function isInfo(): bool
    {
        return $this->severity === self::SEVERITY_INFO;
    }

    /**
     * Get a formatted description of the issue.
     */
    public function getDescription(): string
    {
        $description = "[{$this->severity}] {$this->message}";
        
        if ($this->abstract !== null) {
            $description .= " (Abstract: {$this->abstract})";
        }

        if (!empty($this->suggestions)) {
            $description .= "\nSuggestions:\n";
            foreach ($this->suggestions as $suggestion) {
                $description .= "  - {$suggestion}\n";
            }
        }

        return $description;
    }
}