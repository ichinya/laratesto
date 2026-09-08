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
use PhpParser\Node\Scalar\Int_;
use PHPStan\Analyser\Scope;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/** Fills source-compatible assertion gaps in testo/bridge-rector 0.2.4. */
final class PhpUnitCompatibilityRector extends AbstractRector
{
    private const METHODS = [
        'assertStringContainsString', 'assertStringNotContainsString',
        'assertEmpty', 'assertNotEmpty', 'assertIsArray', 'assertNotContains',
        'assertDoesNotMatchRegularExpression',
        'assertIsString', 'assertIsInt', 'assertIsBool', 'assertIsObject', 'assertNotFalse',
        'assertMatchesRegularExpression', 'assertStringStartsWith',
        'assertFileExists', 'assertFileDoesNotExist', 'assertDirectoryExists', 'assertDirectoryDoesNotExist',
        'addToAssertionCount',
        'createStub',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Preserve PHPUnit assertion argument and matching semantics', [
            new CodeSample('$this->assertNotEmpty($value);', '\Laratesto\Testing\PhpUnitCompatibility::assertNotEmpty($value);'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node->name instanceof Identifier || !$this->sourceReceiver($node)) {
            return null;
        }
        $scope = $node->getAttribute(AttributeKey::SCOPE);
        if (!$scope instanceof Scope || !$scope->isInClass()) {
            return null;
        }
        $method = $node->name->toString();
        if (!self::supportsCall($method, $node->args)) {
            return null;
        }
        $class = $scope->getClassReflection();
        if (strtolower($method) === 'createstub' && !$class->hasNativeMethod($method)) {
            // An unrelated class may provide createStub() through __call().
            // Only PHPUnit's actual factory has the semantics we preserve.
            return null;
        }
        if ($class->hasNativeMethod($method)
            && !\str_starts_with($class->getNativeMethod($method)->getDeclaringClass()->getName(), 'PHPUnit\\Framework\\')) {
            return null;
        }
        if (strtolower($method) === 'createstub') {
            if ($node->args[0]->name !== null) {
                $node->args[0]->name = new Identifier('originalClassName');
            }
            $node->args[] = new Arg(new Node\Expr\ClassConstFetch(new Name('static'), 'class'), name: new Identifier('testClass'));
        }
        return new StaticCall(new FullyQualified('Laratesto\Testing\PhpUnitCompatibility'), $node->name, $node->args);
    }

    /** @param array<Arg|Node\VariadicPlaceholder> $arguments */
    public static function supportsCall(string $method, array $arguments): bool
    {
        $method = \strtolower($method);
        if (!\in_array($method, \array_map('strtolower', self::METHODS), true)) {
            return false;
        }
        foreach ($arguments as $argument) {
            if (!$argument instanceof Arg || $argument->unpack) {
                return false;
            }
        }
        if ($method === 'createstub') {
            if (count($arguments) !== 1) {
                return false;
            }
            if ($arguments[0]->name !== null) {
                if (!class_exists(\PHPUnit\Framework\TestCase::class)) {
                    return false;
                }
                $parameter = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'createStub'))->getParameters()[0]->getName();
                return $arguments[0]->name->toString() === $parameter;
            }
        }
        // Decrementing a PHPUnit counter has no equivalent in Testo's history.
        // Only the proven, bounded increment form is converted mechanically.
        if ($method === 'addtoassertioncount'
            && (\count($arguments) !== 1 || !$arguments[0]->value instanceof Int_
                || $arguments[0]->value->value < 0 || $arguments[0]->value->value > 1000)) {
            return false;
        }
        return true;
    }

    private function sourceReceiver(MethodCall|StaticCall $node): bool
    {
        return $node instanceof MethodCall
            ? $node->var instanceof Variable && $node->var->name === 'this'
            : $node->class instanceof Name && \in_array(\strtolower($node->class->toString()), ['self', 'static'], true);
    }
}
