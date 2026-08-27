<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Harness;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Pipeline probe for ticket 01: proves the inline fixture harness from
 * testo/bridge-rector drives OUR rule through a real Rector run. Not a product rule —
 * lives in tests/ and disappears once real conversion rules (tickets 02+) carry their own
 * TestRectorFixtures attributes.
 */
#[TestRectorFixtures('Fixtures')]
final class SmokeRule extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Smoke-probe: renames method smoke() to smoked().',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class Sample
{
    public function smoke(): void
    {
    }
}
CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
class Sample
{
    public function smoked(): void
    {
    }
}
CODE_SAMPLE,
                ),
            ],
        );
    }

    public function getNodeTypes(): array
    {
        return [Node\Stmt\ClassMethod::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (! $node->name instanceof Identifier || $node->name->toString() !== 'smoke') {
            return null;
        }

        $node->name = new Identifier('smoked');

        return $node;
    }
}
