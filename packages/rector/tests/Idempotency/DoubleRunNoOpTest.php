<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Idempotency;

use Laratesto\Rector\Tests\Support\RectorRun;
use Testo\Assert;
use Testo\Test;

/**
 * Ticket 01 acceptance (story: issue #7, п. 6): running our set over the same input
 * twice must produce changes only ONCE — the second run leaves files untouched.
 *
 * Runs the real `rector` binary against a throwaway copy of a representative Laravel
 * PHPUnit fixture, hashing the corpus between runs instead of parsing console output.
 */
final class DoubleRunNoOpTest
{
    private const CORPUS_SAMPLE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

final class UsersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_users_list_renders(): void
    {
        $response = $this->get('/users');

        $response->assertStatus(200);
    }
}
PHP;

    /**
     * GLM final-review finding 3: a file-wide gate block (an unsafe sibling) must
     * not strand the safe class silently. The pipeline converts the safe class
     * onto the Laratesto base but leaves its TestResponse property/parameter/return
     * declarations, which would TypeError at runtime against the non-subtype
     * Laratesto\Testing\LaravelResponse. The safe class must carry an actionable
     * RESPONSE_UNSUPPORTED_API residual naming the blocking sibling, the swap must
     * stay blocked, and the second run must not touch anything.
     */
    private const MIXED_FILE_CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

final class MixedSafeResponseTest extends TestCase
{
    private TestResponse $pending;

    public function response(): TestResponse
    {
        return $this->get('/safe');
    }

    public function hold(TestResponse $response): void
    {
        $this->pending = $response;
    }
}

final class MixedUnsafeResponseTest extends TestCase
{
    public function response(): TestResponse
    {
        $response = $this->get('/unsafe');
        $response->assertDownload();

        return $response;
    }
}
PHP;

    /**
     * GLM cold-review finding: the analyzer scanned MethodCall only, so a PHPUnit
     * assertion written as self::/static:: (self::assertStringContainsString())
     * survived the upstream bridge-rector, bypassed the residual preflight, and the
     * class still migrated to LaravelTestCase — fataling at runtime on the converted
     * base, which provides no assert surface. The full pipeline must keep the class
     * on PHPUnit with an actionable residual naming the static call, and the second
     * run must not touch anything.
     */
    private const STATIC_ASSERT_CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class StaticAssertBlockTest extends TestCase
{
    public function test_haystack(): void
    {
        $this->assertSame('acme', 'acme');

        self::assertStringContainsString('acme', 'acme label');
    }
}
PHP;


    #[Test]
    public function mixedFileGateMarksSafeClassAndStaysIdempotent(): void
    {
        $snapshot = RectorRun::twiceWithByteIdenticalSecondRun([
            'MixedResponseBlockTest.php' => self::MIXED_FILE_CORPUS,
        ]);

        $migrated = Assert::string($snapshot['MixedResponseBlockTest.php']);

        // The safe class converted but the file-wide swap stayed blocked.
        $migrated->contains('extends \Laratesto\Testing\LaravelTestCase');
        $migrated->contains('public function response(): TestResponse');

        // The safe class carries an actionable residual naming the blocker.
        $migrated->contains(
            'the file-wide TestResponse to Laratesto\Testing\LaravelResponse swap'
            . ' was blocked by sibling class Tests\Feature\MixedUnsafeResponseTest',
        );

        // The unsafe sibling keeps its own marker and stays un-migrated.
        $migrated->contains('TestResponse::assertDownload() is outside the supported response matrix');
        $migrated->contains('extends TestCase');
    }

    #[Test]
    public function staticAssertBlockMarksResidualAndStaysIdempotent(): void
    {
        $snapshot = RectorRun::twiceWithByteIdenticalSecondRun([
            'StaticAssertBlockTest.php' => self::STATIC_ASSERT_CORPUS,
        ]);

        $blocked = Assert::string($snapshot['StaticAssertBlockTest.php']);

        // The unsupported self:: assert keeps the class on PHPUnit.
        $blocked->contains('extends TestCase');
        $blocked->contains('self::assertStringContainsString() is outside the supported helper matrix');

        // Both preflight rules fail the same code and merge into one marker comment.
        $blocked->contains(
            'laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, '
            . 'rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual)',
        );
        $blocked->contains(
            'rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual)',
        );
    }

    #[Test]
    public function secondRunLeavesCorpusUntouched(): void
    {
        RectorRun::twiceWithByteIdenticalSecondRun(['UsersListTest.php' => self::CORPUS_SAMPLE]);
    }
}
