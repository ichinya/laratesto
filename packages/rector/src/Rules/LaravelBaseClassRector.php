<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer;
use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Configuration\BaseClassConfiguration;
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
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
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

    private const TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    private const TARGET_TRAIT = 'Laratesto\Testing\InteractsWithLaravel';

    /**
     * Guard against cyclic or pathologically deep extends chains: anything deeper is
     * reported as an unsafe hierarchy instead of being converted.
     */
    private const MAX_CHAIN_DEPTH = 10;

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

    private BaseClassConfiguration $configuration;

    public function __construct(
        private readonly AstResolver $astResolver,
        private readonly DatabaseConfigurationAnalyzer $databaseAnalyzer,
        private readonly HttpCompatibilityAnalyzer $httpAnalyzer,
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
        // singleton instance (see BaseClassConfiguration for the contract).
        $this->configuration = BaseClassConfiguration::fromArray($configuration);
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

        if (! $this->isNames($node->extends, $this->configuration->laravelBases)) {
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
        $databaseAnalysis = $this->databaseAnalyzer->analyze($node);
        $httpAnalysis = $this->httpAnalyzer->analyze($node);
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
     * Walks the extends chain of a project base and proves three things: every class
     * on the chain is resolvable, every project class on it is inside the processed
     * paths and free of conversion blockers, and the chain terminates at the Laravel
     * framework base (or at an already-migrated Laratesto base).
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

            if ($this->usesTargetTrait($parentClass)) {
                return ['descendant', null];
            }

            $failures = [
                ...$this->traitAdaptationFailures($parentClass),
                ...$this->bootstrapMethodFailures($parentClass),
                ...$this->lifecycleFailures($parentClass),
            ];

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
                if (! in_array(self::FRAMEWORK_BASE, $this->configuration->laravelBases, true)) {
                    return [null, sprintf(
                        'project base %s extends the framework base, which is outside the configured base_classes',
                        $current,
                    )];
                }

                return ['descendant', null];
            }

            if ($next !== self::TARGET_BASE && ! in_array($next, $this->configuration->laravelBases, true)) {
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

            if ($name !== null && in_array($name, self::UNSUPPORTED_BOOTSTRAP_METHODS, true)) {
                $failures[] = sprintf('custom bootstrap method %s() is not called by the Laratesto target', $name);
            }
        }

        return $failures;
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

    private function usesTargetTrait(Class_ $class): bool
    {
        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                if ($this->isName($trait, self::TARGET_TRAIT)) {
                    return true;
                }
            }
        }

        return false;
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

        return array_values(array_unique($failures));
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
            $this->convertLifecycleMethod($method);
            $this->markTestMethod($method);
        }

        $this->traverseNodesWithCallable($class->stmts, fn(Node $inner): ?Node => $this->convertAppExpression($inner));

        return $class;
    }

    private function addTargetTrait(Class_ $class): void
    {
        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                if ($this->isName($trait, self::TARGET_TRAIT)) {
                    return;
                }
            }
        }

        array_unshift($class->stmts, new TraitUse([new FullyQualified(self::TARGET_TRAIT)]));
    }

    private function convertLifecycleMethod(ClassMethod $method): void
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

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, self::TEST_ATTRIBUTE)) {
                    return;
                }
            }
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->isName($attribute->name, self::PHPUNIT_TEST_ATTRIBUTE)) {
                    $attribute->name = new FullyQualified(self::TEST_ATTRIBUTE);
                    return;
                }
            }
        }

        $name = $this->getName($method->name);
        if ($name !== null && str_starts_with($name, 'test')) {
            $method->attrGroups[] = new AttributeGroup([
                new Attribute(new FullyQualified(self::TEST_ATTRIBUTE)),
            ]);
        }
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
        foreach ([
            ResidualCode::CLASS_UNSAFE_HIERARCHY,
            ResidualCode::LIFECYCLE_UNSUPPORTED,
            ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
            ResidualCode::HTTP_UNSUPPORTED_SIGNATURE,
            ResidualCode::RESPONSE_UNSUPPORTED_API,
            ResidualCode::ARTISAN_INTERACTION_UNSUPPORTED,
        ] as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                return true;
            }
        }

        return false;
    }
}
