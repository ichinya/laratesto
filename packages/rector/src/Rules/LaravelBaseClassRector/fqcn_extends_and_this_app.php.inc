<?php

declare(strict_types=1);

namespace Tests\Feature;

final class DashboardTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_dashboard(): void
    {
        $app = $this->app;

        $this->app->make('cache');

        self::assertNotNull($app);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

final class DashboardTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_dashboard(): void
    {
        $app = $this->app();

        $this->make('cache');

        self::assertNotNull($app);
    }
}
