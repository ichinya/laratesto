<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\AstResolver;

/** @internal Classifies only the statically proven common-path signature matrix. */
final class HttpCompatibilityAnalyzer
{
    public const TEST_RESPONSE = 'Illuminate\Testing\TestResponse';

    public const LARAVEL_RESPONSE = 'Laratesto\Testing\LaravelResponse';

    public const ASSERTABLE_JSON = 'Laratesto\Testing\AssertableJson';

    /**
     * Guard against cyclic or pathologically deep extends chains while proving
     * project-parent declarations for `parent::` calls; deeper chains are not
     * provable and fail closed.
     */
    private const MAX_CHAIN_DEPTH = 10;

    private const REQUEST_SIGNATURES = [
        'get' => [1, 2, ['string', 'array']],
        'getJson' => [1, 2, ['string', 'array']],
        'post' => [1, 3, ['string', 'array', 'array']],
        'put' => [1, 3, ['string', 'array', 'array']],
        'patch' => [1, 3, ['string', 'array', 'array']],
        'delete' => [1, 3, ['string', 'array', 'array']],
        'postJson' => [1, 3, ['string', 'array', 'array']],
        'deleteJson' => [1, 3, ['string', 'array', 'array']],
        'call' => [2, 7, ['string', 'string', 'array', 'array', 'array', 'array', 'nullable-string']],
    ];

    // Mirrors the public surface of Laratesto\Testing\InteractsWithLaravel: every
    // helper the converted base really provides. Anything NOT listed here and not
    // declared by the test class itself has no converted equivalent and must fail
    // the preflight (fail-closed), because the migrated class would fatal on it.
    // Laravel verbs the runtime deliberately does not provide (putJson, patchJson,
    // options, optionsJson, head, json) are therefore absent on purpose.
    //
    // session/app/make return something other than the test case, so fluent calls
    // chained onto them must not classify as `$this->` receivers — see
    // NON_FLUENT_HELPERS below.
    private const HELPER_SIGNATURES = [
        'withHeaders' => [1, 1, ['array']],
        'withHeader' => [2, 2, ['string', 'string']],
        'withoutHeader' => [1, 1, ['string']],
        'withToken' => [1, 2, ['string', 'string']],
        'withSession' => [1, 1, ['array']],
        'withCookie' => [2, 2, ['string', 'string']],
        'withCookies' => [1, 1, ['array']],
        'withServerVariables' => [1, 1, ['array']],
        'followingRedirects' => [0, 0, []],
        'from' => [1, 1, ['string']],
        'withoutMiddleware' => [0, 1, ['middleware']],
        'withMiddleware' => [0, 1, ['middleware']],
        'withoutVite' => [0, 0, []],
        'withoutExceptionHandling' => [0, 1, ['array']],
        'withExceptionHandling' => [0, 0, []],
        'mock' => [1, 2, ['string', 'any']],
        'assertModelExists' => [1, 1, ['any']],
        'actingAs' => [1, 2, ['any', 'nullable-string']],
        'actingAsGuest' => [0, 1, ['nullable-string']],
        'assertAuthenticated' => [0, 1, ['nullable-string']],
        'assertGuest' => [0, 1, ['nullable-string']],
        'assertAuthenticatedAs' => [1, 2, ['any', 'nullable-string']],
        'artisan' => [1, 2, ['string', 'array']],
        'travel' => [1, 1, ['int']],
        'assertDatabaseHas' => [2, 3, ['string', 'associative-array', 'nullable-string']],
        'assertDatabaseMissing' => [2, 3, ['string', 'associative-array', 'nullable-string']],
        'assertDatabaseCount' => [2, 3, ['string', 'int', 'nullable-string']],
        'assertSessionHas' => [1, 2, ['string', 'any']],
        'assertSessionMissing' => [1, 1, ['string']],
        'assertSessionHasErrors' => [0, 1, ['array']],
        'assertExitCode' => [2, 3, ['int', 'string', 'array']],
        'session' => [0, 0, []],
        'app' => [0, 0, []],
        'make' => [1, 1, ['any']],
        'expectOutputString' => [1, 1, ['string']],
        'pendingArtisan' => [1, 2, ['string', 'array']],
    ];

    /**
     * Helpers whose runtime return value is not the test case itself. They are
     * validated on `$this` but must not turn chained calls (`$this->make($x)->any()`,
     * `$this->app()->bind()`) into classified `$this->` receivers — the pipeline
     * output itself contains such chains, and flagging them would break byte
     * idempotency on the second run. `travel()` returns a runtime Wormhole whose
     * methods (`$this->travel(5)->days()`) are never rewritten and work as-is.
     */
    private const NON_FLUENT_HELPERS = ['session', 'app', 'make', 'travel', 'mock', 'expectOutputString', 'pendingArtisan'];

    /**
     * PHPUnit `$this->` calls the imported testo/bridge-rector
     * PHPUNIT_TO_TESTO set rewrites unconditionally later in the same Rector run
     * (AssertCallToTestoRector, the unconditional TypedAssertCallToTestoRector
     * cases, ExpectExceptionToTestoRector, MarkTestSkippedToTestoRector,
     * MarkTestIncompleteRector). By the time the migrated class runs, none of
     * them exist anymore, so they must not fail the preflight. The invariant is
     * "upstream provably rewrites THIS call with THESE arguments": calls whose
     * rewrite fires only for a statically provable argument shape are NOT listed
     * here — upstream's emptiness() rewrites assertEmpty/assertNotEmpty only when
     * the subject is statically an array, and a surviving call would fatal on the
     * converted base, which provides no assert surface. Those are pinned to their
     * triggering shape by UPSTREAM_SHAPE_CONDITIONAL_CALLS instead. PHPUnit
     * assertions outside the upstream and local compatibility lists (assertSeeded,
     * ...) are NOT rewritten and stay fail-closed.
     */
    private const UPSTREAM_REWRITTEN_CALLS = [
        'assertSame',
        'assertNotSame',
        'assertEquals',
        'assertNotEquals',
        'assertTrue',
        'assertFalse',
        'assertNull',
        'assertNotNull',
        'assertCount',
        'assertContains',
        'assertInstanceOf',
        'fail',
        'assertGreaterThan',
        'assertGreaterThanOrEqual',
        'assertLessThan',
        'assertLessThanOrEqual',
        'assertArrayHasKey',
        'assertArrayNotHasKey',
        'assertEqualsCanonicalizing',
        'expectException',
        'expectExceptionMessage',
        'expectExceptionCode',
        'markTestSkipped',
        'markTestIncomplete',
    ];

    /**
     * Upstream rewrites that fire only for a statically provable argument shape.
     * TypedAssertCallToTestoRector::emptiness() rewrites assertEmpty/assertNotEmpty
     * into Assert::blank()/notBlank() ONLY when the subject is statically an
     * array; otherwise the call survives onto the converted base, which provides
     * no assert surface, and fatals at runtime. The preflight cannot reproduce
     * Rector's type inference, so it exempts only the shape it can prove without
     * inference — a literal static array subject with a literal string message
     * (Assert::blank() takes `string $message = ''`) — and fails closed on every
     * other subject via HTTP_UNSUPPORTED_SIGNATURE.
     */
    private const UPSTREAM_SHAPE_CONDITIONAL_CALLS = [
        'assertEmpty' => [1, 2, ['array', 'string']],
        'assertNotEmpty' => [1, 2, ['array', 'string']],
    ];

