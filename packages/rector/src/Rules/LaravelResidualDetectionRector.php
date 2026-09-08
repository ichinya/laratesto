<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Configuration\ConfiguredHierarchy;
use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Residuals\ResidualCode;
use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use PHPStan\Type\ObjectType;
use Rector\PhpParser\AstResolver;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Makes everything the migration cannot convert VISIBLE instead of silently dropped.
 *
 * Two sides of the hybrid detection decision:
 *
 * 1. Inside a converted Laravel test class — calls with no Laratesto counterpart:
 *    response asserts Laratesto does not provide (`assertJsonFragment`,
 *    `assertCookieExpired`, `assertViewIs`,
 *    `assertDownload`, ...). They keep running under PHPUnit semantics; the class gets
 *    a residual marker listing what needs a manual decision. An unsupported database
 *    strategy is made visible the same way: a `LazilyRefreshDatabase` trait use —
 *    direct, or composed through a project trait resolvable in the same file — has no
 *    lazy counterpart (the eager attribute would change refresh timing), so the trait
 *    use stays and the class is marked for manual migration.
 *
 * 2. Outside the convertible hierarchy — a class that is NOT a Laravel test but still
 *    uses Laravel test constructs (`$this->app`, a database trait, a `TestResponse`
 *    typehint). The extends chain decides the wording: when it resolves into the
 *    configured hierarchy through project bases missing from `base_classes`, the
 *    marker names them with the exact `--base-class`/config fix (fail-closed — this
 *    rule never converts); when a hop is unresolvable, cyclic, over-deep or dead-ends
 *    below the configured bases, the conservative manual-migration wording stays.
 *
 * The marker is the canonical `laratesto-residual` comment (see {@see ResidualMarker});
 * the scanner collects it into the table and report. Idempotent: one marker per code
 * per class, and rules failing the same code merge their contributions.
 */
#[TestRectorFixtures('LaravelResidualDetectionRector')]
final class LaravelResidualDetectionRector extends AbstractRector
{
    /**
     * Guard against cyclic or pathologically deep extends chains, mirroring
     * LaravelBaseClassRector::MAX_CHAIN_DEPTH: anything deeper stays on the
     * conservative "does not resolve" wording instead of a speculative fix.
     */
    private const MAX_CHAIN_DEPTH = 10;

    /**
     * Response/method calls inside a Laravel test that Laratesto does not provide.
     */
    private const UNSUPPORTED_TEST_HELPERS = [];

    private const UNSUPPORTED_RESPONSE_METHODS = [
        'assertJsonFragment',
        'assertCookieExpired',
        'assertCookieNotExpired',
        'assertViewIs',
        'assertDownload',
        'assertStreamedContent',
    ];

    /**
     * The lazy refresh strategy: same refresh semantics as RefreshDatabase, deferred
     * to the first database use. The migration has no lazy counterpart — mapping it
     * to the eager attribute would change refresh timing — so a class carrying it
     * (directly or through a same-file project trait) is marked, and the trait use
     * stays in place for the manual migration.
     */
    private const LAZY_DATABASE_TRAIT = 'Illuminate\Foundation\Testing\LazilyRefreshDatabase';

