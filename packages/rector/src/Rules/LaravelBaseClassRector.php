<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer;
use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
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
use Rector\Contract\Rector\ConfigurableRectorInterface;
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
    public const string BASE_CLASSES = 'base_classes';

    public const string TARGET_MODE = 'target_mode';

    public const string TARGET_MODE_BASE_CLASS = 'base_class';

    public const string TARGET_MODE_TRAIT = 'trait';

    /** @var list<non-empty-string> */
    public const array DEFAULT_BASE_CLASSES = [
        'Tests\TestCase',
        'Illuminate\Foundation\Testing\TestCase',
    ];

    private const string FRAMEWORK_BASE = 'Illuminate\Foundation\Testing\TestCase';

    private const string TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    private const string TARGET_TRAIT = 'Laratesto\Testing\InteractsWithLaravel';

    private const string TEST_ATTRIBUTE = 'Testo\Test';

    private const string PHPUNIT_TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    /**
     * Laravel/PHPUnit boot hooks that the Laratesto application factory does not call.
     * Keeping such a method while replacing the parent would silently drop behavior.
     */
    private const array UNSUPPORTED_BOOTSTRAP_METHODS = [
        'createApplication',
        'getPackageProviders',
        'getPackageAliases',
        'getEnvironmentSetUp',
        'defineEnvironment',
        'resolveApplication',
        'afterApplicationCreated',
        'beforeApplicationDestroyed',
    ];

    /** @var list<non-empty-string> */
    private array $laravelBases = self::DEFAULT_BASE_CLASSES;

    private string $targetMode = self::TARGET_MODE_BASE_CLASS;

    public function __construct(
        private readonly AstResolver $astResolver,
        private readonly DatabaseConfigurationAnalyzer $databaseAnalyzer,
        private readonly HttpCompatibilityAnalyzer $httpAnalyzer,
    ) {}

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
        if (array_key_exists(self::BASE_CLASSES, $configuration)) {
            $bases = $configuration[self::BASE_CLASSES];

            if (! is_array($bases) || $bases === []) {
                throw new \InvalidArgumentException('base_classes must be a non-empty list of class names.');
            }

            $normalized = [];
            foreach ($bases as $base) {
                if (! is_string($base) || trim($base, " \\t\\n\\r\\0\\x0B\\") === '') {
                    throw new \InvalidArgumentException('base_classes must contain only non-empty class names.');
                }

                $normalized[] = trim($base, " \\t\\n\\r\\0\\x0B\\");
            }

            $this->laravelBases = array_values(array_unique($normalized));
        }

        if (array_key_exists(self::TARGET_MODE, $configuration)) {
            $mode = $configuration[self::TARGET_MODE];

            if (! is_string($mode) || ! in_array($mode, [self::TARGET_MODE_BASE_CLASS, self::TARGET_MODE_TRAIT], true)) {
                throw new \InvalidArgumentException('target_mode must be "base_class" or "trait".');
            }

            $this->targetMode = $mode;
        }
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

        if (! $this->isNames($node->extends, $this->laravelBases)) {
            return null;
        }

        if ($this->hasBlockingMarker($node)) {
            return null;
        }

        $changed = false;
        $hierarchyFailures = $this->hierarchyFailures($node);
        $lifecycleFailures = $this->lifecycleFailures($node);
        $appFailures = $this->appFailures($node);
        $databaseAnalysis = $this->databaseAnalyzer->analyze($node);
        $httpAnalysis = $this->httpAnalyzer->analyze($node);
        $httpFailures = array_values(array_unique([
            ...$appFailures,
            ...($httpAnalysis->reasonsByCode['HTTP_UNSUPPORTED_SIGNATURE'] ?? []),
        ]));

        if ($hierarchyFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                'CLASS_UNSAFE_HIERARCHY',
                static::class,
                implode('; ', $hierarchyFailures),
            ) || $changed;
        }

        if ($lifecycleFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                'LIFECYCLE_UNSUPPORTED',
                static::class,
                implode('; ', $lifecycleFailures),
            ) || $changed;
        }

        if ($httpFailures !== []) {
            $changed = ResidualMarker::mark(
                $node,
                'HTTP_UNSUPPORTED_SIGNATURE',
                static::class,
                implode('; ', $httpFailures),
            ) || $changed;
        }

        foreach (['RESPONSE_UNSUPPORTED_API', 'ARTISAN_INTERACTION_UNSUPPORTED'] as $code) {
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
                'DATABASE_UNSUPPORTED_CONFIGURATION',
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

        return $this->convertClass($node);
    }

    /** @return list<non-empty-string> */
    private function hierarchyFailures(Class_ $class): array
    {
        $failures = [];

        if (! $this->isName($class->extends, self::FRAMEWORK_BASE) && ! $this->isSafePassThroughParent($class->extends)) {
            $failures[] = sprintf(
                'parent %s is not a resolvable pass-through Laravel test base',
                $this->getName($class->extends) ?? 'unknown',
            );
        }

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof TraitUse && $stmt->adaptations !== []) {
                $failures[] = 'trait adaptations cannot be preserved by automatic class conversion';
                break;
            }
        }

        foreach ($class->getMethods() as $method) {
            $name = $this->getName($method->name);

            if ($name !== null && in_array($name, self::UNSUPPORTED_BOOTSTRAP_METHODS, true)) {
                $failures[] = sprintf('custom bootstrap method %s() is not called by the Laratesto target', $name);
            }
        }

        if ($this->targetMode === self::TARGET_MODE_TRAIT && $class->isAnonymous()) {
            $failures[] = 'trait target mode does not support anonymous test classes';
        }

        return array_values(array_unique($failures));
    }

    private function isSafePassThroughParent(Node\Name $parent): bool
    {
        $parentName = $this->getName($parent);

        if ($parentName === null || ! in_array($parentName, $this->laravelBases, true)) {
            return false;
        }

        try {
            $parentNode = $this->astResolver->resolveClassFromName($parentName);
        } catch (\Throwable) {
            return false;
        }

        return $parentNode instanceof Class_
            && $parentNode->extends !== null
            && $this->isName($parentNode->extends, self::FRAMEWORK_BASE)
            && $parentNode->stmts === [];
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

    private function convertClass(Class_ $class): Node
    {
        if ($this->targetMode === self::TARGET_MODE_BASE_CLASS) {
            $class->extends = new FullyQualified(self::TARGET_BASE);
        } else {
            $class->extends = null;
            $this->addTargetTrait($class);
        }

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
            'CLASS_UNSAFE_HIERARCHY',
            'LIFECYCLE_UNSUPPORTED',
            'DATABASE_UNSUPPORTED_CONFIGURATION',
            'HTTP_UNSUPPORTED_SIGNATURE',
            'RESPONSE_UNSUPPORTED_API',
            'ARTISAN_INTERACTION_UNSUPPORTED',
        ] as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                return true;
            }
        }

        return false;
    }
}
