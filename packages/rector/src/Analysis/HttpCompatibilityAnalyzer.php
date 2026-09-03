<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Analyser\Scope;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\AstResolver;

/** @internal Classifies only the statically proven common-path signature matrix. */
final class HttpCompatibilityAnalyzer
{
    public const TEST_RESPONSE = 'Illuminate\Testing\TestResponse';

    public const LARAVEL_RESPONSE = 'Laratesto\Testing\LaravelResponse';

    public const ASSERTABLE_JSON = 'Laratesto\Testing\AssertableJson';

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
        'assertExitCode' => [1, 3, ['int', 'string', 'array']],
        'session' => [0, 0, []],
        'app' => [0, 0, []],
        'make' => [1, 1, ['any']],
    ];

    /**
     * Helpers whose runtime return value is not the test case itself. They are
     * validated on `$this` but must not turn chained calls (`$this->make($x)->any()`,
     * `$this->app()->bind()`) into classified `$this->` receivers — the pipeline
     * output itself contains such chains, and flagging them would break byte
     * idempotency on the second run. `travel()` returns a runtime Wormhole whose
     * methods (`$this->travel(5)->days()`) are never rewritten and work as-is.
     */
    private const NON_FLUENT_HELPERS = ['session', 'app', 'make', 'travel'];

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
     * assertions outside both lists (assertStringContainsString, assertSeeded,
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
    private const RESPONSE_SIGNATURES = [
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
        'assertSee' => [1, 2, ['string', 'bool']],
        'assertExactJson' => [1, 1, ['array']],
        'assertHeaderMissing' => [1, 1, ['string']],
        'assertCreated' => [0, 0, []],
        'assertBadRequest' => [0, 0, []],
        'assertUnauthorized' => [0, 0, []],
        'assertForbidden' => [0, 0, []],
        'assertNotFound' => [0, 0, []],
        'assertUnprocessable' => [0, 0, []],
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
        'assertStatus',
        'assertOk',
        'assertJson',
        'assertJsonPath',
        'assertHeader',
        'assertRedirect',
        'assertSee',
        'assertExactJson',
        'assertHeaderMissing',
        'assertCreated',
        'assertBadRequest',
        'assertUnauthorized',
        'assertForbidden',
        'assertNotFound',
        'assertUnprocessable',
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
        /** @var array<non-empty-string, list<non-empty-string>> $reasons */
        $reasons = [];
        $responseProperties = $this->responseProperties($class);
        $responseMethods = $this->responseMethods($class);
        $responseVariables = $this->responseVariables($class, $responseProperties, $responseMethods);
        $artisanVariables = $this->artisanVariables($class);
        $hasResponseType = $this->classContainsName($class, self::TEST_RESPONSE);
        $declaredMethods = array_map(static fn(Node\Stmt\ClassMethod $method): string => $method->name->toString(), $class->getMethods());

        /** @var list<MethodCall> $calls */
        $calls = $this->nodeFinder->findInstanceOf($class->stmts, MethodCall::class);
        foreach ($calls as $call) {
            $method = $this->nodeNameResolver->getName($call->name);
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
                // equivalent and would fatal at runtime. Three carve-outs keep the
                // classification aligned with what the pipeline really produces:
                // upstream rewrites that fire for every argument shape, upstream
                // rewrites pinned to their triggering argument shape, and the base
                // helpers the conversion itself emits (make/app/session) validated
                // by the HELPER matrix above.
                if (in_array($method, $declaredMethods, true)) {
                    continue;
                }

                if (isset(self::UPSTREAM_SHAPE_CONDITIONAL_CALLS[$method])) {
                    $this->validate(
                        $reasons,
                        'HTTP_UNSUPPORTED_SIGNATURE',
                        $method,
                        $call->args,
                        self::UPSTREAM_SHAPE_CONDITIONAL_CALLS[$method],
                    );

                    continue;
                }

                if (! in_array($method, self::UPSTREAM_REWRITTEN_CALLS, true)) {
                    $this->addReason(
                        $reasons,
                        'HTTP_UNSUPPORTED_SIGNATURE',
                        sprintf('$this->%s() is outside the supported helper matrix', $method),
                    );
                }

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

        /** @var list<PropertyFetch> $properties */
        $properties = $this->nodeFinder->findInstanceOf($class->stmts, PropertyFetch::class);
        foreach ($properties as $property) {
            if (! $this->isResponseReceiver($property->var, $responseVariables, $responseProperties, $responseMethods)) {
                continue;
            }

            $name = $this->nodeNameResolver->getName($property->name);
            if ($name === null || ! in_array($name, ['headers', 'baseResponse'], true)) {
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
            if ($argument->unpack || $argument->name !== null) {
                $this->addReason($reasons, $code, sprintf('%s() uses named or unpacked arguments outside the automatic matrix', $method));
                return;
            }

            if (! $this->matchesShape($argument->value, $types[$position] ?? 'any')) {
                $this->addReason(
                    $reasons,
                    $code,
                    sprintf('%s() argument %d is not a statically supported %s', $method, $position + 1, $types[$position] ?? 'value'),
                );
            }
        }
    }

    private function matchesShape(Expr $expression, string $shape): bool
    {
        return match ($shape) {
            'any' => true,
            'string' => $expression instanceof Scalar\String_,
            'nullable-string' => $expression instanceof Scalar\String_ || $this->isConst($expression, 'null'),
            'int' => $expression instanceof Scalar\Int_,
            'bool' => $this->isConst($expression, 'true') || $this->isConst($expression, 'false'),
            'array' => $expression instanceof Expr\Array_ && $this->arrayIsStatic($expression),
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

    /**
     * @param list<non-empty-string> $responseVariables
     * @param list<non-empty-string> $responseProperties
     * @param list<non-empty-string> $responseMethods
     */
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

        $method = $this->nodeNameResolver->getName($expression->name);

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

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $this->nodeNameResolver->getName($expression->name);
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
     * Pending Artisan parity: Laravel's PendingCommand is lazy and runs the command
     * on the first assertion (or scope-end), while PendingArtisanCommand executes
     * eagerly in its constructor. Statements between `$pending = $this->artisan()`
     * and the first use of $pending therefore already observe the command's side
     * effects, unlike the Laravel original. Immediate use stays marker-free.
     *
     * @param array<non-empty-string, list<non-empty-string>> $reasons
     * @param list<non-empty-string> $artisanVariables
     */
    private function markPendingArtisanExecutionGaps(
        array &$reasons,
        Class_ $class,
        array $artisanVariables,
    ): void
    {
        if ($artisanVariables === []) {
            return;
        }

        foreach ($class->getMethods() as $method) {
            $awaiting = null;
            foreach ($method->stmts ?? [] as $statement) {
                if ($awaiting !== null) {
                    if (! $this->statementUsesVariable($statement, $awaiting)) {
                        $this->addReason(
                            $reasons,
                            'ARTISAN_INTERACTION_UNSUPPORTED',
                            sprintf('$%s holds an eagerly executed Pending Artisan command; the statements before its first use already observe the command side effects (Laravel PendingCommand runs lazily)', $awaiting),
                        );
                    }

                    $awaiting = null;
                }

                if (! $statement instanceof Node\Stmt\Expression
                    || ! $statement->expr instanceof Expr\Assign
                    || ! $statement->expr->var instanceof Variable
                    || ! is_string($statement->expr->var->name)) {
                    continue;
                }

                if ($this->isArtisanProducingExpression($statement->expr->expr, $artisanVariables)) {
                    $awaiting = $statement->expr->var->name;
                }
            }
        }
    }

    private function statementUsesVariable(Node\Stmt $statement, string $name): bool
    {
        /** @var list<Variable> $variables */
        $variables = $this->nodeFinder->findInstanceOf($statement, Variable::class);
        foreach ($variables as $variable) {
            if ($variable->name === $name) {
                return true;
            }
        }

        return false;
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
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $artisanVariables, true);
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $this->nodeNameResolver->getName($expression->name);
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
        if ($expression instanceof Variable && is_string($expression->name)) {
            return in_array($expression->name, $artisanVariables, true);
        }

        if (! $expression instanceof MethodCall) {
            return false;
        }

        $method = $this->nodeNameResolver->getName($expression->name);

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

        $method = $this->nodeNameResolver->getName($expression->name);

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
