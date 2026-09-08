<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/** Keep Laravel's fake and mailable assertions and expose their result to Testo. */
final class LaravelFacadeAssertionsRector extends AbstractRector
{
    public const FACADES = [
        'Illuminate\Support\Facades\Mail', 'Illuminate\Support\Facades\Queue',
        'Illuminate\Support\Facades\Bus', 'Illuminate\Support\Facades\Event',
        'Illuminate\Support\Facades\Notification', 'Illuminate\Support\Facades\Storage',
        'Illuminate\Support\Facades\Http',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Record Laravel facade and mailable assertions in Testo', [
            new CodeSample('Notification::assertNothingSent();', "\\Laratesto\\Testing\\PhpUnitCompatibility::facade(Notification::class, 'assertNothingSent', []);"),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [StaticCall::class, MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node->name instanceof Identifier || !str_starts_with(strtolower($node->name->toString()), 'assert')) {
            return null;
        }
        if ($node instanceof StaticCall ? !$this->isNames($node->class, self::FACADES)
            : (!$this->isObjectType($node->var, new ObjectType(\Illuminate\Mail\Mailable::class))
                || !method_exists(\Illuminate\Mail\Mailable::class, $node->name->toString()))) {
            return null;
        }
        $arguments = [];
        foreach ($node->args as $argument) {
            if (!$argument instanceof Arg) {
                return null;
            }
            $arguments[] = new ArrayItem($argument->value, $argument->name === null ? null : new String_($argument->name->toString()), unpack: $argument->unpack);
        }
        return new StaticCall(new FullyQualified('Laratesto\Testing\PhpUnitCompatibility'), $node instanceof StaticCall ? 'facade' : 'mailable', [
            new Arg($node instanceof StaticCall ? new Expr\ClassConstFetch($node->class, 'class') : $node->var),
            new Arg(new String_($node->name->toString())),
            new Arg(new Expr\Array_($arguments)),
        ]);
    }
}
