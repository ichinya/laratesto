<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/** Preserve straight-line local command lifetimes; escapes remain explicit residuals. */
final class DeferredArtisanRewriter
{
    private const EXPECTATIONS = ['assertExitCode', 'assertSuccessful', 'assertFailed', 'expectsOutput', 'expectsOutputToContain', 'doesntExpectOutputToContain'];

    /** @return list<Expr\MethodCall> Calls to restore if another conversion gate fails. */
    public function rewrite(Stmt\Class_ $class): array
    {
        $changed = [];
        $finder = new NodeFinder();
        foreach ($class->getMethods() as $method) {
            if ($finder->findFirst($method->stmts ?? [], static fn(Node $n): bool => $n instanceof Node\FunctionLike
                || $n instanceof Stmt\ClassLike || $n instanceof Stmt\If_ || $n instanceof Stmt\TryCatch
                || $n instanceof Stmt\For_ || $n instanceof Stmt\Foreach_ || $n instanceof Stmt\While_
                || $n instanceof Stmt\Do_ || $n instanceof Stmt\Switch_) !== null) {
                continue;
            }
            foreach ($method->stmts ?? [] as $index => $statement) {
                if (!$statement instanceof Stmt\Expression || !$statement->expr instanceof Expr\Assign
                    || !$statement->expr->var instanceof Expr\Variable || !is_string($statement->expr->var->name)) {
                    continue;
                }
                $assignment = $statement->expr;
                $call = $assignment->expr;
                if (!$call instanceof Expr\MethodCall || !$call->name instanceof Identifier
                    || strtolower($call->name->toString()) !== 'artisan'
                    || !$call->var instanceof Expr\Variable || $call->var->name !== 'this') {
                    continue;
                }
                $name = $assignment->var->name;
                $allowed = [spl_object_id($assignment->var) => true];
                foreach ($method->stmts ?? [] as $candidate) {
                    $expectation = $candidate instanceof Stmt\Expression ? $candidate->expr : null;
                    // Only discarded expectation chains retain a proven local
                    // lifetime. Returning, assigning or passing a fluent result
                    // can let the command escape through another reference.
                    while ($expectation instanceof Expr\MethodCall && $expectation->name instanceof Identifier
                        && in_array($expectation->name->toString(), self::EXPECTATIONS, true)) {
                        if ($this->isExpectation($expectation, $name)) {
                            $allowed[spl_object_id($expectation->var)] = true;
                        }
                        $expectation = $expectation->var;
                    }
                }
                foreach ($finder->findInstanceOf($method->stmts ?? [], Stmt\Unset_::class) as $unset) {
                    foreach ($unset->vars as $variable) {
                        if ($variable instanceof Expr\Variable && $variable->name === $name) {
                            $allowed[spl_object_id($variable)] = true;
                        }
                    }
                }
                foreach ($finder->findInstanceOf($method, Expr\Variable::class) as $variable) {
                    if ($variable->name === $name && !isset($allowed[spl_object_id($variable)])) {
                        continue 2;
                    }
                }
                $needsDeferral = false;
                foreach (array_slice($method->stmts ?? [], $index + 1) as $following) {
                    $expectation = $following instanceof Stmt\Expression ? $following->expr : null;
                    if (!$this->isExpectation($expectation, $name)) {
                        $needsDeferral = true;
                        break;
                    }
                    foreach ($expectation->args as $argument) {
                        if (!$argument instanceof Node\Arg || $argument->unpack
                            || (!$argument->value instanceof Node\Scalar && !$argument->value instanceof Expr\ConstFetch)) {
                            $needsDeferral = true;
                        }
                    }
                }
                if ($needsDeferral) {
                    $call->name = new Identifier('pendingArtisan');
                    $changed[] = $call;
                }
            }
        }
        return $changed;
    }

    private function isExpectation(?Node $node, string $name): bool
    {
        return $node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable
            && $node->var->name === $name && $node->name instanceof Identifier
            && in_array($node->name->toString(), self::EXPECTATIONS, true);
    }
}
