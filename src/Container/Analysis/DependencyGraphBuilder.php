<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Analysis;

use CFXP\Core\Exceptions\ContainerResolutionException;
use CFXP\Core\Container\IntrospectableContainerInterface;

/**
 * Builds and analyzes dependency graphs for container services.
 * 
 * Provides visualization and analysis capabilities for understanding
 * service relationships, detecting circular dependencies, and optimizing
 * container configuration.
 */
class DependencyGraphBuilder
{
    /** @var array<string, array<string>> Dependency graph adjacency list */
    private array $dependencyGraph = [];

    /** @var array<string, array<string, mixed>> Node metadata */
    private array $nodeMetadata = [];

    /** @var array<string, int> Node depths in the dependency tree */
    private array $nodeDepths = [];

    /** @var array<int, array<string>> Detected circular dependencies */
    private array $circularDependencies = [];

    /**
     * Build dependency graph for the container.
     */
    /**
     * @return array<string, mixed>
     */
public function buildGraph(IntrospectableContainerInterface $container): array
    {
        $this->reset();
        
        $bindings = $container->getBindings();
        
        foreach (array_keys($bindings) as $abstract) {
            $this->analyzeService($abstract, $container);
        }

        $this->calculateDepths();
        $this->detectCircularDependencies();

        return $this->getGraphData();
    }

    /**
     * Get dependency relationships for a specific service.
      * @return array<string>
     */
    public function getServiceDependencies(string $abstract, IntrospectableContainerInterface $container): array
    {
        $dependencies = $container->getDependencies($abstract);
        
        return [
            'abstract' => $abstract,
            'direct_dependencies' => $this->extractDirectDependencies($dependencies),
            'transitive_dependencies' => $this->getTransitiveDependencies($abstract, $container),
            'dependents' => $this->getDependents($abstract),
            'depth' => $this->nodeDepths[$abstract] ?? 0,
            'complexity_score' => $this->calculateServiceComplexity($abstract),
            'metadata' => $this->nodeMetadata[$abstract] ?? []
        ];
    }

    /**
     * Detect circular dependencies in the graph.
      * @return array<array<string>>
     */
    public function detectCircularDependencies(): array
    {
        $this->circularDependencies = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($this->dependencyGraph) as $node) {
            if (!isset($visited[$node])) {
                $this->detectCircularDependenciesRecursive($node, $visited, $recursionStack, []);
            }
        }

