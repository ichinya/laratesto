<?php

namespace Other;

function takeValue(&$container): void {}

namespace Tests\Feature;

function takeValue($container): void {}

final class NamespacedValueTest extends \Illuminate\Foundation\Testing\TestCase
{
    public function test_read(): void
    {
        takeValue($this->app);
    }
}
-----
<?php

namespace Other;

function takeValue(&$container): void {}

namespace Tests\Feature;

function takeValue($container): void {}

final class NamespacedValueTest extends \Laratesto\Testing\LaravelTestCase
{
    #[\Testo\Test]
    public function test_read(): void
    {
        takeValue($this->app());
    }
}
