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

    /**
     * MiMo finding 3: a one-argument $this->assertExitCode(0) call satisfied
     * the old 1..3 helper-matrix arity, but the real runtime helper
     * (InteractsWithLaravel::assertExitCode(int $code, string $command,
     * array $parameters = [])) requires two — the call passed the preflight
     * and died with an ArgumentCountError after migration. The gap must fail
     * the class closed with an actionable residual instead.
     */
    private const EXIT_CODE_ARITY_BLOCKED_CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class ExitCodeArityBlockedTest extends TestCase
{
    public function test_one_argument_helper_call(): void
    {
        $this->assertExitCode(0);
    }
}
PHP;

    /**
     * The other face of finding 3: two- and three-argument helper calls are
     * the real runtime shape and must migrate untouched, and the pending
     * chain keeps its ONE-argument assertExitCode(0) — PendingArtisanCommand
     * really declares assertExitCode(int $code), the opposite of the helper.
     */
    private const EXIT_CODE_ARITY_ACCEPTED_CORPUS = <<<'PHP'
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class ExitCodeArityAcceptedTest extends TestCase
{
    public function test_supported_helper_forms(): void
    {
        $this->assertExitCode(0, 'parity:ping');
        $this->assertExitCode(1, 'parity:ping', ['--fail' => true]);
    }

    public function test_pending_command_chain(): void
    {
        $this->artisan('parity:ping')->assertExitCode(0);
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

    /**
     * MiMo finding 3, end to end: the helper matrix let a one-argument
     * $this->assertExitCode(0) pass the preflight, but the migrated runtime
     * helper requires (int $code, string $command, ...) — a TypeError after
     * migration. The gap must block the swap with an actionable residual,
     * while the real runtime shapes (2..3 helper arguments, and the pending
     * chain whose PendingArtisanCommand::assertExitCode(int $code) takes
     * exactly ONE argument) migrate clean.
     */
    #[Test]
    public function exitCodeArityGapFailsClosedWhileAcceptedFormsMigrate(): void
    {
        $snapshot = RectorRun::twiceWithByteIdenticalSecondRun([
            'ExitCodeArityBlockedTest.php' => self::EXIT_CODE_ARITY_BLOCKED_CORPUS,
            'ExitCodeArityAcceptedTest.php' => self::EXIT_CODE_ARITY_ACCEPTED_CORPUS,
        ]);

        $blocked = Assert::string($snapshot['ExitCodeArityBlockedTest.php']);

        // The arity gap keeps the class on PHPUnit instead of migrating it
        // onto a base whose helper would die on the missing command argument.
        $blocked->contains('extends TestCase');
        $blocked->contains('assertExitCode() expects 2..3 common-path arguments; got 1');

        // Both preflight rules fail the same code and merge into one marker comment.
        $blocked->contains(
            'laratesto-residual(code=HTTP_UNSUPPORTED_SIGNATURE, '
            . 'rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual)',
        );
        $blocked->contains(
            'rule=Laratesto\Rector\Rules\LaravelSourceCompatibleCallsRector, severity=manual)',
        );

        $accepted = Assert::string($snapshot['ExitCodeArityAcceptedTest.php']);

        // The supported arities migrate with the calls untouched...
        $accepted->contains('extends \Laratesto\Testing\LaravelTestCase');
        $accepted->contains('#[\Testo\Test]');
        $accepted->notContains('laratesto-residual');
        $accepted->contains("\$this->assertExitCode(0, 'parity:ping');");
        $accepted->notContains("\$this->assertExitCode(0);");

        // ...and the pending chain keeps its one-argument assertExitCode(0).
        $accepted->contains("\$this->artisan('parity:ping')->assertExitCode(0);");
    }

    #[Test]
    public function secondRunLeavesCorpusUntouched(): void
    {
        RectorRun::twiceWithByteIdenticalSecondRun(['UsersListTest.php' => self::CORPUS_SAMPLE]);
    }
}
