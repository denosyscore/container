<?php

declare(strict_types=1);

namespace CFXP\Core\Container\Performance;

use Closure;
use CFXP\Core\Container\Container;
use CFXP\Core\Exceptions\ContainerResolutionException;

/**
 * Container compiler for generating optimized production code.
 * 
 * Analyzes the container configuration and generates optimized PHP code
 * that eliminates reflection overhead and provides faster service resolution.
 */
class ContainerCompiler
{
    /**
     * @var string Generated class name for the compiled container
     */
    private string $compiledClassName;

    /**
     * @var string Namespace for the compiled container
     */
    private string $compiledNamespace;

    /**
     * @var array<string, mixed> Compilation options
     */
    private array $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        /**
         * @param array<string, mixed> $options
         */
        private Container $container,
        array $options = []
    ) {
        $this->compiledClassName = $options['class_name'] ?? 'CompiledContainer';
        $this->compiledNamespace = $options['namespace'] ?? 'CFXP\\Core\\Container\\Compiled';
        $this->options = array_merge([
            'optimize_singletons' => true,
            'inline_simple_bindings' => true,
            'generate_factory_methods' => true,
            'include_debug_info' => false,
            'validate_bindings' => true
        ], $options);
    }

    /**
     * Compile the container to optimized PHP code.
     */
    public function compile(string $outputPath): void
    {
        // Validate the container before compilation
        if ($this->options['validate_bindings']) {
            $validation = $this->container->validate();
            if (!$validation->isValid()) {
                throw new ContainerResolutionException(
                    'Cannot compile container with validation errors: ' . $validation->getSummary(),
                    null,
                    null,
                    ['Fix validation errors before compiling', 'Run container validation for details']
                );
            }
        }

        // Analyze the container
        $analysis = $this->analyzeContainer();
        $fingerprint = $this->fingerprint();

        // Generate the compiled code
        $compiledCode = $this->generateCompiledContainer($analysis, $fingerprint);

        // Write to file
        $this->writeCompiledContainer($outputPath, $compiledCode);
    }

    /**
     * Generate a deterministic fingerprint for the current container graph.
     */
    public function fingerprint(): string
    {
        $bindings = $this->extractRawBindings();
        $aliases = $this->extractRawAliases();
        $contextual = $this->extractRawContextualBindings();

        $normalizedBindings = [];
        foreach ($bindings as $abstract => $binding) {
            $normalizedBindings[$abstract] = [
                'shared' => (bool) ($binding['shared'] ?? false),
                'concrete' => $this->normalizeForFingerprint($binding['concrete'] ?? null),
            ];
        }
        ksort($normalizedBindings);

        ksort($aliases);

        $normalizedContextual = [];
        foreach ($contextual as $concrete => $definitions) {
            if (!is_array($definitions)) {
                continue;
            }

            $entries = [];
            foreach ($definitions as $abstract => $implementation) {
                $entries[$abstract] = $this->normalizeForFingerprint($implementation);
            }
            ksort($entries);
            $normalizedContextual[$concrete] = $entries;
        }
        ksort($normalizedContextual);

        $payload = [
            'class_name' => $this->compiledClassName,
            'namespace' => $this->compiledNamespace,
            'options' => $this->fingerprintRelevantOptions(),
            'bindings' => $normalizedBindings,
            'aliases' => $aliases,
            'contextual' => $normalizedContextual,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Analyze the container configuration.
     */
    /**
     * @return array<string, mixed>
     */
private function analyzeContainer(): array
    {
        $bindings = $this->container->getBindings();
        $analysis = [
            'bindings' => [],
            'singletons' => [],
            'dependencies' => [],
            'complexity_scores' => [],
            'optimization_opportunities' => []
        ];

        foreach ($bindings as $abstract => $bindingInfo) {
            // Analyze each binding
            $bindingAnalysis = $this->analyzeBinding($abstract, $bindingInfo);
            $analysis['bindings'][$abstract] = $bindingAnalysis;

            // Track singletons
            if ($bindingInfo['shared'] ?? false) {
                $analysis['singletons'][] = $abstract;
            }

            // Analyze dependencies
            $dependencies = $this->container->getDependencies($abstract);
            $analysis['dependencies'][$abstract] = $dependencies['dependencies'] ?? [];

            // Calculate complexity score
            $complexity = $this->calculateComplexityScore($abstract, $bindingAnalysis, $dependencies);
            $analysis['complexity_scores'][$abstract] = $complexity;

            // Identify optimization opportunities
            $optimizations = $this->identifyOptimizations($abstract, $bindingAnalysis, $complexity);
            if (!empty($optimizations)) {
                $analysis['optimization_opportunities'][$abstract] = $optimizations;
            }
        }

        return $analysis;
    }

    /**
     * Analyze a single binding.
      * @param array<string, mixed> $bindingInfo
      * @return array<string, mixed>
     */
    private function analyzeBinding(string $abstract, array $bindingInfo): array
    {
        return [
            'type' => $this->determineBindingType($abstract, $bindingInfo),
            'shared' => $bindingInfo['shared'] ?? false,
            'has_tags' => !empty($bindingInfo['tags'] ?? []),
            'has_decorators' => $bindingInfo['has_decorators'] ?? false,
            'has_contextual' => $bindingInfo['has_contextual'] ?? false,
            'complexity' => $this->calculateBindingComplexity($bindingInfo),
            'can_inline' => $this->canInlineBinding($abstract, $bindingInfo)
        ];
    }

    /**
     * Determine the type of binding.
      * @param array<string, mixed> $bindingInfo
     */
    private function determineBindingType(string $abstract, array $bindingInfo): string
    {
        if (class_exists($abstract)) {
            return 'class';
        }
        
        if (interface_exists($abstract)) {
            return 'interface';
        }

        return 'custom';
    }

    /**
     * Calculate complexity score for a binding.
      * @param array<string, mixed> $bindingAnalysis
      * @param array<string> $dependencies
     */
    private function calculateComplexityScore(string $abstract, array $bindingAnalysis, array $dependencies): int
    {
        $score = 0;

        // Base complexity
        $score += count($dependencies);

        // Additional complexity factors
        if ($bindingAnalysis['has_decorators']) $score += 5;
        if ($bindingAnalysis['has_contextual']) $score += 3;
        if ($bindingAnalysis['has_tags']) $score += 1;

        // Nested dependency complexity
        foreach ($dependencies as $dep) {
            if (isset($dep['dependencies']) && is_array($dep['dependencies'])) {
                $score += count($dep['dependencies']);
            }
        }

        return $score;
    }

    /**
     * Calculate binding complexity.
      * @param array<string, mixed> $bindingInfo
     */
    private function calculateBindingComplexity(array $bindingInfo): string
    {
        $score = 0;
        
        if ($bindingInfo['has_decorators'] ?? false) $score += 3;
        if ($bindingInfo['has_contextual'] ?? false) $score += 2;
        if ($bindingInfo['has_tags'] ?? false) $score += 1;

        return match (true) {
            $score === 0 => 'simple',
            $score <= 2 => 'moderate',
            default => 'complex'
        };
    }

    /**
     * Check if a binding can be inlined for optimization.
      * @param array<string, mixed> $bindingInfo
     */
    private function canInlineBinding(string $abstract, array $bindingInfo): bool
    {
        // Don't inline complex bindings
        if (($bindingInfo['has_decorators'] ?? false) || ($bindingInfo['has_contextual'] ?? false)) {
            return false;
        }

        // Only inline simple class bindings
        return class_exists($abstract) && empty($bindingInfo['tags'] ?? []);
    }

    /**
     * Identify optimization opportunities.
      * @param array<string, mixed> $bindingAnalysis
      * @return array<array<string, mixed>>
     */
    private function identifyOptimizations(string $abstract, array $bindingAnalysis, int $complexity): array
    {
        $optimizations = [];

        if ($complexity > 10) {
            $optimizations[] = 'high_complexity';
        }

        if ($bindingAnalysis['can_inline'] && $this->options['inline_simple_bindings']) {
            $optimizations[] = 'can_inline';
        }

        if ($bindingAnalysis['shared'] && $this->options['optimize_singletons']) {
            $optimizations[] = 'optimize_singleton';
        }

        return $optimizations;
    }

    /**
     * Generate the compiled container code.
      * @param array<string, mixed> $analysis
     */
    private function generateCompiledContainer(array $analysis, string $fingerprint): string
    {
        $compiledPlan = $this->buildCompiledFactoryPlan($analysis);

        $code = "<?php\n\n";
        $code .= "declare(strict_types=1);\n\n";
        $code .= "namespace {$this->compiledNamespace};\n\n";
        $code .= "use Closure;\n";
        $code .= "use CFXP\\Core\\Container\\Container;\n";
        $code .= "use CFXP\\Core\\Container\\ContainerInterface;\n\n";

        if ($this->options['include_debug_info']) {
            $code .= "/**\n";
            $code .= " * Compiled container generated on " . date('Y-m-d H:i:s') . "\n";
            $code .= " * Total bindings: " . count($analysis['bindings']) . "\n";
            $code .= " * Singletons: " . count($analysis['singletons']) . "\n";
            $code .= " * Optimization opportunities: " . count($analysis['optimization_opportunities']) . "\n";
            $code .= " */\n";
        }

        $generatedAt = date(DATE_ATOM);
        $totalBindings = count($analysis['bindings']);
        $optimizedBindings = count($compiledPlan['binding_plans']);
        $optimizedClasses = count($compiledPlan['class_plans']);
        $totalAliases = count($compiledPlan['alias_plans']);
        $contextualBindings = count($compiledPlan['contextual_plans']);

        $code .= "final class {$this->compiledClassName} extends Container\n{\n";
        $code .= "    public const GENERATED_AT = '{$generatedAt}';\n";
        $code .= "    public const FINGERPRINT = '{$fingerprint}';\n";
        $code .= "    public const TOTAL_BINDINGS = {$totalBindings};\n";
        $code .= "    public const OPTIMIZED_BINDINGS = {$optimizedBindings};\n";
        $code .= "    public const OPTIMIZED_CLASSES = {$optimizedClasses};\n\n";
        $code .= "    public const TOTAL_ALIASES = {$totalAliases};\n";
        $code .= "    public const TOTAL_CONTEXTUAL_BINDINGS = {$contextualBindings};\n\n";
        $code .= $this->renderCompiledBindingsMap(
            $compiledPlan['binding_plans'],
            $compiledPlan['class_plans']
        );
        $code .= "\n";
        $code .= "    public function __construct()\n    {\n";
        $code .= "        parent::__construct();\n";
        $code .= "        \$this->registerCompiledBindings();\n";
        $code .= "    }\n\n";
        $code .= $this->generateOptimizedBindOverride();
        $code .= $this->generateRegisterCompiledBindingsMethod(
            $compiledPlan['binding_plans'],
            $compiledPlan['class_plans'],
            $compiledPlan['alias_plans'],
            $compiledPlan['contextual_plans']
        );
        $code .= $this->generateFactoryMethodsFromPlan($compiledPlan['class_plans']);
        $code .= "}\n";

        return $code;
    }

    /**
     * @param array<string, mixed> $analysis
     * @return array{
     *   binding_plans: array<int, array{abstract: string, concrete: string, shared: bool, method: string}>,
     *   class_plans: array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}>,
     *   alias_plans: array<string, string>,
     *   contextual_plans: array<int, array{concrete: string, abstract: string, type: string, implementation: mixed}>
     * }
     */
    private function buildCompiledFactoryPlan(array $analysis): array
    {
        /** @var array<string, array{concrete: mixed, shared: bool}> $rawBindings */
        $rawBindings = $this->extractRawBindings();
        $aliasPlans = $this->extractRawAliases();
        $rawContextualBindings = $this->extractRawContextualBindings();

        $bindingPlans = [];
        $classPlans = [];
        $visiting = [];

        foreach ($rawBindings as $abstract => $binding) {
            if (!$this->canCompileBinding($abstract, $analysis)) {
                continue;
            }

            $concreteClass = $this->extractConcreteClass($abstract, $binding['concrete']);
            if ($concreteClass === null) {
                continue;
            }

            if (!$this->compileClassFactoryPlan($concreteClass, $classPlans, $visiting)) {
                continue;
            }

            $bindingPlans[] = [
                'abstract' => $abstract,
                'concrete' => $concreteClass,
                'shared' => (bool) ($binding['shared'] ?? false),
                'method' => $classPlans[$concreteClass]['method'],
            ];
        }

        $contextualPlans = $this->buildContextualPlans($rawContextualBindings, $classPlans, $visiting);

        return [
            'binding_plans' => $bindingPlans,
            'class_plans' => $classPlans,
            'alias_plans' => $aliasPlans,
            'contextual_plans' => $contextualPlans,
        ];
    }

    /**
     * @return array<string, array{concrete: mixed, shared: bool}>
     */
    private function extractRawBindings(): array
    {
        $reflection = new \ReflectionClass(Container::class);
        $property = $reflection->getProperty('bindings');
        $property->setAccessible(true);

        $bindings = $property->getValue($this->container);
        return is_array($bindings) ? $bindings : [];
    }

    /**
     * @return array<string, string>
     */
    private function extractRawAliases(): array
    {
        $reflection = new \ReflectionClass(Container::class);
        $property = $reflection->getProperty('aliases');
        $property->setAccessible(true);

        $aliases = $property->getValue($this->container);
        if (!is_array($aliases)) {
            return [];
        }

        $result = [];
        foreach ($aliases as $alias => $abstract) {
            if (!is_string($alias) || !is_string($abstract)) {
                continue;
            }

            $result[$alias] = $abstract;
        }

        ksort($result);
        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function extractRawContextualBindings(): array
    {
        $containerReflection = new \ReflectionClass(Container::class);
        $advancedFeaturesProperty = $containerReflection->getProperty('advancedFeaturesInitialized');
        $advancedFeaturesProperty->setAccessible(true);

        if (!$advancedFeaturesProperty->getValue($this->container)) {
            return [];
        }

        $contextualProperty = $containerReflection->getProperty('contextualBindings');
        $contextualProperty->setAccessible(true);

        if (!$contextualProperty->isInitialized($this->container)) {
            return [];
        }

        $contextualManager = $contextualProperty->getValue($this->container);
        if (!is_object($contextualManager) || !method_exists($contextualManager, 'getBindings')) {
            return [];
        }

        $raw = $contextualManager->getBindings();
        if (!is_array($raw)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $raw */
        return $raw;
    }

    /**
     * @param array<string, mixed> $analysis
     */
    private function canCompileBinding(string $abstract, array $analysis): bool
    {
        $bindingInfo = $analysis['bindings'][$abstract] ?? null;
        if (!is_array($bindingInfo)) {
            return false;
        }

        if (($bindingInfo['has_decorators'] ?? false) || ($bindingInfo['has_contextual'] ?? false)) {
            return false;
        }

        return empty($bindingInfo['has_tags'] ?? false);
    }

    private function extractConcreteClass(string $abstract, mixed $concrete): ?string
    {
        if (is_string($concrete) && class_exists($concrete)) {
            return ltrim($concrete, '\\');
        }

        if (!$concrete instanceof Closure) {
            return null;
        }

        $reflection = new \ReflectionFunction($concrete);
        if ($reflection->getNumberOfParameters() !== 0) {
            return null;
        }

        $staticVariables = $reflection->getStaticVariables();
        $resolved = $staticVariables['concrete'] ?? null;

        if (is_string($resolved) && class_exists($resolved)) {
            return ltrim($resolved, '\\');
        }

        $returnType = $reflection->getReturnType();
        if ($returnType instanceof \ReflectionNamedType && !$returnType->isBuiltin()) {
            $typeName = $returnType->getName();
            if (class_exists($typeName)) {
                return ltrim($typeName, '\\');
            }
        }

        return null;
    }

    /**
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     * @param array<string, bool> $visiting
     */
    private function compileClassFactoryPlan(string $class, array &$classPlans, array &$visiting): bool
    {
        $class = ltrim($class, '\\');

        if (isset($classPlans[$class])) {
            return true;
        }

        if (isset($visiting[$class])) {
            return false;
        }

        if (!class_exists($class)) {
            return false;
        }

        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            return false;
        }

        $visiting[$class] = true;
        $arguments = $this->compileConstructorArguments($reflection, $classPlans, $visiting);
        unset($visiting[$class]);

        if ($arguments === null) {
            return false;
        }

        $classPlans[$class] = [
            'class' => $class,
            'method' => $this->factoryMethodName($class),
            'arguments' => $arguments,
        ];

        return true;
    }

    /**
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     * @param array<string, bool> $visiting
     * @return array<int, array{kind: string, value: mixed}>|null
     */
    private function compileConstructorArguments(
        \ReflectionClass $reflection,
        array &$classPlans,
        array &$visiting
    ): ?array {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return [];
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $service = ltrim($type->getName(), '\\');

                if (class_exists($service)) {
                    $this->compileClassFactoryPlan($service, $classPlans, $visiting);
                }

                $arguments[] = ['kind' => 'service', 'value' => $service];
                continue;
            }

            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = ['kind' => 'literal', 'value' => $parameter->getDefaultValue()];
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $arguments[] = ['kind' => 'literal', 'value' => null];
                    continue;
                }

                return null;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = ['kind' => 'literal', 'value' => $parameter->getDefaultValue()];
                continue;
            }

            if ($parameter->allowsNull()) {
                $arguments[] = ['kind' => 'literal', 'value' => null];
                continue;
            }

            return null;
        }

        return $arguments;
    }

    /**
     * @param array<string, array<string, mixed>> $rawContextualBindings
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     * @param array<string, bool> $visiting
     * @return array<int, array{concrete: string, abstract: string, type: string, implementation: mixed}>
     */
    private function buildContextualPlans(array $rawContextualBindings, array &$classPlans, array &$visiting): array
    {
        $plans = [];

        foreach ($rawContextualBindings as $concrete => $definitions) {
            if (!is_string($concrete) || !is_array($definitions)) {
                continue;
            }

            foreach ($definitions as $abstract => $implementation) {
                if (!is_string($abstract)) {
                    continue;
                }

                if (is_string($implementation)) {
                    $normalized = ltrim($implementation, '\\');
                    if (class_exists($normalized)) {
                        $this->compileClassFactoryPlan($normalized, $classPlans, $visiting);
                    }

                    $plans[] = [
                        'concrete' => ltrim($concrete, '\\'),
                        'abstract' => ltrim($abstract, '\\'),
                        'type' => 'class',
                        'implementation' => $normalized,
                    ];
                    continue;
                }

                if (!is_array($implementation)) {
                    continue;
                }

                if (isset($implementation['tagged']) && is_string($implementation['tagged'])) {
                    $plans[] = [
                        'concrete' => ltrim($concrete, '\\'),
                        'abstract' => ltrim($abstract, '\\'),
                        'type' => 'tagged',
                        'implementation' => $implementation['tagged'],
                    ];
                    continue;
                }

                if (isset($implementation['configured']) && is_array($implementation['configured'])) {
                    $plans[] = [
                        'concrete' => ltrim($concrete, '\\'),
                        'abstract' => ltrim($abstract, '\\'),
                        'type' => 'configured',
                        'implementation' => $implementation['configured'],
                    ];
                }
            }
        }

        return $plans;
    }

    /**
     * @param array<int, array{abstract: string, concrete: string, shared: bool, method: string}> $bindingPlans
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     */
    private function renderCompiledBindingsMap(array $bindingPlans, array $classPlans): string
    {
        $map = [];

        foreach ($classPlans as $class => $plan) {
            $map[$class . '|' . $class] = $plan['method'];
        }

        foreach ($bindingPlans as $plan) {
            $map[$plan['abstract'] . '|' . $plan['concrete']] = $plan['method'];
        }

        ksort($map);

        $code = "    private const COMPILED_BINDINGS = [\n";
        foreach ($map as $key => $method) {
            $escapedKey = addslashes($key);
            $escapedMethod = addslashes($method);
            $code .= "        '{$escapedKey}' => '{$escapedMethod}',\n";
        }
        $code .= "    ];\n";

        return $code;
    }

    private function generateOptimizedBindOverride(): string
    {
        return <<<'PHP'
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void
    {
        $normalizedConcrete = $concrete ?? $abstract;

        if (is_string($normalizedConcrete)) {
            $key = $abstract . '|' . ltrim($normalizedConcrete, '\\');

            if (isset(self::COMPILED_BINDINGS[$key])) {
                $method = self::COMPILED_BINDINGS[$key];
                parent::bind(
                    $abstract,
                    function (ContainerInterface $container) use ($method): mixed {
                        return $this->{$method}();
                    },
                    $shared
                );
                return;
            }
        }

        parent::bind($abstract, $concrete, $shared);
    }

PHP;
    }

    /**
     * @param array<int, array{abstract: string, concrete: string, shared: bool, method: string}> $bindingPlans
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     * @param array<string, string> $aliasPlans
     * @param array<int, array{concrete: string, abstract: string, type: string, implementation: mixed}> $contextualPlans
     */
    private function generateRegisterCompiledBindingsMethod(
        array $bindingPlans,
        array $classPlans,
        array $aliasPlans,
        array $contextualPlans
    ): string {
        $code = "    private function registerCompiledBindings(): void\n    {\n";

        $boundAbstracts = [];
        foreach ($bindingPlans as $plan) {
            $boundAbstracts[$plan['abstract']] = true;
        }

        foreach ($classPlans as $class => $plan) {
            if (isset($boundAbstracts[$class])) {
                continue;
            }

            $escapedClass = addslashes($class);
            $method = $plan['method'];
            $code .= "        parent::bind('{$escapedClass}', function (ContainerInterface \$container): mixed {\n";
            $code .= "            return \$this->{$method}();\n";
            $code .= "        }, false);\n";
        }

        foreach ($bindingPlans as $plan) {
            $escapedAbstract = addslashes($plan['abstract']);
            $method = $plan['method'];
            $shared = $plan['shared'] ? 'true' : 'false';
            $code .= "        parent::bind('{$escapedAbstract}', function (ContainerInterface \$container): mixed {\n";
            $code .= "            return \$this->{$method}();\n";
            $code .= "        }, {$shared});\n";
        }

        foreach ($aliasPlans as $alias => $abstract) {
            $escapedAlias = addslashes($alias);
            $escapedAbstract = addslashes($abstract);
            $code .= "        parent::alias('{$escapedAlias}', '{$escapedAbstract}');\n";
        }

        foreach ($contextualPlans as $plan) {
            $escapedConcrete = addslashes($plan['concrete']);
            $escapedAbstract = addslashes($plan['abstract']);

            if ($plan['type'] === 'class' && is_string($plan['implementation'])) {
                $escapedImplementation = addslashes($plan['implementation']);
                $code .= "        \$this->when('{$escapedConcrete}')->needs('{$escapedAbstract}')->give('{$escapedImplementation}');\n";
                continue;
            }

            if ($plan['type'] === 'tagged' && is_string($plan['implementation'])) {
                $escapedTag = addslashes($plan['implementation']);
                $code .= "        \$this->when('{$escapedConcrete}')->needs('{$escapedAbstract}')->giveTagged('{$escapedTag}');\n";
                continue;
            }

            if ($plan['type'] === 'configured' && is_array($plan['implementation'])) {
                $configuration = var_export($plan['implementation'], true);
                $configuration = str_replace("\n", "\n            ", $configuration);
                $code .= "        \$this->when('{$escapedConcrete}')->needs('{$escapedAbstract}')->giveConfigured(\n";
                $code .= "            {$configuration}\n";
                $code .= "        );\n";
            }
        }

        $code .= "    }\n\n";

        return $code;
    }

    /**
     * @param array<string, array{class: string, method: string, arguments: array<int, array{kind: string, value: mixed}>}> $classPlans
     */
    private function generateFactoryMethodsFromPlan(array $classPlans): string
    {
        $code = '';
        ksort($classPlans);

        foreach ($classPlans as $plan) {
            $method = $plan['method'];
            $class = '\\' . ltrim($plan['class'], '\\');

            $code .= "    private function {$method}(): mixed\n    {\n";

            if ($plan['arguments'] === []) {
                $code .= "        return new {$class}();\n";
                $code .= "    }\n\n";
                continue;
            }

            $code .= "        return new {$class}(\n";
            foreach ($plan['arguments'] as $argument) {
                $code .= "            " . $this->renderFactoryArgument($argument) . ",\n";
            }
            $code .= "        );\n";
            $code .= "    }\n\n";
        }

        return $code;
    }

    /**
     * @param array{kind: string, value: mixed} $argument
     */
    private function renderFactoryArgument(array $argument): string
    {
        if ($argument['kind'] === 'service' && is_string($argument['value'])) {
            $service = addslashes($argument['value']);
            return "\$this->get('{$service}')";
        }

        return var_export($argument['value'], true);
    }

    private function factoryMethodName(string $class): string
    {
        return 'create_' . substr(sha1(ltrim($class, '\\')), 0, 12);
    }

    /**
     * Generate optimized get method.
      * @param array<string, mixed> $analysis
     */
    private function generateOptimizedGetMethod(array $analysis): string
    {
        $code = "    public function get(string \$abstract): mixed\n    {\n";
        $code .= "        return match (\$abstract) {\n";

        foreach ($analysis['bindings'] as $abstract => $bindingAnalysis) {
            if (in_array('can_inline', $analysis['optimization_opportunities'][$abstract] ?? [])) {
                $code .= $this->generateInlineBinding($abstract, $bindingAnalysis);
            } elseif (in_array('optimize_singleton', $analysis['optimization_opportunities'][$abstract] ?? [])) {
                $code .= $this->generateOptimizedSingleton($abstract);
            } else {
                $code .= "            '{$abstract}' => \$this->create" . $this->sanitizeClassName($abstract) . "(),\n";
            }
        }

        $code .= "            default => parent::get(\$abstract)\n";
        $code .= "        };\n";
        $code .= "    }\n\n";

        return $code;
    }

    /**
     * Generate inline binding code.
      * @param array<string, mixed> $bindingAnalysis
     */
    private function generateInlineBinding(string $abstract, array $bindingAnalysis): string
    {
        if (class_exists($abstract)) {
            return "            '{$abstract}' => new {$abstract}(),\n";
        }

        return "            '{$abstract}' => \$this->create" . $this->sanitizeClassName($abstract) . "(),\n";
    }

    /**
     * Generate optimized singleton resolution.
     */
    private function generateOptimizedSingleton(string $abstract): string
    {
        $methodName = 'create' . $this->sanitizeClassName($abstract);
        
        return "            '{$abstract}' => \$this->singletonInstances['{$abstract}'] ??= \$this->{$methodName}(),\n";
    }

    /**
     * Generate factory methods for complex bindings.
      * @param array<string, mixed> $analysis
     */
    private function generateFactoryMethods(array $analysis): string
    {
        $code = "";

        foreach ($analysis['bindings'] as $abstract => $bindingAnalysis) {
            if (!in_array('can_inline', $analysis['optimization_opportunities'][$abstract] ?? [])) {
                $code .= $this->generateFactoryMethod($abstract, $bindingAnalysis, $analysis);
            }
        }

        return $code;
    }

    /**
     * Generate a factory method for a specific binding.
      * @param array<string, mixed> $bindingAnalysis
      * @param array<string, mixed> $analysis
     */
    private function generateFactoryMethod(string $abstract, array $bindingAnalysis, array $analysis): string
    {
        $methodName = 'create' . $this->sanitizeClassName($abstract);
        $dependencies = $analysis['dependencies'][$abstract] ?? [];

        $code = "    private function {$methodName}(): mixed\n    {\n";

        if (empty($dependencies)) {
            $code .= "        return new {$abstract}();\n";
        } else {
            $code .= "        return new {$abstract}(\n";
            
            foreach ($dependencies as $dependency) {
                $depType = $dependency['type'] ?? 'mixed';
                $code .= "            \$this->get('{$depType}'),\n";
            }
            
            $code .= "        );\n";
        }

        $code .= "    }\n\n";

        return $code;
    }

    /**
     * Generate validation method.
      * @param array<string, mixed> $analysis
     */
    private function generateValidationMethod(array $analysis): string
    {
        $code = "    public function validateCompilation(): bool\n    {\n";
        $code .= "        // Validate that all required classes exist\n";
        $code .= "        \$requiredClasses = [\n";

        foreach (array_keys($analysis['bindings']) as $abstract) {
            if (class_exists($abstract)) {
                $code .= "            '{$abstract}',\n";
            }
        }

        $code .= "        ];\n\n";
        $code .= "        foreach (\$requiredClasses as \$class) {\n";
        $code .= "            if (!class_exists(\$class)) {\n";
        $code .= "                throw new ContainerResolutionException(\"Required class '{\$class}' not found in compiled container\");\n";
        $code .= "            }\n";
        $code .= "        }\n\n";
        $code .= "        return true;\n";
        $code .= "    }\n\n";

        return $code;
    }

    /**
     * Write compiled container to file.
     */
    private function writeCompiledContainer(string $outputPath, string $code): void
    {
        $directory = dirname($outputPath);
        
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                throw new ContainerResolutionException(
                    "Cannot create directory '{$directory}' for compiled container",
                    null,
                    null,
                    ['Check directory permissions', 'Ensure parent directories exist']
                );
            }
        }

        $lockPath = $outputPath . '.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            throw new ContainerResolutionException(
                "Cannot acquire compilation lock for '{$outputPath}'",
                null,
                null,
                ['Check directory permissions', 'Ensure lock file can be created']
            );
        }

        $temporaryPath = $directory . '/.' . basename($outputPath) . '.' . uniqid('tmp', true);

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new ContainerResolutionException(
                    "Cannot lock compiled container output '{$outputPath}'",
                    null,
                    null,
                    ['Ensure filesystem supports locks', 'Check directory permissions']
                );
            }

            if (file_put_contents($temporaryPath, $code) === false) {
                throw new ContainerResolutionException(
                    "Cannot write compiled container to temporary file '{$temporaryPath}'",
                    null,
                    null,
                    ['Check file permissions', 'Ensure directory is writable', 'Verify disk space']
                );
            }

            chmod($temporaryPath, 0644);

            if (!rename($temporaryPath, $outputPath)) {
                throw new ContainerResolutionException(
                    "Cannot finalize compiled container write to '{$outputPath}'",
                    null,
                    null,
                    ['Ensure destination path is writable', 'Verify filesystem supports atomic rename']
                );
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Normalize values for deterministic fingerprinting.
     */
    private function normalizeForFingerprint(mixed $value): mixed
    {
        if ($value instanceof Closure) {
            $reflection = new \ReflectionFunction($value);

            return [
                'type' => 'closure',
                'file' => $reflection->getFileName() ?: null,
                'start' => $reflection->getStartLine(),
                'end' => $reflection->getEndLine(),
                'return_type' => $reflection->hasReturnType() ? (string) $reflection->getReturnType() : null,
                'static' => $this->normalizeForFingerprint($reflection->getStaticVariables()),
            ];
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string) $key] = $this->normalizeForFingerprint($item);
            }
            ksort($normalized);
            return $normalized;
        }

        if (is_object($value)) {
            return ['type' => 'object', 'class' => $value::class];
        }

        if (is_resource($value)) {
            return ['type' => 'resource', 'resource' => get_resource_type($value)];
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function fingerprintRelevantOptions(): array
    {
        $relevant = $this->options;
        unset($relevant['validate_bindings']);
        ksort($relevant);

        return $relevant;
    }

    /**
     * Sanitize class name for method generation.
     */
    private function sanitizeClassName(string $className): string
    {
        // Remove namespace separators and other invalid characters
        $sanitized = str_replace(['\\', '/', '-', '.'], '', $className);
        
        // Ensure it starts with a letter
        if (!preg_match('/^[a-zA-Z]/', $sanitized)) {
            $sanitized = 'Class' . $sanitized;
        }

        return $sanitized;
    }

    /**
     * Get compilation statistics.
      * @return array<string, mixed>
     */
    public function getCompilationStats(): array
    {
        $analysis = $this->analyzeContainer();
        $plan = $this->buildCompiledFactoryPlan($analysis);
        
        return [
            'total_bindings' => count($analysis['bindings']),
            'singletons' => count($analysis['singletons']),
            'aliases' => count($plan['alias_plans']),
            'contextual_bindings' => count($plan['contextual_plans']),
            'fingerprint' => $this->fingerprint(),
            'optimization_opportunities' => array_sum(array_map('count', $analysis['optimization_opportunities'])),
            'inlinable_bindings' => count(array_filter($analysis['optimization_opportunities'], 
                fn($opts) => in_array('can_inline', $opts))),
            'complex_bindings' => count(array_filter($analysis['bindings'], 
                fn($binding) => $binding['complexity'] === 'complex')),
            'average_complexity' => count($analysis['bindings']) > 0 
                ? array_sum($analysis['complexity_scores']) / count($analysis['bindings']) 
                : 0
        ];
    }
}
