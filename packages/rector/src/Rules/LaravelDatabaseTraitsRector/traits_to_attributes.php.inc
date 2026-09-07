<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Foundation\Testing\DatabaseTruncation;

final class BillingTest extends TestCase
{
    use RefreshDatabase;
}

final class OrdersTest extends TestCase
{
    use DatabaseTransactions;
}

final class ReportsTest extends TestCase
{
    use DatabaseMigrations;
}

final class ArchiveTest extends TestCase
{
    use DatabaseTruncation;
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

#[\Laratesto\Attribute\RefreshDatabase]
final class BillingTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTransactions]
final class OrdersTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseMigrations]
final class ReportsTest extends TestCase
{
}

#[\Laratesto\Attribute\DatabaseTruncation]
final class ArchiveTest extends TestCase
{
}