    /**
     * Laravel test constructs that mark a NON-test class as carrying unmigrated code.
     * Includes the lazy refresh strategy: the pipeline never converts it, so it is a
     * construct outside the hierarchy exactly like its eager siblings.
     */
    private const LARAVEL_TEST_TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTransactions',
        'Illuminate\Foundation\Testing\DatabaseMigrations',
        'Illuminate\Foundation\Testing\DatabaseTruncation',
        'Illuminate\Foundation\Testing\LazilyRefreshDatabase',
    ];

    public function __construct(
        private readonly AstResolver $astResolver,
        private readonly ConfiguredHierarchy $hierarchy,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Mark unsupported Laravel assertions, database strategies and constructs outside the hierarchy as residuals',
            [
                new CodeSample(
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;

                        final class SignupTest extends TestCase
                        {
                            public function test_signup_sends_mail(): void
                            {
                                $this->postJson('/signup', ['email' => 'a@b.c'])
                                    ->assertJsonFragment(['created' => true]);
                            }
                        }
                        PHP,
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;

                        /* laratesto-residual(code=RESPONSE_UNSUPPORTED_API, rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector, severity=manual): assertJsonFragment() has no supported response counterpart */
                        final class SignupTest extends TestCase
                        {
                            public function test_signup_sends_mail(): void
                            {
                                $this->postJson('/signup', ['email' => 'a@b.c'])
                                    ->assertJsonFragment(['created' => true]);
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
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    #[\Override]
    public function refactor(Node $node): ?Node
    {
        return $this->hierarchy->recognizesTestClass($node)
            ? $this->detectUnsupportedInside($node)
            : $this->detectConstructsOutsideHierarchy($node);
    }

    /**
     * Unsupported constructs inside a converted (or convertible) Laravel test.
     */
    private function detectUnsupportedInside(Class_ $node): ?Node
    {
        $unsupportedHelpers = [];
        $unsupportedResponses = [];

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use ($node, &$unsupportedHelpers, &$unsupportedResponses): void {
            if (! $inner instanceof MethodCall) {
                return;
            }

            if ($this->isNames($inner->name, self::UNSUPPORTED_TEST_HELPERS)
                && ! $this->isUnrelatedHelperReceiver($inner, $node)) {
                $unsupportedHelpers[] = \sprintf('%s()', $this->getName($inner->name));
            }

            if ($this->isNames($inner->name, self::UNSUPPORTED_RESPONSE_METHODS)
                && ! $this->isProvenOutsideTypes($inner->var, [HttpCompatibilityAnalyzer::TEST_RESPONSE, HttpCompatibilityAnalyzer::LARAVEL_RESPONSE])) {
                $unsupportedResponses[] = \sprintf('%s()', $this->getName($inner->name));
            }
        });

        $changed = false;

        // Fake setup already works in a booted Laravel application. Their
        // assertions are retained through LaravelFacadeAssertionsRector; PHPUnit
        // remains a documented assertion-library dependency for these calls.

        $unsupportedHelpers !== [] and $changed = ResidualMarker::mark(
            $node,
            ResidualCode::HTTP_UNSUPPORTED_SIGNATURE,
            static::class,
            \implode(', ', \array_values(\array_unique($unsupportedHelpers)))
                . ' — no automatic helper conversion; migrate manually',
        ) || $changed;

        $unsupportedResponses !== [] and $changed = ResidualMarker::mark(
            $node,
            ResidualCode::RESPONSE_UNSUPPORTED_API,
            static::class,
            \implode(', ', \array_values(\array_unique($unsupportedResponses)))
                . ' — no automatic conversion; migrate manually',
        ) || $changed;

        $lazyStrategy = $this->lazyStrategyFindings($node);

        $lazyStrategy !== [] and $changed = ResidualMarker::mark(
            $node,
            ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
            static::class,
            \implode(', ', \array_values(\array_unique($lazyStrategy)))
                . ' — the lazy database refresh strategy has no automatic conversion; migrate manually',
        ) || $changed;

        return $changed ? $node : null;
    }

    private function isUnrelatedHelperReceiver(MethodCall $call, Class_ $class): bool
    {
        if ($call->var instanceof Variable && $call->var->name === 'this') {
            return $call->name instanceof Identifier && $class->getMethod($call->name->toString()) !== null;
        }

        return $this->isProvenOutsideTypes($call->var, [
            'Illuminate\\Foundation\\Testing\\TestCase',
            'Laratesto\\Testing\\LaravelTestCase',
            (string) $this->getName($class),
        ]);
    }

    /** @param list<class-string> $classes */
    private function isProvenOutsideTypes(Node\Expr $receiver, array $classes): bool
    {
        $type = $this->getNativeType($receiver);
        if (! $type->isObject()->yes()) {
            return false;
        }

        foreach ($classes as $class) {
            if (! (new ObjectType($class))->isSuperTypeOf($type)->no()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The lazy refresh strategy inside a converted class: a direct trait use (any
     * import alias or casing — matched through Rector name resolution), or a project
     * trait resolvable in the same file that composes it
     * ({@see sameFileTraitComposes()}). Traits flatten their composition into every
     * consuming class, so the composed shape hides the strategy from the direct use
     * scan exactly like an unconverted strategy trait would.
     *
     * @return list<string>
     */
    private function lazyStrategyFindings(Class_ $node): array
    {
        $findings = [];

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$findings): void {
            if (! $inner instanceof TraitUse) {
                return;
            }

            foreach ($inner->traits as $trait) {
                if ($this->isName($trait, self::LAZY_DATABASE_TRAIT)) {
                    $findings[] = self::LAZY_DATABASE_TRAIT . ' trait';

                    continue;
                }

                $traitName = $this->getName($trait);

                if (\is_string($traitName) && $traitName !== ''
                    && $this->sameFileTraitComposes($traitName)) {
                    $findings[] = \sprintf(
                        'project trait %s composes %s',
                        $traitName,
                        self::LAZY_DATABASE_TRAIT,
                    );
                }
            }
        });

        return $findings;
    }

    /**
     * Bounded, cycle-guarded walk over the file's own trait declarations: the exact
     * local-first lookup {@see resolveChainClass()} uses, without the autoload
     * fallback — Rector has no name resolution for trait declarations, and the walk
     * must stay provable rather than speculative. An unresolvable project trait is
     * not claimed here; its uses stay outside this rule's evidence.
     */
    private function sameFileTraitComposes(string $traitName, int $depth = 0, array $seen = []): bool
    {
        if ($depth > self::MAX_CHAIN_DEPTH || isset($seen[$traitName])) {
            return false;
        }

        $seen[$traitName] = true;

        $trait = (new NodeFinder())->findFirst(
            $this->getFile()->getNewStmts(),
            fn(Node $node): bool => $node instanceof Trait_ && $this->isName($node, $traitName),
        );

        if (! $trait instanceof Trait_) {
            return false;
        }

        foreach ($trait->stmts as $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $composed) {
                if ($this->isName($composed, self::LAZY_DATABASE_TRAIT)) {
                    return true;
                }

                $composedName = $this->getName($composed);

                if (\is_string($composedName) && $composedName !== ''
                    && $this->sameFileTraitComposes($composedName, $depth + 1, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Laravel constructs in a class outside the convertible hierarchy, with the
     * reason split between an unconfigured-but-resolvable chain and a truly
     * unresolvable one ({@see outsideHierarchyReason()}).
     */
    private function detectConstructsOutsideHierarchy(Class_ $node): ?Node
    {
        $found = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    if ($this->isNames($trait, self::LARAVEL_TEST_TRAITS)) {
                        $found[] = \sprintf('%s trait', (new FullyQualified(
                            (string) $this->getName($trait),
                        ))->getLast());
                    }
                }
            }
        }

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$found): void {
            if ($inner instanceof PropertyFetch
                && $inner->var instanceof Variable
                && $inner->var->name === 'this'
                && $inner->name instanceof Identifier
                && $inner->name->toString() === 'app') {
                $found[] = '$this->app';
            }

            if ($inner instanceof Node\Name
                && $this->isName($inner, 'Illuminate\Testing\TestResponse')) {
                $found[] = 'TestResponse';
            }
        });

        if ($found === []) {
            return null;
        }

        ResidualMarker::mark(
            $node,
            ResidualCode::LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY,
            static::class,
            'Laravel constructs outside a convertible hierarchy ('
            . \implode(', ', \array_values(\array_unique($found)))
            . ') — ' . $this->outsideHierarchyReason($node),
        );

        return $node;
    }

    /**
     * Why the constructs sit outside a convertible hierarchy, decided by the extends
     * chain: a chain that resolves into the configured hierarchy through project
     * bases missing from `base_classes` is a configuration gap with a mechanical fix.
     * The marker names every missing base and gives the remediation in the order the
     * user must perform it — add the repeated `--base-class` CLI options (the
     * canonical shell-safe forward-slash form, canonicalized by the command) or add
     * these names to the `base_classes` config, remove this residual marker, then
     * re-run —
     * because the stale marker would keep the class visibly residual after the
     * configuration is fixed. Any chain that cannot be proven to reach the hierarchy
     * keeps the conservative wording.
     */
    private function outsideHierarchyReason(Class_ $node): string
    {
        $parentName = $node->extends === null ? null : $this->getName($node->extends);

        $unconfigured = \is_string($parentName) && $parentName !== ''
            ? $this->resolvableButUnconfiguredBases($parentName)
            : null;

        if ($unconfigured === null || $unconfigured === []) {
            return 'base class does not resolve; migrate manually';
        }

        return \sprintf(
            'project base(s) %s resolvable but missing from base_classes: add CLI option(s) %s (or add these names to the base_classes config), remove this residual marker, then re-run to convert the whole chain',
            \implode(', ', $unconfigured),
            \implode(' ', \array_map(
                static fn(string $base): string => '--base-class=' . \str_replace('\\', '/', $base),
                $unconfigured,
            )),
        );
    }

    /**
     * The project classes between the given direct parent and the first configured
     * base, nearest first, when every hop resolves and the chain reaches a configured
     * source base or an already-migrated target form. `null` when the chain cannot be
     * proven to reach the hierarchy: unresolvable parent, cycle, over-deep chain or a
     * dead end below the configured bases — never converted on, diagnosis only.
     *
     * @return list<non-empty-string>|null
     */
    private function resolvableButUnconfiguredBases(string $startName): ?array
    {
        if ($this->isConfiguredOrTargetBase($startName)) {
            return null;
        }

        $unconfigured = [];
        $seen = [];
        $current = $startName;

        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($this->isConfiguredOrTargetBase($current)) {
                return $unconfigured;
            }

            if (isset($seen[$current])) {
                return null;
            }

            $seen[$current] = true;

            $parent = $this->resolveChainClass($current);

            if (! $parent instanceof Class_) {
                return null;
            }

            $unconfigured[] = $current;

            if ($parent->extends === null) {
                return null;
            }

            $next = $this->getName($parent->extends);

            if (! \is_string($next) || $next === '') {
                return null;
            }

            $current = $next;
        }

        return null;
    }

    private function isConfiguredOrTargetBase(string $name): bool
    {
        return \in_array($name, $this->hierarchy->hierarchyBaseNames(), true);
    }

    /**
     * Same-file lookup first, then reflection — the exact resolution order
     * LaravelBaseClassRector uses to prove configured chains, so a base defined in
     * the file currently being processed resolves without autoload.
     */
    private function resolveChainClass(string $className): ?Class_
    {
        $local = (new NodeFinder())->findFirst(
            $this->getFile()->getNewStmts(),
            fn(Node $node): bool => $node instanceof Class_ && $this->isName($node, $className),
        );

        if ($local instanceof Class_) {
            return $local;
        }

        try {
            $resolved = $this->astResolver->resolveClassFromName($className);
        } catch (\Throwable) {
            $resolved = null;
        }

        return $resolved instanceof Class_ ? $resolved : null;
    }
}
