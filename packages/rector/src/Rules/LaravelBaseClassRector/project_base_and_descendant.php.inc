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

    protected function tearDown(): void
    {
        \Tests\State::$log[] = 'base.teardown';

        parent::tearDown();
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

    protected function tearDown(): void
    {
        \Tests\State::$log[] = 'descendant.teardown';

        parent::tearDown();
    }

    public function test_descendant_keeps_project_base(): void
    {
        $this->travel(5);
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

    protected function tearDownLaravel(): void
    {
        \Tests\State::$log[] = 'base.teardown';
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
        parent::setUpLaravel();

        $this->make('router');
    }

    protected function tearDownLaravel(): void
    {
        \Tests\State::$log[] = 'descendant.teardown';

        parent::tearDownLaravel();
    }

    #[\Testo\Test]
    public function test_descendant_keeps_project_base(): void
    {
        $this->travel(5);
    }
}