        return $this->circularDependencies;
    }

    /**
     * Find the shortest path between two services.
     *
     * @return array<string>|null
     */
    public function findPath(string $from, string $to): ?array
    {
        if ($from === $to) {
            return [$from];
        }

        $queue = [[$from]];
        $visited = [$from => true];

        while (!empty($queue)) {
            $path = array_shift($queue);
            $node = end($path);

            $dependencies = $this->dependencyGraph[$node] ?? [];
            
            foreach ($dependencies as $dependency) {
                if ($dependency === $to) {
                    return array_merge($path, [$dependency]);
                }

                if (!isset($visited[$dependency])) {
                    $visited[$dependency] = true;
                    $queue[] = array_merge($path, [$dependency]);
                }
            }
        }

        return null; // No path found
    }

    /**
     * Get services that have no dependencies (leaf nodes).
      * @return array<string>
     */
    public function getLeafServices(): array
    {
        $leafServices = [];

        foreach (array_keys($this->dependencyGraph) as $service) {
            if (empty($this->dependencyGraph[$service])) {
                $leafServices[] = $service;
            }
        }

        return $leafServices;
    }

    /**
     * Get services that are not depended upon by others (root nodes).
      * @return array<string>
     */
    public function getRootServices(): array
    {
        $allDependencies = [];
        
        foreach ($this->dependencyGraph as $dependencies) {
            $allDependencies = array_merge($allDependencies, $dependencies);
        }
        
        $allDependencies = array_unique($allDependencies);
        $allServices = array_keys($this->dependencyGraph);

        return array_diff($allServices, $allDependencies);
    }

    /**
     * Get services with the most dependencies (highest fan-out).
      * @return array<string, int>
     */
    public function getMostConnectedServices(int $limit = 10): array
    {
        $connectionCounts = [];

        foreach ($this->dependencyGraph as $service => $dependencies) {
            $connectionCounts[$service] = count($dependencies);
        }

        arsort($connectionCounts);
        return array_slice($connectionCounts, 0, $limit, true);
    }

    /**
     * Get services that are most depended upon (highest fan-in).
      * @return array<string, int>
     */
    public function getMostDependedUponServices(int $limit = 10): array
    {
        $dependentCounts = [];

        foreach (array_keys($this->dependencyGraph) as $service) {
            $dependentCounts[$service] = count($this->getDependents($service));
        }

        arsort($dependentCounts);
        return array_slice($dependentCounts, 0, $limit, true);
    }

    /**
     * Export graph data in various formats.
     *
     * @return string|array<string, mixed>
     */
    public function exportGraph(string $format = 'array'): string|array
    {
        $graphData = $this->getGraphData();

        return match ($format) {
            'json' => json_encode($graphData, JSON_PRETTY_PRINT),
            'dot' => $this->exportToDot($graphData),
            'mermaid' => $this->exportToMermaid($graphData),
            'array' => $graphData,
            default => throw new ContainerResolutionException(
                "Unsupported export format: {$format}",
                null,
                null,
                ['Supported formats: array, json, dot, mermaid']
            )
        };
    }

    /**
     * Get graph analysis statistics.
      * @return array<string, mixed>
     */
    public function getGraphStatistics(): array
    {
        $nodeCount = count($this->dependencyGraph);
        $edgeCount = array_sum(array_map('count', $this->dependencyGraph));
        
        $depths = array_values($this->nodeDepths);
        $maxDepth = !empty($depths) ? max($depths) : 0;
        $avgDepth = !empty($depths) ? array_sum($depths) / count($depths) : 0;

        return [
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'max_depth' => $maxDepth,
            'average_depth' => $avgDepth,
            'circular_dependencies' => count($this->circularDependencies),
            'leaf_services' => count($this->getLeafServices()),
            'root_services' => count($this->getRootServices()),
            'density' => $nodeCount > 1 ? $edgeCount / ($nodeCount * ($nodeCount - 1)) : 0,
            'complexity_score' => $this->calculateOverallComplexity()
        ];
    }

    /**
     * Reset graph data.
     */
    private function reset(): void
    {
        $this->dependencyGraph = [];
        $this->nodeMetadata = [];
        $this->nodeDepths = [];
        $this->circularDependencies = [];
    }

    /**
     * Analyze a service and its dependencies.
     */
    private function analyzeService(string $abstract, IntrospectableContainerInterface $container): void
    {
        if (isset($this->dependencyGraph[$abstract])) {
            return; // Already analyzed
        }

        $dependencies = $container->getDependencies($abstract);
        $directDeps = $this->extractDirectDependencies($dependencies);

        $this->dependencyGraph[$abstract] = $directDeps;
        $this->nodeMetadata[$abstract] = [
            'type' => $this->determineServiceType($abstract),
            'is_bound' => $dependencies['is_bound'] ?? false,
            'tags' => $dependencies['tags'] ?? [],
            'dependency_count' => count($directDeps)
        ];

        // Recursively analyze dependencies
        foreach ($directDeps as $dependency) {
            $this->analyzeService($dependency, $container);
        }
    }

    /**
     * Extract direct dependencies from dependency info.
      * @param array<string, mixed> $dependencyInfo
      * @return array<string>
     */
    private function extractDirectDependencies(array $dependencyInfo): array
    {
        $dependencies = [];
        
        if (isset($dependencyInfo['dependencies']) && is_array($dependencyInfo['dependencies'])) {
            foreach ($dependencyInfo['dependencies'] as $dep) {
                if (isset($dep['type']) && !$this->isBuiltinType($dep['type'])) {
                    $dependencies[] = $dep['type'];
                }
            }
        }

        return array_unique($dependencies);
    }

    /**
     * Get transitive dependencies (dependencies of dependencies).
      * @return array<string>
     */
    private function getTransitiveDependencies(string $abstract, IntrospectableContainerInterface $container): array
    {
        $transitive = [];
        $visited = [];
        
        $this->getTransitiveDependenciesRecursive($abstract, $transitive, $visited);
        
        return array_values(array_unique($transitive));
    }

    /**
     * Recursively get transitive dependencies.
     *
     * @param array<string> $transitive
     * @param-out array<string> $transitive
     * @param array<string, bool> $visited
     */
    private function getTransitiveDependenciesRecursive(string $service, array &$transitive, array &$visited): void
    {
        if (isset($visited[$service])) {
            return;
        }

        $visited[$service] = true;
        $dependencies = $this->dependencyGraph[$service] ?? [];

        foreach ($dependencies as $dependency) {
            $transitive[] = $dependency;
            $this->getTransitiveDependenciesRecursive($dependency, $transitive, $visited);
        }
    }

    /**
     * Get services that depend on the given service.
      * @return array<string>
     */
    private function getDependents(string $abstract): array
    {
        $dependents = [];

        foreach ($this->dependencyGraph as $service => $dependencies) {
            if (in_array($abstract, $dependencies, true)) {
                $dependents[] = $service;
            }
        }

        return $dependents;
    }

    /**
     * Calculate depths of nodes in the dependency graph.
     */
    private function calculateDepths(): void
    {
        $leafServices = $this->getLeafServices();
        
        // Leaf services have depth 0
        foreach ($leafServices as $service) {
            $this->nodeDepths[$service] = 0;
        }

        // Calculate depths using topological sort
        $queue = $leafServices;
        $processed = array_flip($leafServices);

        while (!empty($queue)) {
            $current = array_shift($queue);
            $currentDepth = $this->nodeDepths[$current];

            $dependents = $this->getDependents($current);
            
            foreach ($dependents as $dependent) {
                if (!isset($this->nodeDepths[$dependent])) {
                    $this->nodeDepths[$dependent] = $currentDepth + 1;
                } else {
                    $this->nodeDepths[$dependent] = max($this->nodeDepths[$dependent], $currentDepth + 1);
                }

                if (!isset($processed[$dependent])) {
                    $queue[] = $dependent;
                    $processed[$dependent] = true;
                }
            }
        }
    }

    /**
     * Recursively detect circular dependencies.
     *
     * @param array<string, bool> $visited
     * @param array<string, bool> $recursionStack
     * @param array<string> $path
     */
    private function detectCircularDependenciesRecursive(string $node, array &$visited, array &$recursionStack, array $path): void
    {
        $visited[$node] = true;
        $recursionStack[$node] = true;
        $path[] = $node;

        $dependencies = $this->dependencyGraph[$node] ?? [];
        
        foreach ($dependencies as $dependency) {
            if (!isset($visited[$dependency])) {
                $this->detectCircularDependenciesRecursive($dependency, $visited, $recursionStack, $path);
            } elseif (isset($recursionStack[$dependency])) {
                // Found a circular dependency
                $cycleStart = array_search($dependency, $path);
                if ($cycleStart !== false) {
                    $cycle = array_slice($path, $cycleStart);
                    $cycle[] = $dependency; // Complete the cycle
                    $this->circularDependencies[] = $cycle;
                }
            }
        }

        unset($recursionStack[$node]);
    }

    /**
     * Calculate complexity score for a service.
     */
    private function calculateServiceComplexity(string $abstract): float
    {
        $directDeps = count($this->dependencyGraph[$abstract] ?? []);
        // Use existing data instead of calling getTransitiveDependencies with placeholder
        $transitiveDeps = 0;
        $visited = [];
        $this->countTransitiveDependencies($abstract, $transitiveDeps, $visited);
        $dependents = count($this->getDependents($abstract));
        $depth = $this->nodeDepths[$abstract] ?? 0;

        return ($directDeps * 1.0) + ($transitiveDeps * 0.5) + ($dependents * 0.3) + ($depth * 0.2);
    }

    /**
     * Count transitive dependencies without needing a container.
     *
     * @param array<string, bool> $visited
     */
    private function countTransitiveDependencies(string $service, int &$count, array &$visited): void
    {
        if (isset($visited[$service])) {
            return;
        }
        $visited[$service] = true;
        
        $dependencies = $this->dependencyGraph[$service] ?? [];
        foreach ($dependencies as $dependency) {
            $count++;
            $this->countTransitiveDependencies($dependency, $count, $visited);
        }
    }

    /**
     * Calculate overall complexity of the dependency graph.
     */
    private function calculateOverallComplexity(): float
    {
        $totalComplexity = 0;
        
        foreach (array_keys($this->dependencyGraph) as $service) {
            $totalComplexity += $this->calculateServiceComplexity($service);
        }

        return $totalComplexity;
    }

    /**
     * Determine the type of service.
     */
    private function determineServiceType(string $abstract): string
    {
        if (interface_exists($abstract)) {
            return 'interface';
        }
        
        if (class_exists($abstract)) {
            try {
                $reflection = new \ReflectionClass($abstract);
                if ($reflection->isAbstract()) {
                    return 'abstract_class';
                }
                return 'class';
            } catch (\ReflectionException $e) {
                return 'unknown';
            }
        }

        return 'custom';
    }

    /**
     * Check if a type is a built-in PHP type.
     */
    private function isBuiltinType(string $type): bool
    {
        return in_array($type, ['string', 'int', 'float', 'bool', 'array', 'object', 'callable', 'mixed'], true);
    }

    /**
     * Get complete graph data.
      * @return array<string, mixed>
     */
    private function getGraphData(): array
    {
        return [
            'nodes' => array_keys($this->dependencyGraph),
            'edges' => $this->dependencyGraph,
            'metadata' => $this->nodeMetadata,
            'depths' => $this->nodeDepths,
            'circular_dependencies' => $this->circularDependencies,
            'statistics' => $this->getGraphStatistics()
        ];
    }

    /**
     * Export graph to DOT format for Graphviz.
      * @param array<string, mixed> $graphData
     */
    private function exportToDot(array $graphData): string
    {
        $dot = "digraph DependencyGraph {\n";
        $dot .= "  rankdir=TB;\n";
        $dot .= "  node [shape=box];\n\n";

        // Add nodes with metadata
        foreach ($graphData['nodes'] as $node) {
            $metadata = $graphData['metadata'][$node] ?? [];
            $type = $metadata['type'] ?? 'unknown';
            $color = match ($type) {
                'interface' => 'lightblue',
                'abstract_class' => 'lightyellow',
                'class' => 'lightgreen',
                default => 'lightgray'
            };
            
            $dot .= "  \"{$node}\" [fillcolor={$color}, style=filled];\n";
        }

        $dot .= "\n";

        // Add edges
        foreach ($graphData['edges'] as $from => $dependencies) {
            foreach ($dependencies as $to) {
                $dot .= "  \"{$from}\" -> \"{$to}\";\n";
            }
        }

        $dot .= "}\n";

        return $dot;
    }

    /**
     * Export graph to Mermaid format.
      * @param array<string, mixed> $graphData
     */
    private function exportToMermaid(array $graphData): string
    {
        $mermaid = "graph TD\n";

        foreach ($graphData['edges'] as $from => $dependencies) {
            $fromSafe = $this->sanitizeForMermaid($from);
            
            foreach ($dependencies as $to) {
                $toSafe = $this->sanitizeForMermaid($to);
                $mermaid .= "  {$fromSafe} --> {$toSafe}\n";
            }
        }

        return $mermaid;
    }

    /**
     * Sanitize string for Mermaid diagram.
     */
    private function sanitizeForMermaid(string $input): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $input);
    }
}