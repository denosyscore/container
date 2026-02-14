<?php

declare(strict_types=1);

namespace CFXP\Core\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use Exception;
use Throwable;

/**
 * Container resolution exception with detailed error information and resolution suggestions.
 */
class ContainerResolutionException extends Exception implements ContainerExceptionInterface
{
    /**
     * @param string $message The exception message
     * @param string|null $abstract The abstract identifier being resolved when the error occurred
     * @param array<string>|null $resolutionStack The stack of classes being resolved
     * @param array<string>|null $suggestions Suggested solutions for the error
     * @param Throwable|null $previous Previous exception in the chain
      * @param array<string, mixed> $resolutionStack
      * @param array<string, mixed> $suggestions
     */
    public function __construct(
        string $message,
        /**
         * @param array<string, mixed> $resolutionStack
         * @param array<string, mixed> $suggestions
         */
        public readonly ?string $abstract = null,
        /**
         * @param array<string, mixed> $resolutionStack
         * @param array<string, mixed> $suggestions
         */
        public readonly ?array $resolutionStack = null,
        /**
         * @param array<string, mixed> $suggestions
         */
        public readonly ?array $suggestions = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Get the abstract identifier that caused the exception.
     */
    public function getAbstract(): ?string
    {
        return $this->abstract;
    }

    /**
     * Get the resolution stack at the time of the exception.
     *
     * @return array<string>|null
     */
    public function getResolutionStack(): ?array
    {
        return $this->resolutionStack;
    }

    /**
     * Get suggested solutions for resolving the error.
     *
     * @return array<string>|null
     */
    public function getSuggestions(): ?array
    {
        return $this->suggestions;
    }

    /**
     * Get a formatted error message with additional context.
     */
    public function getDetailedMessage(): string
    {
        $message = $this->getMessage();

        if ($this->abstract !== null) {
            $message .= "\nAbstract: {$this->abstract}";
        }

        if (!empty($this->resolutionStack)) {
            $stack = implode(' -> ', $this->resolutionStack);
            $message .= "\nResolution Stack: {$stack}";
        }

        if (!empty($this->suggestions)) {
            $message .= "\n\nSuggestions:";
            foreach ($this->suggestions as $suggestion) {
                $message .= "\n  - {$suggestion}";
            }
        }

        return $message;
    }

    /**
     * Convert the exception to a string with detailed information.
     */
    public function __toString(): string
    {
        return $this->getDetailedMessage() . "\n\n" . $this->getTraceAsString();
    }
}
