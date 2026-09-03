<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Idempotency;

use Laratesto\Rector\Tests\Support\RectorRun;
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

    #[Test]
    public function secondRunLeavesCorpusUntouched(): void
    {
        RectorRun::twiceWithByteIdenticalSecondRun(['UsersListTest.php' => self::CORPUS_SAMPLE]);
    }
}
