<?php

declare(strict_types=1);

namespace Tests\DescendantPositive;

abstract class Base extends \Illuminate\Foundation\Testing\TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;
    protected bool $seed = true;
}

final class UnchangedTest extends Base
{
    public function testValue(): void { $this->assertTrue(true); }
}

abstract class Intermediate extends Base
{
    protected bool $seed = TRUE;
}

final class SameOptionsTest extends Intermediate
{
    protected bool $seed = true;
    protected bool $dropViews = false;
    protected array $connectionsToTransact = [null];
    protected array $connectionsToTruncate = ['irrelevant'];
    public function testValue(): void { $this->assertTrue($this->seed); }
}

abstract class TransactionsBase extends \Illuminate\Foundation\Testing\TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;
    protected array $connectionsToTransact = ['audit'];
}

final class SameTransactionsTest extends TransactionsBase
{
    protected array $connectionsToTransact = ['audit'];
    protected bool $seed = true;
    public function testValue(): void { $this->assertTrue(true); }
}

abstract class TruncationBase extends \Illuminate\Foundation\Testing\TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTruncation;
}

final class DefaultTruncationTest extends TruncationBase
{
    protected ?string $seeder = null;
    protected ?array $tablesToTruncate = null;
    protected array $exceptTables = [];
    public function testValue(): void { $this->assertTrue(true); }
}
