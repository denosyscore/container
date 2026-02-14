<?php

declare(strict_types=1);

namespace Denosys\Container\Exceptions;

use Throwable;

/**
 * Exception thrown when there are conflicts in container bindings.
 * This includes duplicate bindings, incompatible binding types, or validation errors.
 */
class BindingConflictException extends ContainerResolutionException
{
    public const CONFLICT_TYPE_DUPLICATE = 'duplicate';
    public const CONFLICT_TYPE_INCOMPATIBLE = 'incompatible';
    public const CONFLICT_TYPE_VALIDATION = 'validation';
    public const CONFLICT_TYPE_CIRCULAR_ALIAS = 'circular_alias';

    /**
     * @param string $abstract The abstract identifier with the binding conflict
     * @param string $conflictType The type of conflict (use class constants)
     * @param array<string, mixed> $conflictDetails Additional details about the conflict
     * @param array<string>|null $suggestions Custom suggestions for resolving the conflict
     * @param Throwable|null $previous Previous exception in the chain
      * @param array<string, mixed> $suggestions
     */
    public function __construct(
        string $abstract,
        /**
         * @param array<string, mixed> $conflictDetails
         * @param array<string, mixed> $suggestions
         */
        public readonly string $conflictType,
        /**
         * @param array<string, mixed> $conflictDetails
         * @param array<string, mixed> $suggestions
         */
        public readonly array $conflictDetails = [],
        ?array $suggestions = null,
        ?Throwable $previous = null
    ) {
        $message = $this->buildConflictMessage($abstract, $conflictType, $conflictDetails);
        $defaultSuggestions = $this->getDefaultSuggestions($conflictType);
        $finalSuggestions = $suggestions ?? $defaultSuggestions;

        parent::__construct(
            $message,
            $abstract,
            null, // No resolution stack for binding conflicts
            $finalSuggestions,
            $previous
        );
    }

    /**
     * Get the type of binding conflict.
     */
    public function getConflictType(): string
    {
        return $this->conflictType;
    }

    /**
     * Get additional details about the conflict.
     *
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
public function getConflictDetails(): array
    {
        return $this->conflictDetails;
    }

    /**
     * Build the conflict message based on the type and details.
      * @param array<string, mixed> $details
     */
    private function buildConflictMessage(string $abstract, string $conflictType, array $details): string
    {
        return match ($conflictType) {
            self::CONFLICT_TYPE_DUPLICATE => $this->buildDuplicateMessage($abstract, $details),
            self::CONFLICT_TYPE_INCOMPATIBLE => $this->buildIncompatibleMessage($abstract, $details),
            self::CONFLICT_TYPE_VALIDATION => $this->buildValidationMessage($abstract, $details),
            self::CONFLICT_TYPE_CIRCULAR_ALIAS => $this->buildCircularAliasMessage($abstract, $details),
            default => "Binding conflict for '{$abstract}': {$conflictType}"
        };
    }

    /**
     * Build message for duplicate binding conflicts.
      * @param array<string, mixed> $details
     */
    private function buildDuplicateMessage(string $abstract, array $details): string
    {
        $existingType = $details['existing_type'] ?? 'unknown';
        $newType = $details['new_type'] ?? 'unknown';
        
        return "Duplicate binding for '{$abstract}': attempting to bind as {$newType} " .
               "but already bound as {$existingType}";
    }

    /**
     * Build message for incompatible binding conflicts.
      * @param array<string, mixed> $details
     */
    private function buildIncompatibleMessage(string $abstract, array $details): string
    {
        $reason = $details['reason'] ?? 'incompatible binding types';
        
        return "Incompatible binding for '{$abstract}': {$reason}";
    }

    /**
     * Build message for validation conflicts.
      * @param array<string, mixed> $details
     */
    private function buildValidationMessage(string $abstract, array $details): string
    {
        $validationError = $details['validation_error'] ?? 'binding validation failed';
        
        return "Binding validation failed for '{$abstract}': {$validationError}";
    }

    /**
     * Build message for circular alias conflicts.
      * @param array<string, mixed> $details
     */
    private function buildCircularAliasMessage(string $abstract, array $details): string
    {
        $aliasChain = $details['alias_chain'] ?? [];
        $chain = implode(' -> ', $aliasChain);
        
        return "Circular alias detected for '{$abstract}': {$chain}";
    }

    /**
     * Get default suggestions based on conflict type.
     *
     * @return array<string>
     */
    private function getDefaultSuggestions(string $conflictType): array
    {
        return match ($conflictType) {
            self::CONFLICT_TYPE_DUPLICATE => [
                'Check if the binding is already registered elsewhere',
                'Use extend() instead of bind() to modify existing bindings',
                'Consider using different abstract identifiers for different implementations'
            ],
            self::CONFLICT_TYPE_INCOMPATIBLE => [
                'Ensure the concrete implementation matches the abstract interface',
                'Check that binding types (singleton vs transient) are compatible',
                'Verify that the concrete class implements the required interface'
            ],
            self::CONFLICT_TYPE_VALIDATION => [
                'Check that the concrete implementation is valid',
                'Ensure all required dependencies are available',
                'Verify that the binding configuration is correct'
            ],
            self::CONFLICT_TYPE_CIRCULAR_ALIAS => [
                'Remove circular references in alias definitions',
                'Use direct bindings instead of chained aliases',
                'Check alias definitions for loops'
            ],
            default => [
                'Review the binding configuration',
                'Check for conflicts with existing bindings',
                'Ensure proper binding setup'
            ]
        };
    }
}