    // Mirrors the public assert/accessor surface of Laratesto\Testing\LaravelResponse
    // signature-for-signature. Methods the runtime provides must never be blocked
    // here: over-blocking inflates manual-migration scope without any safety gain.
    // Laravel-only forms the runtime cannot accept stay fail-closed on purpose
    // (assertJsonStructure() with no structure or with Laravel's second
    // $responseData argument), and so do runtime-only accessors that Laravel's
    // TestResponse never declared (response(), headers(), header(), body(),
    // getSession()).
    private const RESPONSE_SIGNATURES = [
        'assertInertia' => [0, 1, ['any']],
        'assertJsonMissing' => [1, 2, ['array', 'bool']],
        'assertJsonCount' => [1, 2, ['int', 'nullable-string']],
        'assertSessionHasNoErrors' => [0, 0, []],
        'assertCookie' => [1, 4, ['any', 'any', 'any', 'any']],
        'assertServerError' => [0, 0, []],
        'viewData' => [0, 1, ['nullable-string']],
        'inertiaPage' => [0, 1, ['nullable-string']],
        'assertStatus' => [1, 1, ['int']],
        'assertOk' => [0, 0, []],
        'assertJson' => [1, 2, ['array-or-closure', 'bool']],
        'assertJsonPath' => [2, 2, ['string', 'any']],
        'assertHeader' => [1, 2, ['string', 'nullable-string']],
        'assertRedirect' => [0, 1, ['nullable-string']],
        'json' => [0, 1, ['nullable-string']],
        'status' => [0, 0, []],
        'getStatusCode' => [0, 0, []],
        'getContent' => [0, 0, []],
        'assertSee' => [1, 2, ['string-or-array', 'bool']],
        'assertDontSee' => [1, 2, ['string-or-array', 'bool']],
        'assertExactJson' => [1, 1, ['array']],
        'assertHeaderMissing' => [1, 1, ['string']],
        'assertCreated' => [0, 0, []],
        'assertBadRequest' => [0, 0, []],
        'assertUnauthorized' => [0, 0, []],
        'assertForbidden' => [0, 0, []],
        'assertNotFound' => [0, 0, []],
        'assertUnprocessable' => [0, 0, []],
        'assertFound' => [0, 0, []],
        'assertMethodNotAllowed' => [0, 0, []],
        'assertConflict' => [0, 0, []],
        'assertGone' => [0, 0, []],
        'assertInternalServerError' => [0, 0, []],
        'assertTooManyRequests' => [0, 0, []],
        'assertServiceUnavailable' => [0, 0, []],
        'assertContent' => [1, 1, ['string']],
        'assertSessionHas' => [1, 2, ['string-or-array', 'any']],
        'assertSessionMissing' => [1, 2, ['string-or-array', 'any']],
        'assertSessionHasErrors' => [0, 3, ['string-or-array', 'nullable-string', 'string']],
        'assertJsonMissingPath' => [1, 1, ['string']],
        'assertJsonStructure' => [1, 1, ['array']],
        'assertJsonValidationErrors' => [1, 2, ['string-or-array', 'string']],
        'assertViewHas' => [1, 2, ['string-or-array', 'any']],
    ];

    private const PENDING_ARTISAN_SIGNATURES = [
        'assertExitCode' => [1, 1, ['int']],
        'assertSuccessful' => [0, 0, []],
        'assertFailed' => [0, 0, []],
        'expectsOutput' => [1, 1, ['string']],
        'expectsOutputToContain' => [1, 1, ['string']],
        'doesntExpectOutputToContain' => [1, 1, ['string']],
        'exitCode' => [0, 0, []],
        'output' => [0, 0, []],
    ];

    private const RESPONSE_FLUENT_METHODS = [
        'assertInertia', 'assertJsonMissing', 'assertJsonCount',
        'assertSessionHasNoErrors', 'assertCookie', 'assertServerError',
        'assertStatus',
        'assertOk',
        'assertJson',
        'assertJsonPath',
        'assertHeader',
        'assertRedirect',
        'assertSee',
        'assertDontSee',
        'assertExactJson',
        'assertHeaderMissing',
        'assertCreated',
        'assertBadRequest',
        'assertUnauthorized',
        'assertForbidden',
        'assertNotFound',
        'assertUnprocessable',
        'assertFound',
        'assertMethodNotAllowed',
        'assertConflict',
        'assertGone',
        'assertInternalServerError',
        'assertTooManyRequests',
        'assertServiceUnavailable',
        'assertContent',
        'assertSessionHas',
        'assertSessionMissing',
        'assertSessionHasErrors',
        'assertJsonMissingPath',
        'assertJsonStructure',
        'assertJsonValidationErrors',
        'assertViewHas',
    ];

    private const INTERACTIVE_ARTISAN_METHODS = [
        'expectsQuestion',
        'expectsConfirmation',
        'expectsChoice',
        'expectsSearch',
        'expectsTable',
        'expectsPrompts',
        'doesntExpectOutput',
    ];

