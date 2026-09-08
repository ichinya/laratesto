<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Expression;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Enum\NodeGroup;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/** Replaces the upstream exact-message conversion without rewriting native Testo calls. */
final class PhpUnitExceptionExpectationRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Keep PHPUnit exception type and substring message expectations together', [
            new CodeSample('$this->expectException(\RuntimeException::class);', '\Testo\Expect::exception(\RuntimeException::class);'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return NodeGroup::STMTS_AWARE;
    }

    public function refactor(Node $node): ?Node
    {
        $scope = $node->getAttribute(AttributeKey::SCOPE);
        if ($node->stmts === null || !$scope instanceof Scope || !$scope->isInClass()) {
            return null;
        }

        $statements = [];
        $changed = false;
        $count = \count($node->stmts);
        for ($index = 0; $index < $count; ++$index) {
            $statement = $node->stmts[$index];
            $call = $this->sourceCall($statement, 'expectException', 'exception');
            if ($call === null) {
                $statements[] = $statement;
                continue;
            }

            $chain = new StaticCall(new FullyQualified('Testo\Expect'), 'exception', [new Arg($call)]);
            while ($index + 1 < $count) {
                $next = $node->stmts[$index + 1];
                $message = $this->sourceCall($next, 'expectExceptionMessage', 'message');
                $code = $this->sourceCall($next, 'expectExceptionCode', 'code');
                if ($message === null && $code === null) {
                    break;
                }
                if ($message !== null) {
                    $pattern = new StaticCall(new FullyQualified('Laratesto\Testing\PhpUnitCompatibility'), 'exceptionMessagePattern', [new Arg($message)]);
                    $chain = new MethodCall($chain, 'withMessagePattern', [new Arg($pattern)]);
                } else {
                    $chain = new MethodCall($chain, 'withCode', [new Arg($code)]);
                }
                $comments = $next->getComments();
                if ($comments !== []) {
                    $statement->setAttribute('comments', [...$statement->getComments(), ...$comments]);
                }
                ++$index;
            }
            $statement->expr = $chain;
            $statements[] = $statement;
            $changed = true;
        }
        if (!$changed) {
            return null;
        }
        $node->stmts = $statements;
        return $node;
    }

    private function sourceCall(Node $statement, string $method, string $parameter): ?Node\Expr
    {
        if (!$statement instanceof Expression) {
            return null;
        }
        $call = $statement->expr;
        if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
            return null;
        }
        if (!$call->name instanceof Identifier || \strcasecmp($call->name->toString(), $method) !== 0) {
            return null;
        }
        $scope = $call->getAttribute(AttributeKey::SCOPE);
        if ($scope instanceof Scope && $scope->isInClass()) {
            $class = $scope->getClassReflection();
            if ($class->hasNativeMethod($method)
                && !\str_starts_with($class->getNativeMethod($method)->getDeclaringClass()->getName(), 'PHPUnit\\Framework\\')) {
                return null;
            }
        }
        if ($call instanceof MethodCall) {
            if (!$call->var instanceof Variable || $call->var->name !== 'this') {
                return null;
            }
        } elseif (!$call->class instanceof Name || !\in_array(\strtolower($call->class->toString()), ['self', 'static'], true)) {
            return null;
        }
        if (\count($call->args) !== 1 || !$call->args[0] instanceof Arg || $call->args[0]->unpack) {
            return null;
        }
        $argument = $call->args[0];
        if ($argument->name !== null && $argument->name->toString() !== $parameter) {
            return null;
        }
        return $argument->value;
    }
}
