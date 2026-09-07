<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

function acceptsByValue($container): void
{
}

final class AppReadsTest extends TestCase
{
    public function test_reads(): void
    {
        $booted = isset($this->app->booted);
        $empty = empty($this->app);
        $config = $this->app['config']->get('x');
        $this->app->flag = true;
        $made = $this->app->make('cache');
        $snapshot = [$this->app];
        acceptsByValue($this->app);
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

function acceptsByValue($container): void
{
}

final class AppReadsTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_reads(): void
    {
        $booted = isset($this->app()->booted);
        $empty = empty($this->app());
        $config = $this->app()['config']->get('x');
        $this->app()->flag = true;
        $made = $this->make('cache');
        $snapshot = [$this->app()];
        acceptsByValue($this->app());
    }
}
