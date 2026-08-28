<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
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
 *    facade fakes (`Mail::fake()`, `Queue::fake()`, `Bus::fake()`, `Event::fake()`,
 *    `Notification::fake()`, `Storage::fake()`, `Http::fake()`), exception-handling
 *    helpers (`$this->withoutExceptionHandling()` / `withExceptionHandling()`), and
 *    response asserts Laratesto does not provide (`assertJsonFragment`,
 *    `assertJsonCount`, `assertCookie`, `assertCookieExpired`, `assertViewIs`,
 *    `assertDownload`, ...). They keep running under PHPUnit semantics; the class gets
 *    a residual marker listing what needs a manual decision.
 *
 * 2. Outside the convertible hierarchy — a class that is NOT a Laravel test but still
 *    uses Laravel test constructs (`$this->app`, a database trait, a `TestResponse`
 *    typehint). The base-class chain does not resolve, so no conversion rule applies;
 *    the marker reports the constructs so nothing passes silently.
 *
 * The marker is the canonical `laratesto-residual` comment (see {@see ResidualMarker});
 * the scanner collects it into the table and report. Idempotent: one marker per class,
 * replace-or-skip.
 */
#[TestRectorFixtures('LaravelResidualDetectionRector')]
final class LaravelResidualDetectionRector extends AbstractRector
{
    private const string TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    /**
     * Facade fakes without a stable Testo-native counterpart yet.
     */
    private const array FAKE_FACADES = [
        'Illuminate\Support\Facades\Mail',
        'Illuminate\Support\Facades\Queue',
        'Illuminate\Support\Facades\Bus',
        'Illuminate\Support\Facades\Event',
        'Illuminate\Support\Facades\Notification',
        'Illuminate\Support\Facades\Storage',
        'Illuminate\Support\Facades\Http',
    ];

    /**
     * Response/method calls inside a Laravel test that Laratesto does not provide.
     */
    private const array UNSUPPORTED_METHOD_CALLS = [
        'withoutExceptionHandling',
        'withExceptionHandling',
        'assertJsonFragment',
        'assertJsonCount',
        'assertCookie',
        'assertCookieExpired',
        'assertCookieNotExpired',
        'assertViewIs',
        'assertDownload',
        'assertStreamedContent',
    ];

    /**
     * Laravel test constructs that mark a NON-test class as carrying unmigrated code.
     */
    private const array LARAVEL_TEST_TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTransactions',
        'Illuminate\Foundation\Testing\DatabaseMigrations',
        'Illuminate\Foundation\Testing\DatabaseTruncation',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Mark unsupported Laravel test constructs (fakes, exception helpers, missing asserts, constructs outside the hierarchy) as residuals',
            [
                new CodeSample(
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;
                        use Illuminate\Support\Facades\Mail;

                        final class SignupTest extends TestCase
                        {
                            public function test_signup_sends_mail(): void
                            {
                                Mail::fake();

                                $this->postJson('/signup', ['email' => 'a@b.c'])
                                    ->assertStatus(201);
                            }
                        }
                        PHP,
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;
                        use Illuminate\Support\Facades\Mail;

                        /* laratesto-residual(rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector): Mail::fake() — no automatic conversion; migrate manually */
                        final class SignupTest extends TestCase
                        {
                            public function test_signup_sends_mail(): void
                            {
                                Mail::fake();

                                $this->postJson('/signup', ['email' => 'a@b.c'])
                                    ->assertStatus(201);
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
        return $this->isLaravelTestClass($node)
            ? $this->detectUnsupportedInside($node)
            : $this->detectConstructsOutsideHierarchy($node);
    }

    private function isLaravelTestClass(Class_ $node): bool
    {
        if ($node->extends === null) {
            return false;
        }

        return $this->isName($node->extends, self::TARGET_BASE)
            || $this->isNames($node->extends, [
                'Tests\TestCase',
                'Illuminate\Foundation\Testing\TestCase',
            ]);
    }

    /**
     * Unsupported constructs inside a converted (or convertible) Laravel test.
     */
    private function detectUnsupportedInside(Class_ $node): ?Node
    {
        $found = [];

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$found): void {
            if ($inner instanceof StaticCall
                && $this->isNames($inner->class, self::FAKE_FACADES)
                && $this->isName($inner->name, 'fake')) {
                $found[] = \sprintf(
                    '%s::fake()',
                    (new FullyQualified((string) $this->getName($inner->class)))->getLast(),
                );

                return;
            }

            if ($inner instanceof MethodCall
                && $this->isNames($inner->name, self::UNSUPPORTED_METHOD_CALLS)) {
                $found[] = \sprintf('%s()', $this->getName($inner->name));
            }
        });

        if ($found === []) {
            return null;
        }

        ResidualMarker::mark(
            $node,
            static::class,
            \implode(', ', \array_values(\array_unique($found)))
                . ' — no automatic conversion; migrate manually',
        );

        return $node;
    }

    /**
     * Laravel constructs in a class whose base does not resolve into the hierarchy.
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
            static::class,
            'Laravel constructs outside a convertible hierarchy ('
            . \implode(', ', \array_values(\array_unique($found)))
            . ') — base class does not resolve; migrate manually',
        );

        return $node;
    }
}
