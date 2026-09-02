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
use Rector\NodeNameResolver\NodeNameResolver;

/** @internal Classifies only the statically proven common-path signature matrix. */
final class HttpCompatibilityAnalyzer
{
    public const TEST_RESPONSE = 'Illuminate\Testing\TestResponse';

    public const LARAVEL_RESPONSE = 'Laratesto\Testing\LaravelResponse';

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
    ];

    private const RESPONSE_SIGNATURES = [
        'assertStatus' => [1, 1, ['int']],
        'assertOk' => [0, 0, []],
        'assertJson' => [1, 2, ['array', 'bool']],
        'assertJsonPath' => [2, 2, ['string', 'any']],
        'assertHeader' => [1, 2, ['string', 'nullable-string']],
        'assertRedirect' => [0, 1, ['nullable-string']],
        'json' => [0, 1, ['nullable-string']],
        'status' => [0, 0, []],
        'getStatusCode' => [0, 0, []],
        'getContent' => [0, 0, []],
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
    ) {
        $this->nodeFinder = new NodeFinder();
    }

    public function analyze(Class_ $class): HttpCompatibilityAnalysis
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

                if (! in_array($method, $declaredMethods, true)
                    && (str_starts_with($method, 'with')
                        || str_starts_with($method, 'without'))) {
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
            'associative-array' => $expression instanceof Expr\Array_
                && $this->arrayIsStatic($expression)
                && ($expression->items === [] || $this->arrayHasOnlyExplicitKeys($expression)),
            'middleware' => $this->isConst($expression, 'null')
                || $expression instanceof Scalar\String_
                || ($expression instanceof Expr\Array_ && $this->arrayIsStatic($expression)),
            default => false,
        };
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

        return $method !== null
            && isset(self::HELPER_SIGNATURES[$method])
            && $this->isThisReceiver($expression->var);
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
