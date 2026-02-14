<?php

declare(strict_types=1);

namespace Denosys\Container;

/**
 * Interface for containers that support service tagging.
 */
interface TaggingContainerInterface
{
    /**
     * Tag one or more abstracts with specified tags.
     *
     * @param array<string>|string $abstracts
     * @param array<string>|string $tags
     */
    public function tag(array|string $abstracts, array|string $tags): void;

    /**
     * Retrieve all services tagged with a specific tag.
     *
     * @param string $tag
     *
     * @return array<string, mixed>
     */
    public function tagged(string $tag): array;
}
