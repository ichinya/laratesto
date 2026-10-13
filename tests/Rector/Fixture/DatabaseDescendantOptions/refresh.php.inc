<?php

declare(strict_types=1);

namespace Tests\DescendantRefresh;

abstract class Base extends \Illuminate\Foundation\Testing\TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
}

final class SeedTest extends Base
{
    protected bool $seed = true;
    public function testValue(): void { $this->assertTrue(true); }
}

final class ConnectionsTest extends Base
{
    protected array $connectionsToTransact = ['audit'];
    public function testValue(): void { $this->assertTrue(true); }
}

abstract class Intermediate extends Base
{
    protected bool $dropViews = true;
}

final class DeepTest extends Intermediate
{
    public function testValue(): void { $this->assertTrue(true); }
}

trait DropsTypes
{
    protected bool $dropTypes = true;
}

final class TraitOptionTest extends Base
{
    use DropsTypes;
    public function testValue(): void { $this->assertTrue(true); }
}

final class HookTest extends Base
{
    protected function shouldSeed(): bool { return true; }
    public function testValue(): void { $this->assertTrue(true); }
}
