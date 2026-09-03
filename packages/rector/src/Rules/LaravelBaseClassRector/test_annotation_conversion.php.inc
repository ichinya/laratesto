<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class UsersSearchTest extends TestCase
{
    /**
     * The search index stays consistent after reindexing.
     *
     * @test
     *
     * @group search
     */
    public function it_finds_active_users(): void
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function test_prefixed_and_annotated(): void
    {
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    protected function it_is_ignored_when_not_public(): void
    {
        $this->assertTrue(true);
    }

    public function searchPayload(): array
    {
        return [];
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class UsersSearchTest extends \Laratesto\Testing\LaravelTestCase
{
    /**
     * The search index stays consistent after reindexing.
     *
     *
     * @group search
     */
    #[\Testo\Test]
    public function it_finds_active_users(): void
    {
        $this->assertTrue(true);
    }

    #[\Testo\Test]
    public function test_prefixed_and_annotated(): void
    {
        $this->assertTrue(true);
    }

    /**
     * @test
     */
    protected function it_is_ignored_when_not_public(): void
    {
        $this->assertTrue(true);
    }

    public function searchPayload(): array
    {
        return [];
    }
}
