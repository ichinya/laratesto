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

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class ProjectDescendantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make('router');
    }

    public function test_descendant_keeps_project_base(): void
    {
        $this->assertSame('acme', $this->acmeHelper());
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

    public function acmeHelper(): string
    {
        return 'acme';
    }
}

namespace Tests\Feature;

use Tests\TestCase;

final class ProjectDescendantTest extends TestCase
{
    protected function setUpLaravel(): void
    {
        $this->make('router');
    }

    #[\Testo\Test]
    public function test_descendant_keeps_project_base(): void
    {
        $this->assertSame('acme', $this->acmeHelper());
    }
}