    private NodeFinder $nodeFinder;

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly AstResolver $astResolver,
    ) {
        $this->nodeFinder = new NodeFinder();
    }

    /**
     * @param list<Class_> $localClasses every class-like declared in the same file
     *        as $class, used to prove TestResponse subclass receivers locally
     */
    public function analyze(Class_ $class, array $localClasses = []): HttpCompatibilityAnalysis
    {
        // $this, self and parent inside a nested class belong to that class.
        // Inspect a private view so neither the analysis nor subsequent rewrites
        // mistake a nested service constructor/assertion for a test helper.
        $class = clone $class;
        $traverser = new \PhpParser\NodeTraverser(new class extends \PhpParser\NodeVisitorAbstract {
            public function enterNode(Node $node): Node
            {
                // Preserve Rector's original-node provenance used by lifecycle
                // validation; CloningVisitor would replace it with renamed nodes.
                $node = clone $node;
                if ($node instanceof Node\Stmt\ClassLike) {
                    $node->stmts = [];
                }
                return $node;
            }
        });
        $class->stmts = $traverser->traverse($class->stmts);
        /** @var array<non-empty-string, list<non-empty-string>> $reasons */
        $reasons = [];
        $responseProperties = $this->responseProperties($class);
        $responseMethods = $this->responseMethods($class);
        $scopedResponseVariables = $this->scopedResponseVariables($class, $responseProperties, $responseMethods);
        $artisanVariables = $this->artisanVariables($class);
        $hasResponseType = $this->classContainsName($class, self::TEST_RESPONSE);
        $declaredMethods = array_map(static fn(Node\Stmt\ClassMethod $method): string => $method->name->toString(), $class->getMethods());
        foreach ($class->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse) {
                foreach ($statement->traits as $trait) {
                    if ($this->nodeNameResolver->isNames($trait, ['Illuminate\Foundation\Testing\WithFaker', 'Laratesto\Testing\WithFaker'])) {
                        $declaredMethods = [...$declaredMethods, 'faker', 'makeFaker', 'setUpFaker'];
                    }
                }
            }
        }
        $this->validateResponseCreation($reasons, $class);

        /** @var list<MethodCall> $calls */
        $calls = $this->nodeFinder->findInstanceOf($class->stmts, MethodCall::class);
        foreach ($calls as $call) {
            $responseVariables = $scopedResponseVariables[$call] ?? [];
            $method = $call->name instanceof Identifier ? $call->name->toString() : null;
            if ($method === null) {
                if ($this->isThisReceiver($call->var)
                    || $this->isResponseReceiver($call->var, $responseVariables, $responseProperties, $responseMethods)
                    || $this->isArtisanReceiver($call->var, $artisanVariables)) {
                    $this->addReason($reasons, 'HTTP_UNSUPPORTED_SIGNATURE', 'dynamic Laravel helper method cannot be classified');
                }
                continue;
            }

            if ($this->isArtisanReceiver($call->var, $artisanVariables)) {
                if (in_array($method, self::INTERACTIVE_ARTISAN_METHODS, true)) {
                    $this->addReason(
                        $reasons,
                        'ARTISAN_INTERACTION_UNSUPPORTED',
                        sprintf('%s() is an interactive Pending Artisan API', $method),
                    );
                } elseif (isset(self::PENDING_ARTISAN_SIGNATURES[$method])) {
                    $this->validate(
                        $reasons,
                        'HTTP_UNSUPPORTED_SIGNATURE',
                        $method,
                        $call->args,
                        self::PENDING_ARTISAN_SIGNATURES[$method],
                    );
                } else {
                    $this->addReason(
                        $reasons,
                        'HTTP_UNSUPPORTED_SIGNATURE',
                        sprintf('Pending Artisan::%s() is outside the supported matrix', $method),
                    );
                }

                continue;
            }

            if ($this->isThisReceiver($call->var)) {
                if ($this->isGuardedOptionalMethod($class, $call, $method)) {
                    continue;
                }
                if (isset(self::REQUEST_SIGNATURES[$method])) {
                    $this->validate($reasons, 'HTTP_UNSUPPORTED_SIGNATURE', $method, $call->args, self::REQUEST_SIGNATURES[$method]);
                    continue;
                }

                if (isset(self::HELPER_SIGNATURES[$method])) {
                    $this->validate($reasons, 'HTTP_UNSUPPORTED_SIGNATURE', $method, $call->args, self::HELPER_SIGNATURES[$method]);
                    continue;
                }

                // Fail-closed: the converted Laratesto base provides only the two
                // matrices above, and methods declared by the class itself survive
                // conversion. Every other `$this->` call (unknown helper, Laravel
                // TestCase leftover such as putJson/seed) has no converted
                // equivalent and would fatal at runtime. The carve-outs shared with
                // the self::/static:: classification live in
                // classifyAgainstUpstreamRewrites().
                $this->classifyAgainstUpstreamRewrites($reasons, '$this->', $method, $call->args, $declaredMethods);

                continue;
            }

            if ($this->isResponseReceiver($call->var, $responseVariables, $responseProperties, $responseMethods)) {
                if (! isset(self::RESPONSE_SIGNATURES[$method])) {
                    $this->addReason(
                        $reasons,
                        'RESPONSE_UNSUPPORTED_API',
                        sprintf('TestResponse::%s() is outside the supported response matrix', $method),
                    );
                    continue;
                }

                $this->validate($reasons, 'RESPONSE_UNSUPPORTED_API', $method, $call->args, self::RESPONSE_SIGNATURES[$method]);
            }
        }

        // PHPUnit assertions written as self::/static:: (self::assertSeeded())
        // never reach the $this-> pass above, yet the upstream bridge-rector rewrites exactly
        // its matrix for them: a supported call converts, an unsupported one survives onto the
        // converted base and fatals at runtime. Classify them through the same carve-outs, and
        // `parent::` calls ALSO land on the converted base - the class extends the framework
        // TestCase, whose converted form is Laratesto\Testing\LaravelTestCase - but the
        // upstream assert rewrite skips parent receivers entirely, so they classify through
        // the parent-specific carve-outs (classifyParentCall()).
        // The classification runs PER METHOD: the parent lifecycle carve-out only
        // covers a parent::setUp()/tearDown() call inside the matching lifecycle
        // override itself - the one location the base rule rewrites. The same
        // call inside any other method survives onto the converted base, which
        // provides no such hook, and fails closed.
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();

            /** @var list<StaticCall> $staticCalls */
            $staticCalls = $this->nodeFinder->findInstanceOf($method->stmts ?? [], StaticCall::class);

            foreach ($staticCalls as $staticCall) {
                $isSelf = $this->nodeNameResolver->isName($staticCall->class, 'self');
                $isStatic = $this->nodeNameResolver->isName($staticCall->class, 'static');
                $isParent = ! $isSelf && ! $isStatic && $this->nodeNameResolver->isName($staticCall->class, 'parent');

                if (! $isSelf && ! $isStatic && ! $isParent) {
                    continue;
                }

                $staticMethod = $staticCall->name instanceof Identifier
                    ? $staticCall->name->toString()
                    : null;

                if ($staticMethod === null) {
                    $this->addReason(
                        $reasons,
                        'HTTP_UNSUPPORTED_SIGNATURE',
                        $isParent
                            ? 'dynamic parent:: method call cannot be classified; the upstream assert rewrite skips parent receivers, so the call would survive onto the converted base'
                            : 'dynamic self/static method cannot be classified',
                    );

                    continue;
                }

                if ($isParent) {
                    $this->classifyParentCall(
                        $reasons,
                        $class,
                        $this->isRewrittenParentLifecycleCall($method, $staticCall),
                        $staticMethod,
                        $staticCall->args,
                        $localClasses,
                    );

                    continue;
                }

                $this->classifyAgainstUpstreamRewrites(
                    $reasons,
                    $isStatic ? 'static::' : 'self::',
                    $staticMethod,
                    $staticCall->args,
                    $declaredMethods,
                );
            }
        }

        /** @var list<PropertyFetch> $properties */
        $properties = $this->nodeFinder->findInstanceOf($class->stmts, PropertyFetch::class);
        foreach ($properties as $property) {
            $responseVariables = $scopedResponseVariables[$property] ?? [];
            if (! $this->isResponseReceiver($property->var, $responseVariables, $responseProperties, $responseMethods)) {
                continue;
            }

            $name = $this->nodeNameResolver->getName($property->name);
            if ($name === null || ! in_array($name, ['headers', 'baseResponse', 'original'], true)) {
                $this->addReason(
                    $reasons,
                    'RESPONSE_UNSUPPORTED_API',
                    sprintf('TestResponse property %s is outside the supported response matrix', $name ?? '<dynamic>'),
                );
            }
        }
        foreach ($this->testResponseSubclasses($class, $localClasses) as $subclass) {
            $this->addReason(
                $reasons,
                'RESPONSE_UNSUPPORTED_API',
                sprintf('%s extends TestResponse, but the Laratesto runtime never produces TestResponse subclasses; migrate the receiver manually', $subclass),
            );
        }

        $this->markPendingArtisanExecutionGaps($reasons, $class, $artisanVariables);

        foreach ($reasons as $code => $messages) {
            $reasons[$code] = array_values(array_unique($messages));
        }

        return new HttpCompatibilityAnalysis($reasons, $hasResponseType);
    }

    /**
     * @param array<non-empty-string, list<non-empty-string>> $reasons
     * @param array{int, int, list<non-empty-string>} $signature
     * @param list<Arg> $arguments
     */
    private function validate(array &$reasons, string $code, string $method, array $arguments, array $signature): void
    {
        [$minimum, $maximum, $types] = $signature;
        $count = count($arguments);

        if ($count < $minimum || $count > $maximum) {
            $this->addReason($reasons, $code, sprintf('%s() expects %d..%d common-path arguments; got %d', $method, $minimum, $maximum, $count));
            return;
        }

        foreach ($arguments as $position => $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                $this->addReason($reasons, $code, sprintf('%s() uses named or unpacked arguments outside the automatic matrix', $method));
                return;
            }

            if ($argument->name !== null) {
                $runtime = $code === 'RESPONSE_UNSUPPORTED_API' ? \Laratesto\Testing\LaravelResponse::class
                    : (method_exists(\Laratesto\Testing\InteractsWithLaravel::class, $method)
                        ? \Laratesto\Testing\InteractsWithLaravel::class : \Laratesto\Testing\PendingArtisanCommand::class);
                $parameters = (new \ReflectionMethod($runtime, $method))->getParameters();
                $position = array_search($argument->name->toString(), array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $parameters), true);
                if ($position === false) {
                    $this->addReason($reasons, $code, sprintf('%s() uses named or unpacked arguments outside the automatic matrix', $method));
                    return;
                }
            }

            if (! $this->matchesShape($argument->value, $types[$position] ?? 'any')
                && !$this->preservesParameterType($method, $position, $code, $types[$position] ?? 'any')) {
                $this->addReason(
                    $reasons,
                    $code,
                    sprintf('%s() argument %d is not a statically supported %s', $method, $position + 1, $types[$position] ?? 'value'),
                );
            }
        }
    }

    private function preservesParameterType(string $method, int $position, string $code, string $shape): bool
    {
        if (!in_array($shape, ['string', 'nullable-string', 'int', 'bool', 'array'], true)) {
            return false;
        }
        $source = $code === 'RESPONSE_UNSUPPORTED_API' ? self::TEST_RESPONSE : 'Illuminate\Foundation\Testing\TestCase';
        $target = $code === 'RESPONSE_UNSUPPORTED_API' ? self::LARAVEL_RESPONSE : 'Laratesto\Testing\InteractsWithLaravel';
        if ($source === 'Illuminate\Foundation\Testing\TestCase' && !class_exists(\PHPUnit\Framework\TestCase::class)) {
            return false;
        }
        if (!method_exists($source, $method) || !method_exists($target, $method)) {
            return false;
        }
        $original = (new \ReflectionMethod($source, $method))->getParameters()[$position] ?? null;
        $converted = (new \ReflectionMethod($target, $method))->getParameters()[$position] ?? null;
        // An unchanged typed parameter performs exactly the same PHP caller-side
        // coercion/check for dynamic expressions, even when PHPStan reports mixed.
        return $original !== null && $converted !== null && $original->hasType()
            && (string) $original->getType() === (string) $converted->getType();
    }

    /**
     * Fail-closed classification shared by the `$this->` and `self::`/`static::`
     * receivers: the converted Laratesto base provides no assert surface, so any
     * call the upstream bridge-rector leaves untouched would fatal at runtime.
     * Three carve-outs keep the classification aligned with what the pipeline
     * really produces: methods the class itself declares survive conversion,
     * upstream rewrites that fire for every argument shape pass
     * (UPSTREAM_REWRITTEN_CALLS), and the shape-conditional emptiness rewrites
     * stay pinned to their provably-static-array triggering shape
     * (UPSTREAM_SHAPE_CONDITIONAL_CALLS).
     *
     * @param array<non-empty-string, list<non-empty-string>> $reasons
     * @param non-empty-string $receiver `$this->`, `self::` or `static::`
     * @param list<Arg> $arguments
     * @param list<non-empty-string> $declaredMethods
     */
    private function classifyAgainstUpstreamRewrites(
        array &$reasons,
        string $receiver,
        string $method,
        array $arguments,
        array $declaredMethods,
    ): void {
        if (in_array($method, $declaredMethods, true)) {
            return;
        }

        // The public set also installs the local source-compatible assertion rules.
        if (\Laratesto\Rector\Rules\PhpUnitCompatibilityRector::supportsCall($method, $arguments)) {
            return;
        }

        if (isset(self::UPSTREAM_SHAPE_CONDITIONAL_CALLS[$method])) {
            $this->validate(
                $reasons,
                'HTTP_UNSUPPORTED_SIGNATURE',
                $method,
                $arguments,
                self::UPSTREAM_SHAPE_CONDITIONAL_CALLS[$method],
            );

            return;
        }

        if (! in_array($method, self::UPSTREAM_REWRITTEN_CALLS, true)) {
            $this->addReason(
                $reasons,
                'HTTP_UNSUPPORTED_SIGNATURE',
                sprintf('%s%s() is outside the supported helper matrix', $receiver, $method),
            );
        }
    }

    /**
     * `parent::` calls land on the converted base: the analyzed class extends the
     * framework TestCase, whose converted form is Laratesto\Testing\LaravelTestCase
     * (or a converted project base below it), and the upstream assert rewrite skips
     * parent receivers entirely. Three carve-outs stay supported, everything else
     * fails closed:
     *
     * 1. setUp()/tearDown() - but ONLY when the call sits inside the MATCHING
     *    lifecycle override, as a direct statement with the exact hook name: that exact
     *    statement location is what the base rule rewrites (the framework
     *    parent call is dropped on a direct framework parent, or renamed to
     *    setUpLaravel()/tearDownLaravel() below a converted project base).
     *    The same call from any other method is not rewritten and fails closed.
     * 1b. The converted base's own helper/request surface, which the
     *    HELPER_SIGNATURES/REQUEST_SIGNATURES matrices mirror exactly.
     * 1c. A method provably declared by a PROJECT class in the parent chain
     *    (same-file or autoload-resolvable): project-declared methods survive
     *    conversion. The walk stops at the framework TestCase itself - its
     *    surface does not survive - and returns not-provable on unresolvable
     *    hops, which fails closed.
     *
     * @param array<non-empty-string, list<non-empty-string>> $reasons
     * @param list<Arg> $arguments
     * @param list<Class_> $localClasses
     */
    private function classifyParentCall(
        array &$reasons,
        Class_ $class,
        bool $rewrittenLifecycleCall,
        string $method,
        array $arguments,
        array $localClasses,
    ): void
    {
        if ($rewrittenLifecycleCall) {
            return;
        }

        if (isset(self::REQUEST_SIGNATURES[$method])) {
            $this->validate($reasons, 'HTTP_UNSUPPORTED_SIGNATURE', $method, $arguments, self::REQUEST_SIGNATURES[$method]);

            return;
        }

        if (isset(self::HELPER_SIGNATURES[$method])) {
            $this->validate($reasons, 'HTTP_UNSUPPORTED_SIGNATURE', $method, $arguments, self::HELPER_SIGNATURES[$method]);

            return;
        }

        if (! in_array(strtolower($method), ['setup', 'teardown'], true)
            && $this->parentChainDeclares($class, $method, $localClasses) === true) {
            return;
        }

        $this->addReason(
            $reasons,
            'HTTP_UNSUPPORTED_SIGNATURE',
            sprintf('parent::%s() lands on the converted Laratesto base, which provides no such method; the upstream assert rewrite skips parent receivers - migrate manually', $method),
        );
    }

    private function isRewrittenParentLifecycleCall(Node\Stmt\ClassMethod $method, StaticCall $call): bool
    {
        $name = $method->name->toString();
        $originalMethod = $method->getAttribute(AttributeKey::ORIGINAL_NODE);
        $originalCall = $call->getAttribute(AttributeKey::ORIGINAL_NODE);
        // Another worker can still reflect the parent's original setUp/tearDown
        // after this class has been converted. Recognize the actual rename from
        // the original AST, without trusting a target hook written in the input.
        $renamed = $originalMethod instanceof Node\Stmt\ClassMethod
            && $originalCall instanceof StaticCall
            && in_array($originalMethod->name->toString(), ['setUp', 'tearDown'], true)
            && $name === $originalMethod->name->toString() . 'Laravel'
            && $this->nodeNameResolver->isName($originalCall->class, 'parent')
            && $this->nodeNameResolver->isName($originalCall->name, $originalMethod->name->toString());
        if ((! in_array($name, ['setUp', 'tearDown'], true) && ! $renamed)
            || ! $this->nodeNameResolver->isName($call->name, $name)) {
            return false;
        }

        foreach ($method->stmts ?? [] as $statement) {
            if ($statement instanceof Node\Stmt\Expression && $statement->expr === $call) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a method is provably declared by a PROJECT class in the parent
     * chain of $class. True: declared before the chain reaches the framework
     * TestCase. False: the chain reached the framework TestCase without a
     * declaration (its surface does not survive conversion). Null: not provable
     * (unresolvable, cyclic, over-deep) - callers fail closed.
     *
     * @param list<Class_> $localClasses
     */
    private function parentChainDeclares(Class_ $class, string $method, array $localClasses): ?bool
    {
        if (! $class->extends instanceof Name) {
            return null;
        }

        $current = $this->nodeNameResolver->getName($class->extends);

        if ($current === null) {
            return null;
        }

        $seen = [];

        for ($depth = 0; $depth < self::MAX_CHAIN_DEPTH; $depth++) {
            if (strcasecmp($current, 'Illuminate\\Foundation\\Testing\\TestCase') === 0) {
                return false;
            }

            // On a fresh second pass the native parent supplies these hooks via
            // InteractsWithLaravel, even when the project base overrides only one.
            // The framework boundary above still rejects target hook names that
            // were written into an unconverted PHPUnit hierarchy.
            if (strcasecmp($current, 'Laratesto\\Testing\\LaravelTestCase') === 0
                && in_array(strtolower($method), ['setuplaravel', 'teardownlaravel'], true)) {
                return true;
            }

            if (isset($seen[$current])) {
                return null;
            }

            $seen[$current] = true;

            $resolved = null;

            foreach ($localClasses as $local) {
                if ($local->namespacedName !== null
                    && strcasecmp($local->namespacedName->toString(), $current) === 0) {
                    $resolved = $local;

                    break;
                }
            }

            if ($resolved === null) {
                try {
                    $resolved = $this->astResolver->resolveClassFromName($current);
                } catch (\Throwable) {
                    $resolved = null;
                }
            }

            if (! $resolved instanceof Class_) {
                return null;
            }

            foreach ($resolved->getMethods() as $declared) {
                if (strcasecmp($declared->name->toString(), $method) === 0) {
                    return true;
                }
            }

            if (! $resolved->extends instanceof Name) {
                return null;
            }

            $parent = $this->nodeNameResolver->getName($resolved->extends);

            if ($parent === null) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    /** @param array<non-empty-string, list<non-empty-string>> $reasons */
    private function validateResponseCreation(array &$reasons, Class_ $class): void
    {
        foreach ($this->nodeFinder->findInstanceOf($class->stmts, StaticCall::class) as $call) {
            if (! $this->nodeNameResolver->isName($call->class, self::TEST_RESPONSE)) {
                continue;
            }

            $this->addReason($reasons, 'RESPONSE_UNSUPPORTED_API', sprintf(
                'TestResponse::%s() has no Laratesto static equivalent; preserve the Laravel response type and migrate manually',
                $call->name instanceof Identifier ? $call->name->toString() : '<dynamic>',
            ));
        }

        foreach ($this->nodeFinder->findInstanceOf($class->stmts, Expr\New_::class) as $construction) {
            if (! $this->nodeNameResolver->isName($construction->class, self::TEST_RESPONSE)) {
                continue;
            }

            $argument = $construction->args[0] ?? null;
            if (count($construction->args) === 1
                && $argument instanceof Arg
                && ! $argument->unpack
                && ($argument->name === null || $argument->name->toString() === 'response')
                && $this->isSymfonyResponse($argument->value)) {
                continue;
            }

            $this->addReason($reasons, 'RESPONSE_UNSUPPORTED_API',
                'new TestResponse() requires exactly one proven Symfony Response argument for conversion; preserve the Laravel response type and migrate manually');
        }
    }

    private function isSymfonyResponse(Expr $expression): bool
    {
        if ($expression instanceof Expr\New_) {
            $className = $this->nodeNameResolver->getName($expression->class);
            if (\in_array($className, [
                'Illuminate\\Http\\Response',
                'Illuminate\\Http\\JsonResponse',
                'Illuminate\\Http\\RedirectResponse',
                'Symfony\\Component\\HttpFoundation\\Response',
                'Symfony\\Component\\HttpFoundation\\JsonResponse',
                'Symfony\\Component\\HttpFoundation\\RedirectResponse',
            ], true)) {
                return true;
            }
        }

        $scope = $expression->getAttribute(AttributeKey::SCOPE);
        return $scope instanceof Scope
            && (new ObjectType('Symfony\\Component\\HttpFoundation\\Response'))
                ->isSuperTypeOf($scope->getNativeType($expression))->yes();
    }

    private function matchesShape(Expr $expression, string $shape): bool
    {
        if ($this->matchesInferredShape($expression, $shape)) {
            return true;
        }
        return match ($shape) {
            'any' => true,
            'string' => $expression instanceof Scalar\String_,
            'nullable-string' => $expression instanceof Scalar\String_ || $this->isConst($expression, 'null'),
            'int' => $expression instanceof Scalar\Int_,
            'bool' => $this->isConst($expression, 'true') || $this->isConst($expression, 'false'),
            'array' => $expression instanceof Expr\Array_ && $this->arrayIsStatic($expression),
            'string-or-array' => $expression instanceof Scalar\String_
                || ($expression instanceof Expr\Array_ && $this->arrayIsStatic($expression)),
            'array-or-closure' => ($expression instanceof Expr\Array_ && $this->arrayIsStatic($expression))
                || $this->isAssertJsonCallable($expression),
            'associative-array' => $expression instanceof Expr\Array_
                && $this->arrayIsStatic($expression)
                && ($expression->items === [] || $this->arrayHasOnlyExplicitKeys($expression)),
            'middleware' => $this->isConst($expression, 'null')
                || $expression instanceof Scalar\String_
                || ($expression instanceof Expr\Array_ && $this->arrayIsStatic($expression)),
            default => false,
        };
    }

    private function matchesInferredShape(Expr $expression, string $shape): bool
    {
        $scope = $expression->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope) {
            return false;
        }
        $type = $scope->getType($expression);
        $string = $type->isString()->yes();
        $array = $type->isArray()->yes();
        return match ($shape) {
            'string' => $string,
            'int' => $type->isInteger()->yes(),
            'bool' => $type->isBoolean()->yes(),
            'array' => $array,
            'nullable-string' => (new \PHPStan\Type\UnionType([new \PHPStan\Type\StringType(), new \PHPStan\Type\NullType()]))->isSuperTypeOf($type)->yes(),
            'string-or-array', 'middleware' => $string || $array,
            'array-or-closure' => $array,
            default => false,
        };
    }

    private function isGuardedOptionalMethod(Class_ $class, MethodCall $call, string $method): bool
    {
        if (!in_array($method, ['markConfigCached', 'markRoutesCached'], true)) {
            return false;
        }
        // Preserve optional hooks such as method_exists($this, 'markRoutesCached').
        // Only an exact positive guard in the containing if body proves this safe.
        foreach ($this->nodeFinder->findInstanceOf($class->stmts, Node\Stmt\If_::class) as $if) {
            if (!$this->nodeFinder->findFirst($if->stmts, static fn(Node $node): bool => $node === $call)) {
                continue;
            }
            $conditions = [$if->cond];
            while ($condition = array_pop($conditions)) {
                if ($condition instanceof Expr\BinaryOp\BooleanAnd || $condition instanceof Expr\BinaryOp\LogicalAnd) {
                    $conditions[] = $condition->left;
                    $conditions[] = $condition->right;
                } elseif ($condition instanceof Expr\FuncCall
                    && $condition->name instanceof Name && $this->nodeNameResolver->isName($condition->name, 'method_exists')
                    && count($condition->args) === 2
                    && $this->isThisVariable($condition->args[0]->value)
                    && $condition->args[1]->value instanceof Scalar\String_
                    && strcasecmp($condition->args[1]->value->value, $method) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Runtime LaravelResponse::assertJson() hands the callable the ported
     * Laratesto\Testing\AssertableJson. A closure typed against Laravel's fluent
     * class instead would TypeError at runtime, so only closures without a
     * first-parameter type — or with that parameter typed as the ported class —
     * count as supported.
     */
    private function isAssertJsonCallable(Expr $expression): bool
    {
        if (! $expression instanceof Expr\Closure && ! $expression instanceof Expr\ArrowFunction) {
            return false;
        }

        $firstParameter = $expression->params[0] ?? null;
        if ($firstParameter === null || $firstParameter->type === null) {
            return true;
        }

        if (! $firstParameter->type instanceof Name) {
            return false;
        }

        return strcasecmp($this->resolveTypeName($firstParameter->type), self::ASSERTABLE_JSON) === 0;
    }

    private function resolveTypeName(Name $name): string
    {
        $scope = $name->getAttribute(AttributeKey::SCOPE);

        return $scope instanceof Scope ? $scope->resolveName($name) : $name->toString();
    }

    private function arrayIsStatic(Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item === null || $item->unpack) {
                return false;
            }
        }

        return true;
    }

    private function arrayHasOnlyExplicitKeys(Expr\Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                return false;
            }
        }

        return true;
    }

    private function isConst(Expr $expression, string $name): bool
    {
        return $expression instanceof Expr\ConstFetch
            && strcasecmp($expression->name->toString(), $name) === 0;
    }

    /**
     * @param list<non-empty-string> $responseProperties
     * @param list<non-empty-string> $responseMethods
     * @return list<non-empty-string>
     */
    private function responseVariables(Class_ $class, array $responseProperties, array $responseMethods): array
    {
        $variables = [];

        /** @var list<Expr\Assign> $assignments */
        $assignments = $this->nodeFinder->findInstanceOf($class->stmts, Expr\Assign::class);
        foreach ($assignments as $assignment) {
            if (! $assignment->var instanceof Variable || ! is_string($assignment->var->name)) {
                continue;
            }

            if ($this->isResponseProducingExpression(
                $assignment->expr,
                $variables,
                $responseProperties,
                $responseMethods,
            )) {
                $variables[] = $assignment->var->name;
            }
        }

        foreach ($class->getMethods() as $method) {
            foreach ($method->params as $parameter) {
                if ($parameter->var instanceof Variable
                    && is_string($parameter->var->name)
                    && $this->typeContainsTestResponse($parameter->type)) {
                    $variables[] = $parameter->var->name;
                }
            }
        }

        return array_values(array_unique($variables));
    }

    /** @return \WeakMap<Node, list<string>> Response variable names in each lexical scope. */
    private function scopedResponseVariables(Class_ $class, array $properties, array $methods): \WeakMap
    {
        $map = new \WeakMap();
        $variablesForMethod = function (Node\Stmt\ClassMethod $method) use ($properties, $methods): array {
            $view = new Class_(null);
            $view->stmts = [$method];
            return $this->responseVariables($view, $properties, $methods);
        };
        $isResponseType = fn(?Node $type): bool => $this->typeContainsTestResponse($type);
        $visitor = new class($map, $variablesForMethod, $isResponseType) extends \PhpParser\NodeVisitorAbstract {
            private array $variables = [];
            private array $stack = [];
            public function __construct(private \WeakMap $map, private \Closure $forMethod, private \Closure $isResponseType) {}
            public function enterNode(Node $node): void
            {
                if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                    $this->stack[] = $this->variables;
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        $this->variables = ($this->forMethod)($node);
                    } else {
                        foreach ($node->params as $parameter) {
                            if ($parameter->var instanceof Variable && is_string($parameter->var->name)) {
                                $this->variables = array_values(array_diff($this->variables, [$parameter->var->name]));
                                if (($this->isResponseType)($parameter->type)) {
                                    $this->variables[] = $parameter->var->name;
                                }
                            }
                        }
                    }
                }
                $this->map[$node] = $this->variables;
            }
            public function leaveNode(Node $node): void
            {
                if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) {
                    $this->variables = array_pop($this->stack);
                }
            }
        };
        (new \PhpParser\NodeTraverser($visitor))->traverse($class->stmts);
        return $map;
    }

    private function isResponseProducingExpression(
        Expr $expression,
        array $responseVariables,
        array $responseProperties,
        array $responseMethods,
    ): bool
    {
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $responseVariables, true);
        }

        if ($expression instanceof PropertyFetch) {
            return $this->isResponseReceiver($expression, $responseVariables, $responseProperties, $responseMethods);
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;

        if ($method !== null
            && (($this->isThisVariable($expression->var) && in_array($method, $responseMethods, true))
                || (isset(self::REQUEST_SIGNATURES[$method]) && $this->isThisReceiver($expression->var)))) {
            return true;
        }

        return $method !== null
            && in_array($method, self::RESPONSE_FLUENT_METHODS, true)
            && $this->isResponseReceiver($expression->var, $responseVariables, $responseProperties, $responseMethods);
    }

    /**
     * @param list<non-empty-string> $responseVariables
     * @param list<non-empty-string> $responseProperties
     * @param list<non-empty-string> $responseMethods
     */
    private function isResponseReceiver(
        Expr $expression,
        array $responseVariables,
        array $responseProperties,
        array $responseMethods,
    ): bool
    {
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $responseVariables, true);
        }

        if ($expression instanceof PropertyFetch
            && $this->isThisVariable($expression->var)
            && $expression->name instanceof Node\Identifier) {
            return in_array($expression->name->toString(), $responseProperties, true);
        }

        if ($expression instanceof StaticCall
            && $this->nodeNameResolver->isName($expression->class, 'parent')
            && $expression->name instanceof Identifier
            && isset(self::REQUEST_SIGNATURES[$expression->name->toString()])) {
            // `parent::get()`/`parent::post()`-style calls produce a response on
            // the converted base exactly like their `$this->` siblings.
            return true;
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;
        if ($method !== null && $this->isThisVariable($expression->var) && in_array($method, $responseMethods, true)) {
            return true;
        }

        if ($method !== null && isset(self::REQUEST_SIGNATURES[$method]) && $this->isThisReceiver($expression->var)) {
            return true;
        }

        return $this->isResponseReceiver($expression->var, $responseVariables, $responseProperties, $responseMethods);
    }

    /** @return list<non-empty-string> */
    private function responseProperties(Class_ $class): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if (! $this->typeContainsTestResponse($property->type)) {
                continue;
            }

            foreach ($property->props as $item) {
                $properties[] = $item->name->toString();
            }
        }

        return array_values(array_unique($properties));
    }

    /** @return list<non-empty-string> */
    private function responseMethods(Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            if ($this->typeContainsTestResponse($method->returnType)) {
                $methods[] = $method->name->toString();
            }
        }

        return array_values(array_unique($methods));
    }

    private function typeContainsTestResponse(Node|null $type): bool
    {
        if ($type instanceof Name) {
            return $this->nodeNameResolver->isName($type, self::TEST_RESPONSE);
        }

        if ($type instanceof Node\NullableType) {
            return $this->typeContainsTestResponse($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as $member) {
                if ($this->typeContainsTestResponse($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * TestResponse subclasses used in receiver type positions (properties, method
     * return types, method parameters). The conversion pipeline only swaps the
     * exact TestResponse type, and the runtime `get()`/`post()`-style helpers only
     * ever produce the final Laratesto response object - a subclass-typed receiver
     * can therefore never bind at runtime, and its calls must not pass silently.
     *
     * @param list<Class_> $localClasses
     * @return list<string>
     */
    private function testResponseSubclasses(Class_ $class, array $localClasses): array
    {
        $types = [];
        foreach ($class->getProperties() as $property) {
            $types[] = $property->type;
        }
        foreach ($class->getMethods() as $method) {
            $types[] = $method->returnType;
            foreach ($method->params as $parameter) {
                $types[] = $parameter->type;
            }
        }

        $subclasses = [];
        foreach ($types as $type) {
            foreach ($this->namesInType($type) as $name) {
                $resolved = $this->nodeNameResolver->getName($name);
                if ($resolved === null
                    || $this->nodeNameResolver->isName($name, self::TEST_RESPONSE)
                    || ! $this->extendsTestResponse($resolved, $localClasses)) {
                    continue;
                }

                $subclasses[] = $resolved;
            }
        }

        return array_values(array_unique($subclasses));
    }

    /** @return list<Name> */
    private function namesInType(Node|null $type): array
    {
        if ($type instanceof Name) {
            return [$type];
        }

        if ($type instanceof Node\NullableType) {
            return $this->namesInType($type->type);
        }

        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            $names = [];
            foreach ($type->types as $member) {
                foreach ($this->namesInType($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }

    /**
     * Walks the extends chain through locally declared classes first, then through
     * AstResolver (processed-file classes and vendor classes). Unresolvable names
     * are not flagged: the classification stays exactly as provable as before.
     *
     * @param list<Class_> $localClasses
     */
    private function extendsTestResponse(string $className, array $localClasses): bool
    {
        $seen = [];
        $current = $className;
        while ($current !== null && ! isset($seen[$current])) {
            $seen[$current] = true;

            if (strcasecmp($current, self::TEST_RESPONSE) === 0) {
                return true;
            }

            $parent = null;
            foreach ($localClasses as $local) {
                if ($local->namespacedName !== null
                    && $local->namespacedName->toString() === $current
                    && $local->extends instanceof Name) {
                    $parent = $local->extends;
                    break;
                }
            }

            if ($parent === null) {
                try {
                    $resolved = $this->astResolver->resolveClassFromName($current);
                } catch (\Throwable) {
                    $resolved = null;
                }

                $parent = $resolved instanceof Class_ ? $resolved->extends : null;
            }

            if (! $parent instanceof Name) {
                return false;
            }

            $current = $this->nodeNameResolver->getName($parent);
        }

        return false;
    }

    /**
     * Laravel retains PendingCommand until destruction: even assertExitCode()
     * merely stores an expectation. Only a terminal sequence of expectation
     * calls on a local command is safe. Branches are not PHP variable scopes;
     * their locals survive into following statements, loop iterations and finally.
     * Closures have their own lifetime and are inspected independently.
     *
     * @param array<non-empty-string, list<non-empty-string>> $reasons
     * @param list<non-empty-string> $artisanVariables
     */
    private function markPendingArtisanExecutionGaps(array &$reasons, Class_ $class, array $artisanVariables): void
    {
        foreach ($class->getMethods() as $method) {
            $this->inspectPendingStatements($reasons, $method->stmts ?? [], $artisanVariables, false, $this->escapingPendingVariables($method));
        }
    }

    /** @param list<Node\Stmt> $statements */
    private function inspectPendingStatements(array &$reasons, array $statements, array $variables, bool $hasContinuation, array $escapingVariables = []): void
    {
        foreach ($statements as $index => $statement) {
            $following = array_slice($statements, $index + 1);
            if ($statement instanceof Node\Stmt\Expression
                && $statement->expr instanceof Expr\Assign
                && $statement->expr->var instanceof Variable
                && is_string($statement->expr->var->name)
                && $this->isArtisanProducingExpression($statement->expr->expr, $variables)) {
                $name = $statement->expr->var->name;
                $safe = ! $hasContinuation && ! in_array($name, $escapingVariables, true);
                foreach ($following as $next) {
                    $safe = $safe && $next instanceof Node\Stmt\Expression
                        && $this->isPendingExpectation($next->expr, $name);
                }
                if (! $safe) {
                    $this->markPendingGap($reasons, $name);
                }
                // The direct assignment was just classified. Inspect its RHS for
                // independent closure scopes without reclassifying the assignment.
                $this->inspectPendingNode($reasons, $statement->expr->expr, $variables, true, $escapingVariables);
                continue;
            }
            $this->inspectPendingNode($reasons, $statement, $variables, $hasContinuation || $following !== [], $escapingVariables);
        }
    }

    private function inspectPendingNode(array &$reasons, Node $node, array $variables, bool $hasContinuation, array $escapingVariables = []): void
    {
        if ($node instanceof Expr\Closure) {
            $this->inspectPendingStatements($reasons, $node->stmts, $variables, false, $this->escapingPendingVariables($node));
            return;
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            return;
        }
        if (($node instanceof Node\Stmt\Return_ || $node instanceof Expr\ArrowFunction)
            && $node->expr instanceof Expr && $this->isArtisanProducingExpression($node->expr, $variables)) {
            $this->markPendingGap($reasons, 'command');
        }
        if ($node instanceof Expr\Assign && $this->isArtisanProducingExpression($node->expr, $variables)) {
            $this->markPendingGap($reasons, $node->var instanceof Variable && is_string($node->var->name) ? $node->var->name : 'command');
        }

        // A loop may keep its last command alive into the next iteration. A try
        // body may continue through catch/finally before its locals are released.
        $hasContinuation = $hasContinuation || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_ || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_ || $node instanceof Node\Stmt\TryCatch
            || $node instanceof Node\Stmt\Switch_;
        foreach ($node->getSubNodeNames() as $key) {
            $child = $node->$key;
            if ($key === 'stmts' && is_array($child)) {
                $this->inspectPendingStatements($reasons, $child, $variables, $hasContinuation, $escapingVariables);
            } elseif ($child instanceof Node) {
                $this->inspectPendingNode($reasons, $child, $variables, $hasContinuation, $escapingVariables);
            } elseif (is_array($child)) {
                foreach ($child as $item) {
                    if ($item instanceof Node) {
                        $this->inspectPendingNode($reasons, $item, $variables, $hasContinuation, $escapingVariables);
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function escapingPendingVariables(Node\Stmt\ClassMethod|Expr\Closure $function): array
    {
        $escaping = [];
        foreach ($function->params as $parameter) {
            if ($parameter->byRef && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $escaping[] = $parameter->var->name;
            }
        }
        if ($function instanceof Expr\Closure) {
            foreach ($function->uses as $use) {
                if ($use->byRef && is_string($use->var->name)) {
                    $escaping[] = $use->var->name;
                }
            }
        }
        $aliases = [];
        foreach ($function->stmts ?? [] as $statement) {
            $this->collectPendingReferences($statement, $escaping, $aliases);
        }
        do {
            $count = count($escaping);
            foreach ($aliases as [$left, $right]) {
                if (in_array($left, $escaping, true) || in_array($right, $escaping, true)) {
                    $escaping = array_values(array_unique([...$escaping, $left, $right]));
                }
            }
        } while (count($escaping) !== $count);

        return array_values(array_unique($escaping));
    }

    private function collectPendingReferences(Node $node, array &$escaping, array &$aliases): void
    {
        // A nested function has separate local bindings; inspect it when entering
        // that scope, not as if its variable names belonged to the outer method.
        if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
            return;
        }
        if ($node instanceof Node\Stmt\Global_ || $node instanceof Node\Stmt\Static_) {
            foreach ($node->vars as $slot) {
                $variable = $slot instanceof Node\StaticVar ? $slot->var : $slot;
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $escaping[] = $variable->name;
                }
            }
        }
        if ($node instanceof Expr\AssignRef) {
            $left = $node->var instanceof Variable && is_string($node->var->name) ? $node->var->name : null;
            $right = $node->expr instanceof Variable && is_string($node->expr->name) ? $node->expr->name : null;
            if ($left !== null && $right !== null) {
                $aliases[] = [$left, $right];
            } elseif ($left !== null || $right !== null) {
                // A reference to a property/offset cannot prove local lifetime.
                $escaping[] = $left ?? $right;
            }
        }
        foreach ($node->getSubNodeNames() as $key) {
            $children = $node->$key;
            foreach (is_array($children) ? $children : [$children] as $child) {
                if ($child instanceof Node) {
                    $this->collectPendingReferences($child, $escaping, $aliases);
                }
            }
        }
    }

    private function isPendingExpectation(Expr $expression, string $name): bool
    {
        if (! $expression instanceof MethodCall || ! $expression->name instanceof Identifier
            || ! isset(self::PENDING_ARTISAN_SIGNATURES[$expression->name->toString()])) {
            return false;
        }
        // An expression in the arguments can observe side effects before the
        // pending command is released, even in an otherwise terminal assertion.
        foreach ($expression->args as $argument) {
            if (! $argument instanceof Arg || $argument->unpack
                || (! $argument->value instanceof Scalar && ! $argument->value instanceof Expr\ConstFetch)) {
                return false;
            }
        }
        return ($expression->var instanceof Variable && $expression->var->name === $name)
            || $this->isPendingExpectation($expression->var, $name);
    }

    private function markPendingGap(array &$reasons, string $name): void
    {
        $this->addReason($reasons, 'ARTISAN_INTERACTION_UNSUPPORTED', sprintf(
            '$%s retains a Pending Artisan command across statements or control flow; Laravel executes it on release, while Laratesto executes it eagerly', $name,
        ));
    }

    /** @return list<non-empty-string> */
    private function artisanVariables(Class_ $class): array
    {
        $variables = [];

        /** @var list<Expr\Assign> $assignments */
        $assignments = $this->nodeFinder->findInstanceOf($class->stmts, Expr\Assign::class);
        foreach ($assignments as $assignment) {
            if (! $assignment->var instanceof Variable || ! is_string($assignment->var->name)) {
                continue;
            }

            if ($this->isArtisanProducingExpression($assignment->expr, $variables)) {
                $variables[] = $assignment->var->name;
            }
        }

        return array_values(array_unique($variables));
    }

    /** @param list<non-empty-string> $artisanVariables */
    private function isArtisanProducingExpression(Expr $expression, array $artisanVariables): bool
    {
        if ($expression instanceof StaticCall
            && $this->nodeNameResolver->isName($expression->class, 'parent')
            && $this->nodeNameResolver->isName($expression->name, 'artisan')) {
            return true;
        }
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $artisanVariables, true);
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;
        if ($method === 'artisan' && $this->isThisReceiver($expression->var)) {
            return true;
        }

        return $method !== null
            && $method !== 'exitCode'
            && $method !== 'output'
            && $this->isArtisanReceiver($expression->var, $artisanVariables);
    }

    /** @param list<non-empty-string> $artisanVariables */
    private function isArtisanReceiver(Expr $expression, array $artisanVariables): bool
    {
        if ($expression instanceof StaticCall
            && $this->nodeNameResolver->isName($expression->class, 'parent')
            && $this->nodeNameResolver->isName($expression->name, 'artisan')) {
            return true;
        }
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $artisanVariables, true);
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;

        return ($method === 'artisan' && $this->isThisReceiver($expression->var))
            || $this->isArtisanReceiver($expression->var, $artisanVariables);
    }

    private function isThisVariable(Expr $expression): bool
    {
        return $expression instanceof Variable && $expression->name === 'this';
    }

    private function isThisReceiver(Expr $expression): bool
    {
        if ($this->isThisVariable($expression)) {
            return true;
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $expression->name instanceof Identifier ? $expression->name->toString() : null;

        if ($method === null || ! isset(self::HELPER_SIGNATURES[$method])) {
            return false;
        }

        if (in_array($method, self::NON_FLUENT_HELPERS, true)) {
            return false;
        }

        return $this->isThisReceiver($expression->var);
    }

    private function classContainsName(Class_ $class, string $name): bool
    {
        /** @var list<Name> $names */
        $names = $this->nodeFinder->findInstanceOf($class, Name::class);

        foreach ($names as $candidate) {
            if ($this->nodeNameResolver->isName($candidate, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<non-empty-string, list<non-empty-string>> $reasons */
    private function addReason(array &$reasons, string $code, string $reason): void
    {
        $reasons[$code] ??= [];
        $reasons[$code][] = $reason;
    }
}
