<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

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
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Use_;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Converts a Laravel PHPUnit test class to the Laratesto base class.
 *
 * Applies to a class whose `extends` names a Laravel test base — the default targets
 * are `Tests\TestCase` (the conventional project base, configurable) and
 * `Illuminate\Foundation\Testing\TestCase`; matching is by name, fully-qualified,
 * imported or aliased alike. The deterministic conversion axis of the hybrid
 * detection decision: everything happens INSIDE a matched class, nothing outside.
 *
 * Inside a matched class the rule:
 *   - rewrites `extends` to `Laratesto\Testing\LaravelTestCase`
 *     (import-style when a `use` of the old base exists, fully-qualified otherwise),
 *   - renames `setUp()`/`tearDown()` to `setUpLaravel()`/`tearDownLaravel()` and drops
 *     their `parent::setUp()` / `parent::tearDown()` calls — the Laratesto bridge runs
 *     the lifecycle itself (also removes a `#[Testo\Lifecycle\...]` attribute that the
 *     upstream generic rule may have attached before the rename),
 *   - marks test methods with `#[\Testo\Test]` the way upstream does for plain PHPUnit
 *     classes (attribute rewrite for `#[PHPUnit\Framework\Attributes\Test]`, addition
 *     for `test`-prefixed public methods),
 *   - rewrites `$this->app` to `$this->app()` and `$this->app->make(X)` to `$this->make(X)`,
 *   - rewrites `Illuminate\Testing\TestResponse` references (typehints, imports, FQ usages)
 *     to `Laratesto\Testing\LaravelResponse`.
 *
 * Idempotent: a class already extending LaravelTestCase is left untouched. Classes with
 * an unresolvable or custom base are not touched here — they surface as residuals via
 * the detection rules (ticket 04).
 */
#[TestRectorFixtures('LaravelBaseClassRector')]
final class LaravelBaseClassRector extends AbstractRector
{
    private const string TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    private const string TEST_ATTRIBUTE = 'Testo\Test';

    private const string PHPUNIT_TEST_ATTRIBUTE = 'PHPUnit\Framework\Attributes\Test';

    /**
     * Laravel PHPUnit base classes converted by name. A project-specific base goes first:
     * the conventional Laravel skeleton TestCase.
     */
    private const array LARAVEL_BASES = [
        'Tests\TestCase',
        'Illuminate\Foundation\Testing\TestCase',
    ];

    private const string OLD_RESPONSE = 'Illuminate\Testing\TestResponse';

    private const string NEW_RESPONSE = 'Laratesto\Testing\LaravelResponse';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convert a Laravel PHPUnit test (base class, lifecycle, $this->app, TestResponse) to Laratesto',
            [
                new CodeSample(
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;

                        final class UsersTest extends TestCase
                        {
                            protected function setUp(): void
                            {
                                parent::setUp();
                            }

                            public function test_users_list(): void
                            {
                                $response = $this->get('/users');
                                $response->assertStatus(200);
                            }
                        }
                        PHP,
                    <<<'PHP'
                        use Laratesto\Testing\LaravelTestCase;

                        final class UsersTest extends LaravelTestCase
                        {
                            protected function setUpLaravel(): void
                            {
                            }

                            #[\Testo\Test]
                            public function test_users_list(): void
                            {
                                $response = $this->get('/users');
                                $response->assertStatus(200);
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
        // Use_ rewrites the import of the old base/response classes; Class_ does the rest.
        return [Class_::class, Use_::class];
    }

    /**
     * @param Class_|Use_ $node
     */
    #[\Override]
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Use_) {
            return $this->refactorUse($node);
        }

        if ($node->extends === null) {
            return null;
        }

        // Idempotency: an already-converted class (or a native Laratesto test) is a no-op.
        if ($this->isName($node->extends, self::TARGET_BASE)) {
            return null;
        }

        if (! $this->isNames($node->extends, self::LARAVEL_BASES)) {
            return null;
        }

        return $this->convertClass($node);
    }

    private function refactorUse(Use_ $node): ?Node
    {
        $changed = false;

        foreach ($node->uses as $use) {
            if ($this->isNames($use->name, self::LARAVEL_BASES)) {
                // Inside a use statement the name is resolved as fully qualified anyway;
                // a plain Name prints without the leading backslash.
                $use->name = new Name(self::TARGET_BASE);
                $changed = true;
            } elseif ($this->isName($use->name, self::OLD_RESPONSE)) {
                $use->name = new Name(self::NEW_RESPONSE);
                $changed = true;
            }
        }

        return $changed ? $node : null;
    }

    private function convertClass(Class_ $node): Node
    {
        // Import-style when the old base had a use statement (this rule rewrites it to
        // the target — Use_ is visited before Class_ in the same traversal); fully
        // qualified when the extends was written long-hand with no import.
        $shortBase = (new FullyQualified(self::TARGET_BASE))->getLast();
        $wasImported = $this->fileImportsAny(self::LARAVEL_BASES);

        $node->extends = $wasImported
            ? new Name($shortBase)
            : new FullyQualified(self::TARGET_BASE);

        foreach ($node->getMethods() as $method) {
            $this->convertLifecycleMethod($method);
            $this->markTestMethod($method);
        }

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner): ?Node {
            return $this->convertExpression($inner);
        });

