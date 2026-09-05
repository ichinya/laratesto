<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;

trait ProjectSetup
{
    protected string $tablePrefix = 'fixture_';

    public function setupHelper(): string
    {
        return $this->tablePrefix . 'ok';
    }
}

final class SetupTest extends TestCase
{
    use RefreshDatabase;
    use ProjectSetup;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

trait ProjectSetup
{
    protected string $tablePrefix = 'fixture_';

    public function setupHelper(): string
    {
        return $this->tablePrefix . 'ok';
    }
}

#[\Laratesto\Attribute\RefreshDatabase]
final class SetupTest extends TestCase
{
    use ProjectSetup;

    public function test_store(): void
    {
        $this->get('/store');
    }
}
