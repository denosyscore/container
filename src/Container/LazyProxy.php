<?php

declare(strict_types=1);

namespace CFXP\Core\Container;

/**
 * Lazy-loading proxy interface for deferring service instantiation.
 * 
 * Allows services to be resolved only when first accessed, improving performance
 * for expensive services that might not be used in every request.
 */
interface LazyProxy
{
    /**
     * Get the proxied instance, resolving it if necessary.
     * 
     * @return mixed The resolved instance
     */
    public function getInstance(): mixed;

    /**
     * Check if the instance has been resolved yet.
     * 
     * @return bool True if resolved, false if still lazy
     */
    public function isResolved(): bool;

    /**
     * Get the abstract identifier this proxy represents.
     * 
     * @return string The abstract identifier
     */
    public function getAbstract(): string;

    /**
     * Force resolution of the proxied instance.
     * 
     * @return mixed The resolved instance
     */
    public function resolve(): mixed;
}