<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Idempotency;

use Laratesto\Rector\Tests\Support\RectorRun;
use Testo\Assert;
use Testo\Test;

/**
 * Review point 3 (PR #8): two different rules failing the same residual code on one
 * class must merge into the single marker comment without losing the earlier reason,
 * and the merged result must stay byte-identical on a repeated run.
 *
 * The corpus fails `HTTP_UNSUPPORTED_SIGNATURE` twice for one class: `$this->app->make()`
 * with two arguments in {@see \Laratesto\Rector\Rules\LaravelBaseClassRector} and
 * `withoutExceptionHandling()` in {@see \Laratesto\Rector\Rules\LaravelResidualDetectionRector}.
 */
final class TwoRuleResidualMergeTest
{
    private const CORPUS_SAMPLE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class CollisionTest extends TestCase
{
    public function test_collision(): void
    {
        $this->app->make('stdout', ['channel' => 'test']);

        $this->withoutExceptionHandling();
    }
}
PHP;

    #[Test]
    public function twoRulesMergingOneCodeStayByteIdenticalOnTheSecondRun(): void
    {
        $corpus = RectorRun::twiceWithByteIdenticalSecondRun(['CollisionTest.php' => self::CORPUS_SAMPLE]);

        $migrated = $corpus['CollisionTest.php'] ?? '';
        Assert::true($migrated !== '', 'The migrated corpus is empty.');

        $markerLines = \array_values(\array_filter(
            \explode("\n", $migrated),
            static fn (string $line): bool => \str_contains($line, 'laratesto-residual('),
        ));

        Assert::count($markerLines, 1, "One marker comment per code — the rules must merge, not stack comments:\n" . $migrated);

        $marker = $markerLines[0] ?? '';
        Assert::true(
            \str_contains($marker, 'code=HTTP_UNSUPPORTED_SIGNATURE')
            && \str_contains($marker, 'rule=Laratesto\Rector\Rules\LaravelBaseClassRector')
            && \str_contains($marker, 'rule=Laratesto\Rector\Rules\LaravelResidualDetectionRector'),
            "Both rule contributions must live in the single marker:\n" . $marker,
        );

        Assert::true(
            \str_contains($marker, '$this->app->make() is automatic only with one argument')
            && \str_contains($marker, 'withoutExceptionHandling() — no automatic helper conversion; migrate manually'),
            "No rule reason may be overwritten or lost:\n" . $marker,
        );

        $baseClassAt = \strpos($marker, 'LaravelBaseClassRector');
        $detectionAt = \strpos($marker, 'LaravelResidualDetectionRector');
        Assert::true(
            $baseClassAt !== false && $detectionAt !== false && $baseClassAt < $detectionAt,
            'Contributions must render sorted by rule class-string, independent of marking order.',
        );
    }
}
