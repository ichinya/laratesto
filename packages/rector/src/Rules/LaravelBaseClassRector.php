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
use PhpParser\Node\Expr\MethodCall;
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
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TraitUseAdaptation;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Ast\PhpDoc\GenericTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTagRemover;
use Rector\Comments\NodeDocBlock\DocBlockUpdater;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\AstResolver;
use Rector\Rector\AbstractRector;
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

    private BaseClassConfiguration $configuration;

    public function __construct(
        private readonly AstResolver $astResolver,
        private readonly ConfiguredHierarchy $hierarchy,
        private readonly DatabaseConfigurationAnalyzer $databaseAnalyzer,
        private readonly HttpCompatibilityAnalyzer $httpAnalyzer,
        private readonly PhpDocInfoFactory $phpDocInfoFactory,
        private readonly PhpDocTagRemover $phpDocTagRemover,
        private readonly DocBlockUpdater $docBlockUpdater,
    ) {
        $this->configuration = BaseClassConfiguration::defaults();
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

    private function resolveTraitNode(string $traitClass): ?Trait_
    {
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
