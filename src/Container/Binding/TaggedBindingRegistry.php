<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Binding;

use CFXP\Core\Container\ContainerInterface;

/**
 * Registry for managing tagged services in the container.
 */
class TaggedBindingRegistry
{
    /**
     * @var array<string, array<string>> Maps tags to arrays of abstract identifiers
     */
    private array $tagMap = [];

    /**
     * @var array<string, array<string>> Maps abstract identifiers to their tags
     */
    private array $serviceTags = [];

    /**
     * Tag one or more services with specified tags.
     *
     * @param array<string>|string $abstracts
     * @param array<string>|string $tags
     */
    public function tag(array|string $abstracts, array|string $tags): void
    {
        $abstracts = is_array($abstracts) ? $abstracts : [$abstracts];
        $tags = is_array($tags) ? $tags : [$tags];

        foreach ($abstracts as $abstract) {
            foreach ($tags as $tag) {
                $this->addTag($abstract, $tag);
            }
        }
    }

    /**
     * Add a single tag to a service.
     */
    private function addTag(string $abstract, string $tag): void
    {
        // Add to tag map
        if (!isset($this->tagMap[$tag])) {
            $this->tagMap[$tag] = [];
        }
        
        if (!in_array($abstract, $this->tagMap[$tag], true)) {
            $this->tagMap[$tag][] = $abstract;
        }

        // Add to service tags
        if (!isset($this->serviceTags[$abstract])) {
            $this->serviceTags[$abstract] = [];
        }
        
        if (!in_array($tag, $this->serviceTags[$abstract], true)) {
            $this->serviceTags[$abstract][] = $tag;
        }
    }

    /**
     * Get all services tagged with a specific tag.
     */
    /**
     * @return array<string>
     */
public function getTaggedServices(string $tag): array
    {
        return $this->tagMap[$tag] ?? [];
    }

    /**
     * Resolve all services tagged with a specific tag.
      * @return array<object>
     */
    public function resolveTagged(string $tag, ContainerInterface $container): array
    {
        $services = [];
        $abstracts = $this->getTaggedServices($tag);

        foreach ($abstracts as $abstract) {
            try {
                $services[] = $container->get($abstract);
            } catch (\Throwable $e) {
                // Log error but continue with other services
                error_log("Failed to resolve tagged service '{$abstract}' for tag '{$tag}': " . $e->getMessage());
            }
        }

        return $services;
    }

    /**
     * Get all tags for a specific service.
      * @return array<string>
     */
    public function getTagsFor(string $abstract): array
    {
        return $this->serviceTags[$abstract] ?? [];
    }

    /**
     * Check if a service has a specific tag.
     */
    public function hasTag(string $abstract, string $tag): bool
    {
        return in_array($tag, $this->getTagsFor($abstract), true);
    }

    /**
     * Remove a tag from a service.
     */
    public function removeTag(string $abstract, string $tag): void
    {
        // Remove from tag map
        if (isset($this->tagMap[$tag])) {
            $key = array_search($abstract, $this->tagMap[$tag], true);
            if ($key !== false) {
                array_splice($this->tagMap[$tag], $key, 1);
            }

            // Clean up empty tag arrays
            if (empty($this->tagMap[$tag])) {
                unset($this->tagMap[$tag]);
            }
        }

        // Remove from service tags
        if (isset($this->serviceTags[$abstract])) {
            $key = array_search($tag, $this->serviceTags[$abstract], true);
            if ($key !== false) {
                array_splice($this->serviceTags[$abstract], $key, 1);
            }

            // Clean up empty service tag arrays
            if (empty($this->serviceTags[$abstract])) {
                unset($this->serviceTags[$abstract]);
            }
        }
    }

    /**
     * Remove all tags from a service.
     */
    public function clearServiceTags(string $abstract): void
    {
        $tags = $this->getTagsFor($abstract);
        foreach ($tags as $tag) {
            $this->removeTag($abstract, $tag);
        }
    }

    /**
     * Get all registered tags.
      * @return array<string>
     */
    public function getAllTags(): array
    {
        return array_keys($this->tagMap);
    }

    /**
     * Get the complete tag map.
      * @return array<string, array<string>>
     */
    public function getTagMap(): array
    {
        return $this->tagMap;
    }

    /**
     * Get statistics about tagged services.
      * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        return [
            'total_tags' => count($this->tagMap),
            'total_tagged_services' => count($this->serviceTags),
            'tags_by_service_count' => array_map('count', $this->tagMap),
            'services_by_tag_count' => array_map('count', $this->serviceTags)
        ];
    }
}