<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends FoundationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind('parity.clock', \DateTimeImmutable::class);
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class AnnotationDescendantTest extends TestCase
{
    /**
     * Descendants discover by annotation too: a non-test name with the tag.
     *
     * @test
     */
    public function it_keeps_the_project_base(): void
    {
        $this->assertTrue(true);
    }

    /** @test */
    public function test_prefixed_annotation(): void
    {
        $this->assertTrue(true);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

abstract class TestCase extends \Laratesto\Testing\LaravelTestCase
{
    protected function setUpLaravel(): void
    {
        $this->app()->bind('parity.clock', \DateTimeImmutable::class);
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class AnnotationDescendantTest extends TestCase
{
    /**
     * Descendants discover by annotation too: a non-test name with the tag.
     */
    #[\Testo\Test]
    public function it_keeps_the_project_base(): void
    {
        $this->assertTrue(true);
    }

    #[\Testo\Test]
    public function test_prefixed_annotation(): void
    {
        $this->assertTrue(true);
    }
}
