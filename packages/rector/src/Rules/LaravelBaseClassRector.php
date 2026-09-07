<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer;
use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Configuration\BaseClassConfiguration;
use Laratesto\Rector\Configuration\ConfiguredHierarchy;
use Laratesto\Rector\Residuals\ResidualCode;
use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\BetterReflection\Reflector\DefaultReflector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTagRemover;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Reflection\BetterReflection\SourceLocatorProvider\DynamicSourceLocatorProvider;
use Rector\PhpParser\AstResolver;
use Rector\Rector\AbstractRector;
use Rector\Skipper\FileSystem\PathNormalizer;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Atomically converts an eligible Laravel PHPUnit class to one of the two
 * Laratesto targets. A failed preflight adds a residual marker and deliberately
 * leaves the class hierarchy, lifecycle and `$this->app` expressions untouched.
 */
#[TestRectorFixtures('LaravelBaseClassRector')]
final class LaravelBaseClassRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const BASE_CLASSES = 'base_classes';

    public const TARGET_MODE = 'target_mode';

    public const TARGET_MODE_BASE_CLASS = 'base_class';

    public const TARGET_MODE_TRAIT = 'trait';

    /** @var list<non-empty-string> */
    public const DEFAULT_BASE_CLASSES = BaseClassConfiguration::DEFAULT_BASE_CLASSES;

    private const FRAMEWORK_BASE = 'Illuminate\Foundation\Testing\TestCase';

    private const TARGET_BASE = ConfiguredHierarchy::TARGET_BASE;

    /**
     * Guard against cyclic or pathologically deep extends chains: anything deeper is
     * reported as an unsafe hierarchy instead of being converted.
     */
    private const MAX_CHAIN_DEPTH = 10;

    /**
     * Residual codes that make conversion impossible wherever they appear:
     * refactor() skips any class carrying one of these markers, so a project
     * base marked with any of them will never convert in any run.
     */
    private const BLOCKING_RESIDUAL_CODES = [
        ResidualCode::CLASS_UNSAFE_HIERARCHY,
        ResidualCode::LIFECYCLE_UNSUPPORTED,
        ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
        ResidualCode::HTTP_UNSUPPORTED_SIGNATURE,
        ResidualCode::RESPONSE_UNSUPPORTED_API,
        ResidualCode::ARTISAN_INTERACTION_UNSUPPORTED,
    ];

    private const TEST_ATTRIBUTE = 'Testo\Test';

    private const PHPUNIT_TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    /**
     * Laravel/PHPUnit boot hooks that the Laratesto application factory does not call.
     * Keeping such a method while replacing the parent would silently drop behavior.
     */
    private const UNSUPPORTED_BOOTSTRAP_METHODS = [
        'createApplication',
        'getPackageProviders',
        'getPackageAliases',
        'getEnvironmentSetUp',
        'defineEnvironment',
        'resolveApplication',
        'afterApplicationCreated',
        'beforeApplicationDestroyed',
    ];

    /**
     * strtolower(UNSUPPORTED_BOOTSTRAP_METHODS): PHP method names are
     * case-insensitive, so the comparison surface is normalized once here.
     */
    private const UNSUPPORTED_BOOTSTRAP_METHODS_LOWER = [
        'createapplication',
        'getpackageproviders',
        'getpackagealiases',
        'getenvironmentsetup',
        'defineenvironment',
        'resolveapplication',
        'afterapplicationcreated',
        'beforeapplicationdestroyed',
    ];

    /**
     * One stable reason for every $this->app write/reference context: the
     * rewritten app() method result is a temporary and cannot take the place of
     * the writable property.
     */
    private const APP_WRITE_CONTEXT_REASON = '$this->app is used in a write context (assignment/isset/unset/reference) that cannot become an app() method call';

    private BaseClassConfiguration $configuration;

    /** @var \WeakMap<object, list<ClassLike>> */
    private \WeakMap $traitFrontierSnapshots;

    public function __construct(
        private readonly AstResolver $astResolver,
        private readonly ReflectionProvider $reflectionProvider,
        private readonly DynamicSourceLocatorProvider $sourceLocatorProvider,
        private readonly ConfiguredHierarchy $hierarchy,
        private readonly DatabaseConfigurationAnalyzer $databaseAnalyzer,
        private readonly HttpCompatibilityAnalyzer $httpAnalyzer,
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly PhpDocTagRemover $phpDocTagRemover,
        private readonly DocBlockUpdater $docBlockUpdater,
    ) {
        $this->configuration = BaseClassConfiguration::defaults();
        $this->traitFrontierSnapshots = new \WeakMap();
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Atomically convert a Laravel PHPUnit base class and exactly-once lifecycle to Laratesto',
            [
                new CodeSample(
                    <<<'PHP'
                        final class UsersTest extends \Illuminate\Foundation\Testing\TestCase
                        {
                            protected function setUp(): void
                            {
                                parent::setUp();
                            }
                        }
                        PHP,
                    <<<'PHP'
                        final class UsersTest extends \Laratesto\Testing\LaravelTestCase
                        {
                            protected function setUpLaravel(): void
                            {
                            }
                        }
                        PHP,
                ),
            ],
        );
    }

    #[\Override]
    public function getNodeTypes(): array
    {
        // File-global import rewrites are intentionally forbidden: a file may contain
        // both eligible and residual classes. FQ targets keep those classes isolated.
        return [Class_::class];
    }

    #[\Override]
    public function configure(array $configuration): void
    {
        // One fresh value object per call: keys absent from $configuration fall back to
        // the defaults, so a previous configuration can never leak through the shared
        // singleton instance (see BaseClassConfiguration for the contract). The fresh
        // state is also pushed into the shared ConfiguredHierarchy, so the configured
        // base classes govern every Laravel rule at once, not only this one.
        $this->configuration = BaseClassConfiguration::fromArray($configuration);
        $this->hierarchy->adopt($this->configuration);
    }

    /**
     * @param Class_ $node
     */
    #[\Override]
    public function refactor(Node $node): ?Node
    {
        if ($node->extends === null) {
            return null;
        }

        if ($this->isName($node->extends, self::TARGET_BASE)) {
            return null;
        }

        if (! $this->isNames($node->extends, $this->hierarchy->sourceBases())) {
            return null;
        }

        if ($this->hasBlockingMarker($node)) {
            return null;
        }

        $changed = false;
        [$kind, $chainFailure] = $this->classifyHierarchy($node);

        $hierarchyFailures = $this->conversionGateFailures($node);
        if ($chainFailure !== null) {
            $hierarchyFailures[] = $chainFailure;
        }

        $lifecycleFailures = $this->lifecycleFailures($node);
        $appFailures = $this->appFailures($node);
        $databaseAnalysis = $this->databaseAnalyzer->analyze($node, $this->fileClasses());
        $httpAnalysis = $this->httpAnalyzer->analyze($node, $this->fileClasses());
        $httpFailures = array_values(array_unique([
            ...$appFailures,
            ...($httpAnalysis->reasonsByCode[ResidualCode::HTTP_UNSUPPORTED_SIGNATURE] ?? []),
        ]));

        if ($hierarchyFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                ResidualCode::CLASS_UNSAFE_HIERARCHY,
                static::class,
                implode('; ', $hierarchyFailures),
            ) || $changed;
        }

        if ($lifecycleFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                ResidualCode::LIFECYCLE_UNSUPPORTED,
                static::class,
                implode('; ', $lifecycleFailures),
            ) || $changed;
        }

        if ($httpFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                ResidualCode::HTTP_UNSUPPORTED_SIGNATURE,
                static::class,
                implode('; ', $httpFailures),
            ) || $changed;
        }

        foreach ([ResidualCode::RESPONSE_UNSUPPORTED_API, ResidualCode::ARTISAN_INTERACTION_UNSUPPORTED] as $code) {
            $reasons = $httpAnalysis->reasonsByCode[$code] ?? [];
            if ($reasons !== []) {
                $changed = ResidualMarker::mark(
                    $node,
                    $code,
                    LaravelSourceCompatibleCallsRector::class,
                    implode('; ', $reasons),
                ) || $changed;
            }
        }

        if ($databaseAnalysis->unsupportedReason !== null) {
            $changed = ResidualMarker::mark(
                $node,
                ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
                LaravelDatabaseTraitsRector::class,
                $databaseAnalysis->unsupportedReason,
            ) || $changed;
        }

        if ($hierarchyFailures !== []
            || $lifecycleFailures !== []
            || $httpAnalysis->reasonsByCode !== []
            || $appFailures !== []
            || $databaseAnalysis->unsupportedReason !== null) {
            return $changed ? $node : null;
        }

        \assert($kind !== null);

        return $this->convertClass($node, $kind);
    }

    /**
     * Decides how far this class may be rewritten: a direct framework child is
     * converted to the Laratesto base itself, while a project-base descendant keeps
     * its extends untouched - the project base is converted exactly once, and this
     * class only receives lifecycle, API and database conversions.
     *
     * Only classes whose direct parent is a configured base reach this point; other
     * hierarchies are simply not our business.
     *
     * @return array{('framework'|'descendant')|null, non-empty-string|null} kind + failure reason (null when safe)
     */
    private function classifyHierarchy(Class_ $class): array
    {
        $parentName = $this->getName($class->extends);

        if ($parentName === null) {
            return [null, 'the direct parent class name cannot be resolved'];
        }

        if ($parentName === self::FRAMEWORK_BASE) {
            return ['framework', null];
        }

        return $this->classifyDescendant($parentName);
    }

    /**
     * Walks the extends chain of a project base and proves that the whole
     * hierarchy converts in this run: every class on the chain is resolvable,
     * every project class on it is inside the processed paths and would pass
     * its own conversion gates ({@see baseConversionFailures}), and the chain
     * terminates at the Laravel framework base (or at an already-migrated
     * Laratesto base).
     *
     * @return array{('framework'|'descendant')|null, non-empty-string}
     */
    private function classifyDescendant(string $parentName): array
    {
        $seen = [];
        $current = $parentName;

        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current === self::FRAMEWORK_BASE || $current === self::TARGET_BASE) {
                // A migrated base (either target) keeps providing the Laratesto API to
                // this descendant.
                return ['descendant', null];
            }

            if (isset($seen[$current])) {
                return [null, sprintf('cyclic inheritance through %s cannot be classified', $current)];
            }

            $seen[$current] = true;

            [$parentClass, $fromCurrentFile] = $this->resolveClassNode($current);

            if (! $parentClass instanceof Class_) {
                return [null, sprintf('parent %s cannot be resolved - migrate the project base in the same run', $current)];
            }

            if (! $fromCurrentFile && ! $this->isWithinProcessedPaths($parentClass)) {
                return [null, sprintf(
                    'project base %s is outside the processed paths - migrate it in the same run first',
                    $current,
                )];
            }

            if (! $fromCurrentFile && $this->isExcludedBySkip($parentClass)) {
                return [null, sprintf(
                    'project base %s is excluded from this Rector run by the skip configuration',
                    $current,
                )];
            }

            if ($this->hierarchy->usesTargetTrait($parentClass)) {
                return ['descendant', null];
            }

            $failures = $this->baseConversionFailures($parentClass);

            if ($failures !== []) {
                return [null, sprintf('project base %s carries unsupported constructs: %s', $current, implode('; ', $failures))];
            }

            if ($parentClass->extends === null) {
                return [null, sprintf('parent %s does not extend a Laravel test base', $current)];
            }

            $next = $this->getName($parentClass->extends);

            if ($next === null || $next === $current) {
                return [null, sprintf('parent %s does not extend a resolvable Laravel test base', $current)];
            }

            if ($next === self::FRAMEWORK_BASE) {
                // The chain bottom must itself be eligible, or the whole hierarchy
                // would stay on PHPUnit under this descendant's Laratesto rewrite.
                if (! in_array(self::FRAMEWORK_BASE, $this->hierarchy->sourceBases(), true)) {
                    return [null, sprintf(
                        'project base %s extends the framework base, which is outside the configured base_classes',
                        $current,
                    )];
                }

                return ['descendant', null];
            }

            if ($next !== self::TARGET_BASE && ! in_array($next, $this->hierarchy->sourceBases(), true)) {
                return [null, sprintf(
                    'project base %s extends %s, which is outside the configured base_classes',
                    $current,
                    $next,
                )];
            }

            $current = $next;
        }

        return [null, sprintf('the extends chain is deeper than %d classes and cannot be classified safely', self::MAX_CHAIN_DEPTH)];
    }

    /**
     * Conversion gates that apply to the class itself in both kinds: the constructs
     * below are lost or broken by the lifecycle rewrite regardless of the parent.
     *
     * @return list<non-empty-string>
     */
    private function conversionGateFailures(Class_ $class): array
    {
        $failures = [
            ...$this->traitAdaptationFailures($class),
            ...$this->traitConversionFailures($class),
            ...$this->descendantTraitConversionFailures($class),
            ...$this->bootstrapMethodFailures($class),
        ];

        if ($this->configuration->targetMode === self::TARGET_MODE_TRAIT && $class->isAnonymous()) {
            $failures[] = 'trait target mode does not support anonymous test classes';
        }

        return $failures;
    }

    /** @return list<non-empty-string> */
    private function traitAdaptationFailures(Class_ $class): array
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse && $stmt->adaptations !== []) {
                return ['trait adaptations cannot be preserved by automatic class conversion'];
            }
        }

        return [];
    }

    /** @return list<non-empty-string> */
    private function bootstrapMethodFailures(Class_ $class): array
    {
        $failures = [];

        foreach ($class->getMethods() as $method) {
            $name = $this->getName($method->name);

            // PHP method names are case-insensitive: CreateApplication() provides
            // createApplication() all the same.
            if ($name !== null && in_array(strtolower($name), self::UNSUPPORTED_BOOTSTRAP_METHODS_LOWER, true)) {
                $failures[] = sprintf('custom bootstrap method %s() is not called by the Laratesto target', $name);
            }
        }

        $traitFailures = $this->traitProvidedLifecycleFailures($class);

        return array_values(array_unique([...$failures, ...$traitFailures['bootstrap']]));
    }

    /**
     * Lifecycle/bootstrap overrides provided by used traits are invisible to
     * Class_::getMethods(), yet they land in the class's method surface all the
     * same: after conversion the trait's `parent::setUp()` would target a base
     * that has no `setUp()`. Every used trait is resolved (same file, other
     * processed files, vendor) and its declared method names are checked,
     * recursively through nested trait uses; a trait that cannot be resolved
     * fails closed.
     *
     * @return array{lifecycle: list<non-empty-string>, bootstrap: list<non-empty-string>}
     */
    private function traitProvidedLifecycleFailures(Class_ $class): array
    {
        $lifecycle = [];
        $bootstrap = [];
        $seen = [];

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $traitName) {
                $this->collectTraitLifecycleFailures($traitName, $seen, $lifecycle, $bootstrap, 0);
            }
        }

        return ['lifecycle' => $lifecycle, 'bootstrap' => $bootstrap];
    }

    /**
     * @param array<string, true> $seen
     * @param list<non-empty-string> $lifecycle
     * @param list<non-empty-string> $bootstrap
     */
    private function collectTraitLifecycleFailures(
        Name $traitName,
        array &$seen,
        array &$lifecycle,
        array &$bootstrap,
        int $depth,
    ): void {
        if ($depth > self::MAX_CHAIN_DEPTH) {
            $lifecycle[] = sprintf(
                'the trait use chain is deeper than %d traits and cannot be classified safely',
                self::MAX_CHAIN_DEPTH,
            );

            return;
        }

        $traitClass = $this->getName($traitName);

        if ($traitClass === null) {
            $lifecycle[] = 'a used trait name cannot be resolved, so a lifecycle or bootstrap override it provides cannot be ruled out';

            return;
        }

        if (isset($seen[$traitClass])) {
            return;
        }

        $seen[$traitClass] = true;

        $trait = $this->resolveTraitNode($traitClass);

        if (! $trait instanceof Trait_) {
            $lifecycle[] = sprintf(
                'used trait %s cannot be resolved, so a lifecycle or bootstrap override it provides cannot be ruled out',
                $traitClass,
            );

            return;
        }

        foreach ($trait->getMethods() as $method) {
            $methodName = $this->getName($method->name);

            if ($methodName === null) {
                continue;
            }

            $lowerName = strtolower($methodName);

            if ($lowerName === 'setup' || $lowerName === 'teardown') {
                $lifecycle[] = sprintf(
                    'trait %s provides %s() which the conversion cannot rename safely',
                    $traitClass,
                    $methodName,
                );
            }

            if (in_array($lowerName, self::UNSUPPORTED_BOOTSTRAP_METHODS_LOWER, true)) {
                $bootstrap[] = sprintf(
                    'trait %s provides custom bootstrap method %s() which is not called by the Laratesto target',
                    $traitClass,
                    $methodName,
                );
            }
        }

        // Nested trait uses are followed so a lifecycle override a layer deep is
        // still caught. Adaptations inside a trait are not gated by the class-level
        // gate above - and an alias can CREATE a lifecycle/bootstrap name that no
        // declared method carries (`silent as tearDown`, `boot as createApplication`),
        // so each alias target name is checked like a declaration.
        foreach ($trait->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->adaptations as $adaptation) {
                if (! $adaptation instanceof TraitUseAdaptation\Alias
                    || ! $adaptation->newName instanceof Identifier) {
                    continue;
                }

                $newName = $adaptation->newName->toString();
                $lowerNewName = strtolower($newName);

                if ($lowerNewName === 'setup' || $lowerNewName === 'teardown') {
                    $lifecycle[] = sprintf(
                        'trait %s adapts a used trait method to %s(), which the conversion cannot rename safely',
                        $traitClass,
                        $newName,
                    );
                }

                if (in_array($lowerNewName, self::UNSUPPORTED_BOOTSTRAP_METHODS_LOWER, true)) {
                    $bootstrap[] = sprintf(
                        'trait %s adapts a used trait method to custom bootstrap method %s() which is not called by the Laratesto target',
                        $traitClass,
                        $newName,
                    );
                }
            }

            foreach ($stmt->traits as $nestedName) {
                $this->collectTraitLifecycleFailures($nestedName, $seen, $lifecycle, $bootstrap, $depth + 1);
            }
        }
    }

    /**
     * Class conversion never edits a shared trait. Prove that its discovery and
     * inherited Laravel/PHPUnit dependencies need no such edit before switching
     * the consumer's parent, including helpers and nested trait aliases.
     *
     * @return list<non-empty-string>
     */
    private function traitConversionFailures(Class_ $class): array
    {
        $lifecycle = $this->traitProvidedLifecycleFailures($class);
        if ($lifecycle['lifecycle'] !== [] || $lifecycle['bootstrap'] !== []) {
            // The existing lifecycle gate already preserves this consumer.
            return [];
        }

        $seen = [];
        $failures = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $name) {
                    $this->collectTraitConversionFailures($name, $seen, $failures, 0);
                }
            }
        }

        // Laravel calls setUp{TraitBasename}/tearDown{TraitBasename} for every
        // recursively used trait, even when another trait or the class provides it.
        $hooks = [];
        $providers = [$class];
        foreach (array_keys($seen) as $traitName) {
            if (isset(DatabaseConfigurationAnalyzer::TRAITS[$traitName])) {
                continue;
            }
            $shortName = substr($traitName, (int) strrpos('\\' . $traitName, '\\'));
            $hooks[] = strtolower('setUp' . $shortName);
            $hooks[] = strtolower('tearDown' . $shortName);
            $trait = $this->resolveTraitNode($traitName);
            if ($trait instanceof Trait_) {
                $providers[] = $trait;
            }
        }
        $providedMethods = [];
        foreach ($providers as $provider) {
            $methodNames = array_map(static fn(ClassMethod $method): string => $method->name->toString(), $provider->getMethods());
            foreach ($provider->stmts as $stmt) {
                if ($stmt instanceof TraitUse) {
                    foreach ($stmt->adaptations as $adaptation) {
                        if ($adaptation instanceof TraitUseAdaptation\Alias && $adaptation->newName instanceof Identifier) {
                            $methodNames[] = $adaptation->newName->toString();
                        }
                    }
                }
            }
            foreach ($methodNames as $methodName) {
                $providedMethods[strtolower($methodName)] = true;
                if (in_array(strtolower($methodName), $hooks, true)) {
                    $failures[] = sprintf('Laravel trait hook %s() is not called by the Laratesto target - migrate the hook manually before converting its consumer', $methodName);
                }
            }
        }
        foreach ($providers as $provider) {
            if (! $provider instanceof Trait_) {
                continue;
            }
            foreach ($provider->getMethods() as $method) {
                $reason = $this->traitMethodConversionReason($method, $providedMethods);
                if ($reason !== null) {
                    $failures[] = sprintf('trait %s method %s() %s - migrate the trait manually before converting its consumer', $this->getName($provider), $method->name->toString(), $reason);
                }
            }
        }

        return array_values(array_unique($failures));
    }

    /** @param array<string, true> $seen @param list<non-empty-string> $failures */
    private function collectTraitConversionFailures(Name $name, array &$seen, array &$failures, int $depth): void
    {
        $traitName = $this->getName($name);
        if ($traitName === null || isset($seen[$traitName]) || $depth > self::MAX_CHAIN_DEPTH) {
            // Resolution/depth failures are already covered by the lifecycle gate.
            return;
        }
        $seen[$traitName] = true;

        if (isset(DatabaseConfigurationAnalyzer::TRAITS[$traitName])) {
            // This declaration is removed by the database rule after its own
            // whole-hierarchy preflight; do not ban its framework implementation.
            return;
        }

        $trait = $this->resolveTraitNode($traitName);
        if (! $trait instanceof Trait_) {
            return;
        }

        foreach ($trait->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }
            foreach ($stmt->adaptations as $adaptation) {
                if (! $adaptation instanceof TraitUseAdaptation\Alias) {
                    continue;
                }
                $alias = ($adaptation->newName ?? $adaptation->method)->toString();
                if (str_starts_with($alias, 'test')) {
                    $failures[] = sprintf('trait %s aliases a method to PHPUnit test name %s() - migrate the trait manually before converting its consumer', $traitName, $alias);
                }
            }
            foreach ($stmt->traits as $nestedName) {
                $this->collectTraitConversionFailures($nestedName, $seen, $failures, $depth + 1);
            }
        }
    }

    /** @param array<string, true> $providedMethods */
    private function traitMethodConversionReason(ClassMethod $method, array $providedMethods): ?string
    {
        $hasTargetTest = false;
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, self::PHPUNIT_TEST_ATTRIBUTE)) {
                    return 'uses PHPUnit test discovery that is not rewritten in traits';
                }
                $hasTargetTest = $hasTargetTest || $this->isName($attribute->name, self::TEST_ATTRIBUTE);
            }
        }
        $phpDoc = $this->phpDocInfoFactory->createFromNode($method);
        if (! $hasTargetTest && $method->isPublic()
            && (str_starts_with($method->name->toString(), 'test')
                || ($phpDoc instanceof PhpDocInfo && $phpDoc->getTagsByName('test') !== []))) {
            return 'uses PHPUnit test discovery that is not rewritten in traits';
        }

        $framework = $this->reflectionProvider->getClass(self::FRAMEWORK_BASE);
        $reason = null;
        $directReceivers = [];
        $this->traverseNodesWithCallable($method, function (Node $node) use ($framework, $providedMethods, &$reason, &$directReceivers): ?int {
            if ($node instanceof ClassLike) {
                return \PhpParser\NodeTraverser::DONT_TRAVERSE_CURRENT_AND_CHILDREN;
            }
            if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall
                || $node instanceof PropertyFetch || $node instanceof Expr\NullsafePropertyFetch) {
                if ($node->var instanceof Expr\Variable && $node->var->name === 'this') {
                    $directReceivers[spl_object_id($node->var)] = true;
                    $member = $node->name instanceof Identifier ? $node->name->toString() : null;
                    $isMethod = $node instanceof MethodCall || $node instanceof NullsafeMethodCall;
                    // PHPUnit need not be installed in the migration project, so
                    // reflection alone cannot prove its inherited API is absent.
                    // Only locally provided helper implementations are retained.
                    if ($member === null || ($isMethod
                        ? $framework->hasMethod($member) || ! isset($providedMethods[strtolower($member)])
                        : $member === 'app' || $framework->hasProperty($member))) {
                        $reason ??= 'depends on inherited Laravel/PHPUnit ' . ($isMethod ? 'method ' : 'property ') . ($member ?? '(dynamic)');
                    }
                }
            }
            if ($node instanceof StaticCall && $node->class instanceof Name
                && $this->isNames($node->class, ['self', 'static', 'parent'])) {
                $member = $node->name instanceof Identifier ? $node->name->toString() : null;
                if ($member === null || $this->isName($node->class, 'parent')
                    || $framework->hasMethod($member) || ! isset($providedMethods[strtolower($member)])) {
                    $reason ??= 'depends on inherited Laravel/PHPUnit method ' . ($member ?? '(dynamic)');
                }
            }
            if ($node instanceof Expr\Variable && $node->name === 'this' && ! isset($directReceivers[spl_object_id($node)])) {
                $reason ??= 'aliases or passes $this whose Laravel/PHPUnit dependencies cannot be proved safe';
            }
            if ($node instanceof Name) {
                $resolved = $this->getName($node);
                if ($resolved !== null && (str_starts_with($resolved, 'PHPUnit\\')
                    || str_starts_with($resolved, 'Illuminate\\Testing\\')
                    || str_starts_with($resolved, 'Illuminate\\Foundation\\Testing\\'))) {
                    $reason ??= 'references Laravel/PHPUnit testing API ' . $resolved;
                }
            }

            return null;
        });

        return $reason;
    }

    private function resolveTraitNode(string $traitClass): ?Trait_
    {
        foreach ($this->traitFrontierClasses() as $classLike) {
            if ($classLike instanceof Trait_ && $this->isName($classLike, $traitClass)) {
                return $classLike;
            }
        }

        $local = (new NodeFinder())->findFirst(
            $this->getFile()->getNewStmts(),
            fn(Node $node): bool => $node instanceof Trait_ && $this->isName($node, $traitClass),
        );

        if ($local instanceof Trait_) {
            return $local;
        }

        try {
            $resolved = $this->astResolver->resolveClassFromName($traitClass);
        } catch (\Throwable) {
            $resolved = null;
        }

        return $resolved instanceof Trait_ ? $resolved : null;
    }

    /** @return list<non-empty-string> */
    private function descendantTraitConversionFailures(Class_ $class): array
    {
        $name = $this->getName($class);
        if ($name === null) {
            return [];
        }
        $classes = [];
        foreach ($this->traitFrontierClasses() as $candidate) {
            if ($candidate instanceof Class_ && ($candidateName = $this->getName($candidate)) !== null) {
                $classes[strtolower($candidateName)] = $candidate;
            }
        }
        foreach ($classes as $candidateName => $candidate) {
            if ($candidateName === strtolower($name)) {
                continue;
            }
            $parent = $candidate->extends;
            $seen = [];
            for ($depth = 0; $parent instanceof Name && $depth <= self::MAX_CHAIN_DEPTH; ++$depth) {
                $parentName = strtolower($this->getName($parent) ?? '');
                if ($parentName === strtolower($name)) {
                    $failures = $this->traitConversionFailures($candidate);
                    if ($failures !== []) {
                        return [sprintf('descendant %s has an unsupported trait dependency - preserve the shared source base until its trait is migrated: %s', $this->getName($candidate), $failures[0])];
                    }
                    break;
                }
                if (isset($seen[$parentName]) || ! isset($classes[$parentName])) {
                    break;
                }
                $seen[$parentName] = true;
                $parent = $classes[$parentName]->extends;
            }
        }

        return [];
    }

    /**
     * Snapshot located source declarations before any shared base changes. Rector
     * puts actual CLI sources into its dynamic locator, not Option::PATHS/SOURCE.
     * The locator also covers trait providers supplied through autoload paths.
     *
     * @return list<ClassLike>
     */
    private function traitFrontierClasses(): array
    {
        $locator = $this->sourceLocatorProvider->provide();
        if (isset($this->traitFrontierSnapshots[$locator])) {
            return $this->traitFrontierSnapshots[$locator];
        }
        $currentFile = $this->getFile()->getFilePath();
        $files = [self::normalizePath($currentFile) => $currentFile];
        foreach ((new DefaultReflector($locator))->reflectAllClasses() as $reflection) {
            $file = $reflection->getFileName();
            if ($file !== null) {
                $files[self::normalizePath($file)] = $file;
            }
        }
        ksort($files);
        $classes = [];
        $parser = (new \PhpParser\ParserFactory())->createForNewestSupportedVersion();
        foreach ($files as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                throw new \RuntimeException(sprintf('Cannot inspect trait consumers in %s', $file));
            }
            try {
                $nodes = $parser->parse($source) ?? [];
                $nodes = (new \PhpParser\NodeTraverser(new \PhpParser\NodeVisitor\NameResolver()))->traverse($nodes);
            } catch (\PhpParser\Error) {
                // Rector reports malformed inputs; do not rewrite their source.
                continue;
            }
            $classes = [...$classes, ...(new NodeFinder())->findInstanceOf($nodes, ClassLike::class)];
        }

        return $this->traitFrontierSnapshots[$locator] = $classes;
    }

    /**
     * Every reason the given project base would fail its own conversion when
     * this rule processes its file: a pre-existing blocking residual marker, or
     * one of the gates the atomic conversion must prove - trait adaptations,
     * unsupported bootstrap hooks, lifecycle overrides, `$this->app`
     * expressions, HTTP/response/Artisan signatures and database strategy
     * configuration.
     *
     * A descendant may only be rewritten when every project base on its extends
     * chain converts in the same run; a base that keeps any of these failures
     * stays on PHPUnit and would leave the hierarchy half-migrated.
     *
     * @return list<non-empty-string>
     */
    private function baseConversionFailures(Class_ $class): array
    {
        $failures = [];

        foreach (self::BLOCKING_RESIDUAL_CODES as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                $failures[] = sprintf('a blocking %s residual marker is already present', $code);
            }
        }

        $failures = [
            ...$failures,
            ...$this->traitAdaptationFailures($class),
            ...$this->traitConversionFailures($class),
            ...$this->descendantTraitConversionFailures($class),
            ...$this->bootstrapMethodFailures($class),
            ...$this->lifecycleFailures($class),
            ...$this->appFailures($class),
        ];

        foreach ($this->httpAnalyzer->analyze($class, $this->fileClasses())->reasonsByCode as $reasons) {
            $failures = [...$failures, ...$reasons];
        }

        $databaseAnalysis = $this->databaseAnalyzer->analyze($class, $this->fileClasses());

        if ($databaseAnalysis->unsupportedReason !== null) {
            $failures[] = $databaseAnalysis->unsupportedReason;
        }

        return array_values(array_unique($failures));
    }

    /**
     * @return array{Class_|null, bool} The second value reports whether the class
     *         lives in the file currently being processed.
     */
    private function resolveClassNode(string $className): array
    {
        $local = (new NodeFinder())->findFirst(
            $this->getFile()->getNewStmts(),
            fn(Node $node): bool => $node instanceof Class_ && $this->isName($node, $className),
        );

        if ($local instanceof Class_) {
            return [$local, true];
        }

        try {
            $resolved = $this->astResolver->resolveClassFromName($className);
        } catch (\Throwable) {
            $resolved = null;
        }

        return [$resolved instanceof Class_ ? $resolved : null, false];
    }

    /** @return list<ClassLike> */
    private function fileClasses(): array
    {
        /** @var list<ClassLike> $classes */
        $classes = (new NodeFinder())->findInstanceOf($this->getFile()->getOldStmts(), ClassLike::class);

        return $classes;
    }

    private function isWithinProcessedPaths(Class_ $class): bool
    {
        $scope = $class->getAttribute(AttributeKey::SCOPE);

        $file = $scope instanceof Scope ? $scope->getFile() : null;

        if ($file === null || $file === '') {
            return false;
        }

        if ($file === $this->getFile()->getFilePath()) {
            return true;
        }

        $realFile = \realpath($file);

        if ($realFile === false) {
            return false;
        }

        $realFile = self::normalizePath($realFile);

        try {
            $paths = SimpleParameterProvider::provideArrayParameter(Option::PATHS);
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($paths as $path) {
            $realPath = \realpath((string) $path);
            $candidate = self::normalizePath($realPath === false ? (string) $path : $realPath);

            if ($realFile === $candidate || \str_starts_with($realFile, $candidate . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $normalized = \str_replace('\\', '/', $path);

        // Windows file paths are case-insensitive; compare them consistently.
        return \DIRECTORY_SEPARATOR === '\\' ? \strtolower($normalized) : $normalized;
    }

    /**
     * Rector filters the processed file set before any rule runs: FilesFinder drops
     * globally skipped files (withSkip paths/globs) and the node traverser drops
     * rule-scoped skips (withSkip([self::class => path])). Either way a project base
     * on such a file never converts - and neither may its descendants, which would
     * rename parent::setUp() against a base that still only knows setUp().
     */
    private function isExcludedBySkip(Class_ $class): bool
    {
        $scope = $class->getAttribute(AttributeKey::SCOPE);

        $file = $scope instanceof Scope ? $scope->getFile() : null;

        if ($file === null || $file === '') {
            return false;
        }

        $realFile = \realpath($file);

        if ($realFile === false) {
            return false;
        }

        // No catch here: a failure to EVALUATE the excludes must not silently
        // authorize conversion. Let Rector report the processing failure instead.

        // The skip matcher compares against the same form FilesFinder feeds it:
        // the realpath with Rector's own slash normalization, original casing.
        $normalized = PathNormalizer::normalize($realFile);

        if ($this->skipper->shouldSkipFilePath($normalized)) {
            return true;
        }

        return $this->skipper->shouldSkipElementAndFilePath(self::class, $normalized);
    }

    /** @return list<non-empty-string> */
    private function lifecycleFailures(Class_ $class): array
    {
        $failures = [];

        foreach (['setUp', 'tearDown'] as $name) {
            $method = $class->getMethod($name);

            if (! $method instanceof ClassMethod) {
                continue;
            }

            if ($method->isStatic() || $method->isPrivate() || $method->params !== [] || $method->stmts === null) {
                $failures[] = sprintf('%s() must be a concrete non-static zero-argument override', $name);
            }

            if ($class->getMethod($name . 'Laravel') instanceof ClassMethod) {
                $failures[] = sprintf('%s() conflicts with an existing %sLaravel() hook', $name, $name);
            }

            if ($this->hasNestedOrMismatchedParentLifecycleCall($method, $name)) {
                $failures[] = sprintf('%s() contains a parent lifecycle call that cannot be removed safely', $name);
            }
        }

        $traitFailures = $this->traitProvidedLifecycleFailures($class);

        return array_values(array_unique([...$failures, ...$traitFailures['lifecycle']]));
    }

    private function hasNestedOrMismatchedParentLifecycleCall(ClassMethod $method, string $expected): bool
    {
        if ($method->stmts === null) {
            return false;
        }

        $allowed = [];
        foreach ($method->stmts as $stmt) {
            if ($stmt instanceof Expression
                && $stmt->expr instanceof StaticCall
                && $this->isName($stmt->expr->class, 'parent')
                && $this->isName($stmt->expr->name, $expected)) {
                $allowed[spl_object_id($stmt->expr)] = true;
            }
        }

        $unsafe = false;
        $this->traverseNodesWithCallable($method->stmts, function (Node $inner) use ($allowed, &$unsafe): void {
            if ($inner instanceof StaticCall
                && $this->isName($inner->class, 'parent')
                && $this->isNames($inner->name, ['setUp', 'tearDown'])
                && ! isset($allowed[spl_object_id($inner)])) {
                $unsafe = true;
            }
        });

        return $unsafe;
    }

    /** @return list<non-empty-string> */
    private function appFailures(Class_ $class): array
    {
        $failures = [];

        $this->traverseNodesWithCallable($class->stmts, function (Node $inner) use (&$failures): void {
            if ($inner instanceof PropertyFetch
                && $inner->var instanceof Expr\Variable
                && $inner->var->name === 'this'
                && ! $inner->name instanceof Identifier) {
                $failures[] = 'dynamic $this property access cannot be classified as $this->app';
            }

            // Write contexts are recognized from their wrapper node downward: the
            // app fetch itself must stay the direct receiver. A member access
            // ($this->app->flag = ..., isset($this->app->booted)) only writes or
            // inspects the returned object, which survives the rewrite.
            if (($inner instanceof Expr\Assign || $inner instanceof Expr\AssignOp)
                && $inner->var instanceof PropertyFetch
                && $this->isThisApp($inner->var)) {
                $failures[] = self::APP_WRITE_CONTEXT_REASON;
            }

            // Destructuring binds the property itself: [$this->app, $b] = ... .
            // An ordinary array literal holding the fetch ($x = [$this->app]) is a
            // by-value read and must stay convertible.
            if ($inner instanceof Expr\Assign
                && ($inner->var instanceof Expr\Array_ || $inner->var instanceof Expr\List_)
                && $this->destructuringTargetsApp($inner->var)) {
                $failures[] = self::APP_WRITE_CONTEXT_REASON;
            }

            if ($inner instanceof Expr\AssignRef
                && (($inner->var instanceof PropertyFetch && $this->isThisApp($inner->var))
                    || ($inner->expr instanceof PropertyFetch && $this->isThisApp($inner->expr)))) {
                $failures[] = self::APP_WRITE_CONTEXT_REASON;
            }

            // isset() is an expression; unset() is a statement - both bind the
            // property itself when it is the direct target.
            if ($inner instanceof Expr\Isset_) {
                foreach ($inner->vars as $var) {
                    if ($var instanceof PropertyFetch && $this->isThisApp($var)) {
                        $failures[] = self::APP_WRITE_CONTEXT_REASON;
                        break;
                    }
                }
            }

            if ($inner instanceof Stmt\Unset_) {
                foreach ($inner->vars as $var) {
                    if ($var instanceof PropertyFetch && $this->isThisApp($var)) {
                        $failures[] = self::APP_WRITE_CONTEXT_REASON;
                        break;
                    }
                }
            }

            if (($inner instanceof Expr\PreInc
                    || $inner instanceof Expr\PreDec
                    || $inner instanceof Expr\PostInc
                    || $inner instanceof Expr\PostDec)
                && $inner->var instanceof PropertyFetch
                && $this->isThisApp($inner->var)) {
                $failures[] = self::APP_WRITE_CONTEXT_REASON;
            }

            if ($inner instanceof Node\ArrayItem && $inner->byRef
                && $inner->value instanceof PropertyFetch && $this->isThisApp($inner->value)) {
                $failures[] = self::APP_WRITE_CONTEXT_REASON;
            }

            if ($inner instanceof Stmt\Foreach_) {
                foreach ([$inner->keyVar, $inner->valueVar] as $target) {
                    if (($target instanceof PropertyFetch && $this->isThisApp($target))
                        || (($target instanceof Expr\Array_ || $target instanceof Expr\List_)
                            && $this->destructuringTargetsApp($target))) {
                        $failures[] = self::APP_WRITE_CONTEXT_REASON;
                    }
                }
            }

            // Callee declarations reveal by-reference parameters, including
            // named arguments and constructor parameters.
            if ($inner instanceof MethodCall
                || $inner instanceof StaticCall
                || $inner instanceof FuncCall
                || $inner instanceof NullsafeMethodCall
                || $inner instanceof New_) {
                $takesReference = $this->callTakesAppByReference($inner);
                if ($takesReference === true) {
                    $failures[] = self::APP_WRITE_CONTEXT_REASON;
                } elseif ($takesReference === null) {
                    $failures[] = '$this->app is passed to a call whose by-value parameter signature cannot be proven';
                }
            }

            if (! $inner instanceof MethodCall
                || ! $inner->var instanceof PropertyFetch
                || ! $this->isThisApp($inner->var)
                || ! $this->isName($inner->name, 'make')) {
                return;
            }

            if (count($inner->args) !== 1 || $inner->args[0]->unpack) {
                $failures[] = '$this->app->make() is automatic only with one argument';
                return;
            }

            $argumentName = $inner->args[0]->name?->toString();
            if ($argumentName !== null && $argumentName !== 'abstract') {
                $failures[] = '$this->app->make() uses an unsupported named argument';
            }
        });

        return array_values(array_unique($failures));
    }

    /**
     * True when any destructuring value slot of this left-hand array - at any
     * nesting depth - binds the $this->app property directly.
     */
    private function destructuringTargetsApp(Expr\Array_|Expr\List_ $leftHandSide): bool
    {
        foreach ($leftHandSide->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->value instanceof PropertyFetch && $this->isThisApp($item->value)) {
                return true;
            }

            if ($item->value instanceof Expr\Array_ || $item->value instanceof Expr\List_) {
                if ($this->destructuringTargetsApp($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function callTakesAppByReference(MethodCall|StaticCall|FuncCall|NullsafeMethodCall|New_ $call): ?bool
    {
        $callee = null;
        $calleeResolved = false;

        foreach ($call->getArgs() as $position => $arg) {
            $value = $arg->value;

            if (! $value instanceof PropertyFetch || ! $this->isThisApp($value)) {
                continue;
            }

            if ($arg->byRef) {
                return true;
            }

            if (! $calleeResolved) {
                $callee = $this->resolveCalleeDeclaration($call);
                $calleeResolved = true;

                if (! $callee instanceof ClassMethod && ! $callee instanceof Function_) {
                    // An unresolved callback may require a writable reference;
                    // replacing the property with a temporary method result
                    // would silently discard writes or raise a runtime error.
                    return null;
                }
            }

            if ($arg->name === null) {
                $parameter = $callee->params[$position] ?? null;
            } else {
                $parameter = null;

                foreach ($callee->params as $candidate) {
                    // Param var names are plain strings, not Identifier nodes, and
                    // parameter names are case-sensitive.
                    if (is_string($candidate->var->name)
                        && $candidate->var->name === $arg->name->toString()) {
                        $parameter = $candidate;
                        break;
                    }
                }
            }

            $lastParameter = $callee->params === [] ? null : $callee->params[array_key_last($callee->params)];
            if ($parameter === null && $lastParameter !== null && $lastParameter->variadic) {
                $parameter = $lastParameter;
            }

            if ($parameter !== null && $parameter->byRef) {
                return true;
            }
        }

        return false;
    }

    /**
     * AstResolver misses declarations that live in the very file being processed
     * (same-run corpus functions and classes are not in the composer autoloader),
     * so the current file's statements are searched before giving up.
     *
     */
    private function resolveCalleeDeclaration(MethodCall|StaticCall|FuncCall|NullsafeMethodCall|New_ $call): ClassMethod|Function_|null
    {
        if ($call instanceof FuncCall && $call->name instanceof Name) {
            foreach ($this->localFunctionCandidates($call) as $functionName) {
                $function = (new NodeFinder())->findFirst(
                    $this->getFile()->getNewStmts(),
                    fn(Node $node): bool => $node instanceof Function_
                        && strcasecmp((string) $this->getName($node), $functionName) === 0,
                );

                if ($function instanceof Function_) {
                    return $function;
                }
            }
        }

        if ($call instanceof New_ && $call->class instanceof Name) {
            $className = $this->getName($call->class);

            if ($className !== null) {
                $classNode = (new NodeFinder())->findFirst(
                    $this->getFile()->getNewStmts(),
                    fn(Node $node): bool => $node instanceof Class_ && $this->isName($node, $className),
                );

                if ($classNode instanceof Class_) {
                    return $classNode->getMethod('__construct');
                }
            }
        }

        $resolved = $this->astResolver->resolveClassMethodOrFunctionFromCall($call);

        return $resolved instanceof ClassMethod || $resolved instanceof Function_ ? $resolved : null;
    }

    /** @return list<string> */
    private function localFunctionCandidates(FuncCall $call): array
    {
        if (! $call->name instanceof Name) {
            return [];
        }

        $name = $call->name->toString();
        if ($call->name->isFullyQualified()) {
            return [$name];
        }

        $namespaced = $call->name->getAttribute(AttributeKey::NAMESPACED_NAME);
        $scope = $call->getAttribute(AttributeKey::SCOPE);
        $namespace = $scope instanceof Scope ? $scope->getNamespace() : null;
        $qualified = $namespaced instanceof Name ? $namespaced->toString() : $namespaced;
        if (! is_string($qualified)) {
            $qualified = $namespace === null ? $name : $namespace . '\\' . $name;
        }

        // Only an unqualified call can fall back to the global function. Never
        // pick an unrelated namespace's same-named declaration: its parameter
        // passing mode can differ from the function PHP actually calls.
        return $call->name->isUnqualified()
            ? array_values(array_unique([$qualified, $name]))
            : [$qualified];
    }

    /**
     * @param 'framework'|'descendant' $kind
     */
    private function convertClass(Class_ $class, string $kind): Node
    {
        if ($kind === 'framework') {
            if ($this->configuration->targetMode === self::TARGET_MODE_BASE_CLASS) {
                $class->extends = new FullyQualified(self::TARGET_BASE);
            } else {
                $class->extends = null;
                $this->addTargetTrait($class);
            }
        }

        // A project-base descendant keeps its hierarchy: the converted base carries
        // the Laratesto binding, this class only needs the lifecycle/API rewrites.

        foreach ($class->getMethods() as $method) {
            $this->convertLifecycleMethod($method, $kind);
            $this->markTestMethod($method);
        }

        $this->traverseNodesWithCallable($class->stmts, fn(Node $inner): ?Node => $this->convertAppExpression($inner));

        return $class;
    }

    private function addTargetTrait(Class_ $class): void
    {
        if ($this->hierarchy->usesTargetTrait($class)) {
            return;
        }

        array_unshift($class->stmts, new TraitUse([new FullyQualified(ConfiguredHierarchy::TARGET_TRAIT)]));
    }

    private function convertLifecycleMethod(ClassMethod $method, string $kind): void
    {
        $name = $this->getName($method->name);

        if ($name !== 'setUp' && $name !== 'tearDown') {
            return;
        }

        $method->name = new Identifier($name . 'Laravel');
        $this->removeLifecycleAttributes($method);

        if ($method->stmts === null) {
            return;
        }

        // A project-base parent keeps its place in the hierarchy and gets its hooks
        // renamed in the same run, so the chain call must follow the rename or the
        // base lifecycle silently stops executing. Only a direct framework parent is
        // replaced wholesale, so only that conversion may drop the parent call.
        if ($kind === 'descendant') {
            foreach ($method->stmts as $stmt) {
                if ($stmt instanceof Expression
                    && $stmt->expr instanceof StaticCall
                    && $this->isName($stmt->expr->class, 'parent')
                    && $this->isName($stmt->expr->name, $name)) {
                    $stmt->expr->name = new Identifier($name . 'Laravel');
                }
            }
        }

        $method->stmts = array_values(array_filter(
            $method->stmts,
            fn(Stmt $stmt): bool => ! ($stmt instanceof Expression
                && $stmt->expr instanceof StaticCall
                && $this->isName($stmt->expr->class, 'parent')
                && $this->isName($stmt->expr->name, $name)),
        ));
    }

    private function removeLifecycleAttributes(ClassMethod $method): void
    {
        $groups = [];

        foreach ($method->attrGroups as $group) {
            $attributes = array_values(array_filter(
                $group->attrs,
                fn(Attribute $attribute): bool => ! $this->isNames(
                    $attribute->name,
                    ['Testo\Lifecycle\BeforeTest', 'Testo\Lifecycle\AfterTest'],
                ),
            ));

            if ($attributes !== []) {
                $groups[] = new AttributeGroup($attributes, $group->getAttributes());
            }
        }

        $method->attrGroups = $groups;
    }

    private function markTestMethod(ClassMethod $method): void
    {
        if (! $method->isPublic()) {
            return;
        }

        // Idempotent: a method already carrying #[\Testo\Test] needs nothing.
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, self::TEST_ATTRIBUTE)) {
                    return;
                }
            }
        }

        // PHPUnit #[Test] attribute is rewritten in place to #[\Testo\Test].
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, self::PHPUNIT_TEST_ATTRIBUTE)) {
                    $attribute->name = new FullyQualified(self::TEST_ATTRIBUTE);

                    return;
                }
            }
        }

        // A @test docblock annotation is PHPUnit discovery for a non-test name:
        // drop the tag without damaging the rest of the docblock, then add the
        // attribute (same semantics as upstream Testo's ExtendsTestCaseToTestoRector).
        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($method);

        if ($phpDocInfo instanceof PhpDocInfo) {
            $testTags = $phpDocInfo->getTagsByName('test');

            if ($testTags !== []) {
                foreach ($testTags as $testTag) {
                    if ($testTag->value instanceof GenericTagValueNode) {
                        $this->phpDocTagRemover->removeTagValueFromNode($phpDocInfo, $testTag);
                    }
                }

                $this->docBlockUpdater->updateRefactoredNodeWithPhpDocInfo($method);
                $this->addTestoAttribute($method);

                return;
            }
        }

        // A bare `test`-prefixed name with no explicit marker gains the attribute.
        $name = $this->getName($method->name);
        if ($name !== null && str_starts_with($name, 'test')) {
            $this->addTestoAttribute($method);
        }
    }

    private function addTestoAttribute(ClassMethod $method): void
    {
        $method->attrGroups[] = new AttributeGroup([
            new Attribute(new FullyQualified(self::TEST_ATTRIBUTE)),
        ]);
    }

    private function convertAppExpression(Node $node): ?Node
    {
        if ($node instanceof MethodCall
            && $node->var instanceof PropertyFetch
            && $this->isThisApp($node->var)
            && $this->isName($node->name, 'make')
            && count($node->args) === 1) {
            return new MethodCall(new Expr\Variable('this'), new Identifier('make'), $node->args);
        }

        if ($node instanceof PropertyFetch && $this->isThisApp($node)) {
            return new MethodCall(new Expr\Variable('this'), new Identifier('app'));
        }

        return null;
    }

    private function isThisApp(PropertyFetch $node): bool
    {
        return $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toString() === 'app';
    }

    private function hasBlockingMarker(Class_ $class): bool
    {
        foreach (self::BLOCKING_RESIDUAL_CODES as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                return true;
            }
        }

        return false;
    }
}
