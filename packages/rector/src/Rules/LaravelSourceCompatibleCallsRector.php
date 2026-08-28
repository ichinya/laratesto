<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Documents and pins the Laravel test calls Laratesto already provides source-compatibly.
 *
 * `InteractsWithLaravel` and `LaravelResponse` keep the PHPUnit-era signatures, so the
 * HTTP helpers, database asserts and response asserts listed below survive migration
 * untouched. This rule never changes a node; its fixtures are the regression net — if a
 * future Laratesto release drifts on any of these signatures, the fixture corpus (and
 * with it the set's acceptance run) fails instead of silently producing broken tests.
 *
 * Covered calls: `$this->get/getJson/post/postJson/put/patch/delete/deleteJson`,
 * `$this->withHeader(s)/withoutHeader/withToken/withSession/withCookie(s)`,
 * `$this->withServerVariables/followingRedirects/from`, `$this->withoutMiddleware`,
 * `$this->actingAs/actingAsGuest`, `$this->artisan`, `$this->travel`,
 * `$this->assertDatabaseHas/assertDatabaseMissing/assertDatabaseCount`,
 * `$this->assertSessionHas/assertSessionMissing/assertSessionHasErrors`,
 * and on responses `assertStatus/assertOk/assertJson/assertJsonPath/assertRedirect/
 * assertHeader` plus the wider assert surface of `LaravelResponse`.
 */
#[TestRectorFixtures('LaravelSourceCompatibleCallsRector')]
final class LaravelSourceCompatibleCallsRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Pin Laravel HTTP/response/database calls as source-compatible: never rewrite them',
            [
                new CodeSample(
                    <<<'PHP'
                        $response = $this->postJson('/api/users', ['name' => 'A']);
                        $response->assertStatus(201)->assertJsonPath('name', 'A');
                        PHP,
                    <<<'PHP'
                        $response = $this->postJson('/api/users', ['name' => 'A']);
                        $response->assertStatus(201)->assertJsonPath('name', 'A');
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

    #[\Override]
    public function refactor(Node $node): ?Node
    {
        // Deliberately empty: the value is the fixture corpus, not the transform.
        return null;
    }
}