        return $node;
    }

    private function convertLifecycleMethod(ClassMethod $method): void
    {
        $name = $this->getName($method->name);

        if ($name !== 'setUp' && $name !== 'tearDown') {
            return;
        }

        // A signature PHPUnit would not accept (parameters) is not a lifecycle override;
        // leave it — it surfaces as a residual.
        if ($method->params !== []) {
            return;
        }

        $method->name = new Identifier($name . 'Laravel');
        $this->removeLifecycleAttributes($method);

        if ($method->stmts === null) {
            return;
        }

        // parent::setUp() must go: LaravelTestCase has no such method — the bridge calls
        // setUpLaravel() itself around every test.
        $method->stmts = array_values(array_filter(
            $method->stmts,
            function (Stmt $stmt): bool {
                if (! $stmt instanceof Expression || ! $stmt->expr instanceof StaticCall) {
                    return true;
                }

                $call = $stmt->expr;

                return ! $this->isName($call->class, 'parent')
                    || ! $this->isNames($call->name, ['setUp', 'tearDown']);
            },
        ));
    }

    private function removeLifecycleAttributes(ClassMethod $method): void
    {
        // The upstream LifecycleMethodToTestoRector may have attached BeforeTest/AfterTest
        // to the method by its old name; after the rename the attribute would double the
        // lifecycle the bridge already runs.
        $method->attrGroups = array_values(array_filter(
            $method->attrGroups,
            function (AttributeGroup $group): bool {
                foreach ($group->attrs as $attr) {
                    if ($this->isNames($attr->name, ['Testo\Lifecycle\BeforeTest', 'Testo\Lifecycle\AfterTest'])) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    private function markTestMethod(ClassMethod $method): void
    {
        if (! $method->isPublic()) {
            return;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                // Idempotent: already discoverable by Testo.
                if ($this->isName($attr->name, self::TEST_ATTRIBUTE)) {
                    return;
                }
            }
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($this->isName($attr->name, self::PHPUNIT_TEST_ATTRIBUTE)) {
                    $attr->name = new FullyQualified(self::TEST_ATTRIBUTE);

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

    private function convertExpression(Node $node): ?Node
    {
        if ($node instanceof Name && $this->isName($node, self::OLD_RESPONSE)) {
            // Short name when the old response class had a use statement (rewritten to the
            // target already); fully-qualified for a long-hand reference with no import.
            return $this->fileImportsAny([self::OLD_RESPONSE])
                ? new Name((new FullyQualified(self::NEW_RESPONSE))->getLast())
                : new FullyQualified(self::NEW_RESPONSE);
        }

        if (! $node instanceof PropertyFetch && ! $node instanceof MethodCall) {
            return null;
        }

        // $this->app->make(X) => $this->make(X)
        if (
            $node instanceof MethodCall
            && $node->var instanceof PropertyFetch
            && $this->isThisApp($node->var)
            && $this->isName($node->name, 'make')
            && $node->args !== []
        ) {
            return new MethodCall(
                new Expr\Variable('this'),
                new Identifier('make'),
                $node->args,
            );
        }

        // $this->app => $this->app()
        if ($node instanceof PropertyFetch && $this->isThisApp($node)) {
            return new MethodCall(
                new Expr\Variable('this'),
                new Identifier('app'),
            );
        }

        return null;
    }

    /**
     * Whether $node is exactly `$this->app` (the Laravel application property).
     */
    private function isThisApp(PropertyFetch $node): bool
    {
        return $node->var instanceof Expr\Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toString() === 'app';
    }

    /**
     * Whether the ORIGINAL file (before this rule's changes) imports any of $classes —
     * the signal that the import-style rewrite applies instead of a fully-qualified name.
     *
     * @param list<string> $classes
     */
    private function fileImportsAny(array $classes): bool
    {
        // Old stmts come wrapped in a Rector FileNode; use statements live inside
        // Namespace_->stmts when the file declares a namespace.
        foreach ($this->getFile()->getOldStmts() as $stmt) {
            foreach ($this->unwrapUseScope($stmt) as $inner) {
                if (! $inner instanceof Use_) {
                    continue;
                }

                foreach ($inner->uses as $use) {
                    if ($this->isNames($use->name, $classes)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The statements a `use` can live under: inside a Rector FileNode wrapper, inside a
     * namespace block, or at the top level of a file.
     *
     * @return Stmt[]
     */
    private function unwrapUseScope(Stmt $stmt): array
    {
        if ($stmt instanceof FileNode) {
            $stmts = $stmt->stmts;
        } else {
            $stmts = [$stmt];
        }

        $result = [];
        foreach ($stmts as $inner) {
            $result[] = $inner instanceof Stmt\Namespace_ ? $inner->stmts : [$inner];
        }

        return array_merge(...$result);
    }
}
