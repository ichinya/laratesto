<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Migration\PhpUnitToTestoMigrator;
use Testo\Assert;
use Testo\Test;

final class PhpUnitToTestoMigratorTest
{
    #[Test]
    public function migratesPureUnitAssertionsWithTestoArgumentOrder(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests\Unit\Domain;

            use PHPUnit\Framework\TestCase;

            final class ExampleTest extends TestCase
            {
                public function testValues(): void
                {
                    self::assertSame('expected', $actual, 'same message');
                    $this->assertContains('needle', $values);
                    static::assertCount(2, $values);
                    self::assertInstanceOf(\ArrayObject::class, $object);
                    self::assertEmpty($empty, 'empty message');
                    self::assertNotEmpty($nonEmpty);
                }
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains('namespace Tests\Testo\Unit\Domain;');
        Assert::string($result->code)->contains('use Testo\Assert;');
        Assert::string($result->code)->notContains('PHPUnit\Framework');
        Assert::string($result->code)->notContains('extends TestCase');
        Assert::string($result->code)->contains("Assert::same(\$actual, 'expected', 'same message');");
        Assert::string($result->code)->contains("Assert::contains(\$values, 'needle');");
        Assert::string($result->code)->contains('Assert::count($values, 2);');
        Assert::string($result->code)->contains('Assert::instanceOf($object, \ArrayObject::class);');
        Assert::string($result->code)->contains("Assert::false((bool) (\$empty), 'empty message');");
        Assert::string($result->code)->contains('Assert::true((bool) ($nonEmpty));');
    }

    #[Test]
    public function migratesLaravelBaseTraitsLifecycleAndResponseAccess(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Tests\Feature;

            use Illuminate\Foundation\Testing\RefreshDatabase;
            use Illuminate\Testing\TestResponse;
            use Tests\TestCase;

            final class ExampleTest extends TestCase
            {
                use RefreshDatabase;

                protected function setUp(): void
                {
                    parent::setUp();
                    $this->app->instance('key', 'value');
                }

                protected function tearDown(): void
                {
                    $this->app->forgetInstance('key');
                    parent::tearDown();
                }

                public function testResponse(): void
                {
                    $this->assertResponse($this->get('/'));
                }

                private function assertResponse(TestResponse $response): void
                {
                    self::assertSame('application/json', $response->headers->get('Content-Type'));
                    self::assertSame('{}', $response->getContent());
                }
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains('namespace Tests\Testo\Feature;');
        Assert::string($result->code)->contains('use Laratesto\Attribute\RefreshDatabase;');
        Assert::string($result->code)->contains('use Laratesto\Testing\LaravelResponse;');
        Assert::string($result->code)->contains('use Laratesto\Testing\LaravelTestCase;');
        Assert::string($result->code)->contains('#[RefreshDatabase]');
        Assert::string($result->code)->contains('extends LaravelTestCase');
        Assert::string($result->code)->contains('function setUpLaravel(): void');
        Assert::string($result->code)->contains('function tearDownLaravel(): void');
        Assert::string($result->code)->notContains('parent::setUp()');
        Assert::string($result->code)->notContains('parent::tearDown()');
        Assert::string($result->code)->contains('$this->app()->instance');
        Assert::string($result->code)->contains('LaravelResponse $response');
        Assert::string($result->code)->contains("\$response->headers->get('Content-Type')");
        Assert::string($result->code)->contains('$response->getContent()');
    }

    #[Test]
    public function migratesLaravelsDatabaseMigrationsTrait(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Feature;

            use Illuminate\Foundation\Testing\DatabaseMigrations;
            use Tests\TestCase;

            final class DatabaseTest extends TestCase
            {
                use DatabaseMigrations;

                public function testDatabase(): void {}
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains('use Laratesto\Attribute\DatabaseMigrations;');
        Assert::string($result->code)->contains('#[DatabaseMigrations]');
        Assert::string($result->code)->notContains('Illuminate\Foundation\Testing\DatabaseMigrations');
        Assert::string($result->code)->notContains('use DatabaseMigrations;');
    }

    #[Test]
    public function migratesExceptionAndSkipExpectations(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Unit;

            use PHPUnit\Framework\TestCase;

            final class ExampleTest extends TestCase
            {
                public function testException(): void
                {
                    $this->expectException(\RuntimeException::class);
                    throw new \RuntimeException('boom');
                }

                public function testMessage(): void
                {
                    $this->expectExceptionMessage('boom');
                    throw new \RuntimeException('boom');
                }

                public function testSkip(): void
                {
                    $this->markTestSkipped('not available');
                }
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains('use Testo\Expect;');
        Assert::string($result->code)->contains('Expect::exception(\RuntimeException::class);');
        Assert::string($result->code)->contains("Expect::exception(\Throwable::class)->withMessage('boom');");
        Assert::string($result->code)->contains("throw new \Testo\Core\Exception\SkipTest('not available');");
        Assert::count($result->warnings, 1);
    }

    #[Test]
    public function migratesExplicitFailureAndAssertionCountCalls(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Unit;

            use PHPUnit\Framework\TestCase;

            final class ExampleTest extends TestCase
            {
                public function testGuard(): void
                {
                    try {
                        self::fail('must throw');
                    } catch (\RuntimeException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains("Assert::fail('must throw');");
        Assert::string($result->code)->contains('Assert::true(true);');
        Assert::count($result->warnings, 1);
    }

    #[Test]
    public function leavesAssertionLikeTextInStringsAndCommentsUntouched(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Unit;

            use PHPUnit\Framework\TestCase;

            final class ExampleTest extends TestCase
            {
                public function testText(): void
                {
                    $example = 'self::assertSame("expected", "actual")';
                    // static::assertFalse(true) is documentation, not executable code.
                    self::assertSame('expected', $actual);
                }
            }
            PHP);

        Assert::true($result->successful(), \implode('; ', $result->errors));
        Assert::string($result->code)->contains('self::assertSame("expected", "actual")');
        Assert::string($result->code)->contains('// static::assertFalse(true) is documentation');
        Assert::string($result->code)->contains("Assert::same(\$actual, 'expected');");
    }

    #[Test]
    public function refusesConstructsThatNeedAHumanDecision(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Unit;

            use PHPUnit\Framework\Attributes\DataProvider;
            use PHPUnit\Framework\TestCase;

            final class ExampleTest extends TestCase
            {
                #[DataProvider('values')]
                public function testValue(string $value): void
                {
                    $mock = $this->createMock(\Stringable::class);
                    self::assertSame('x', $value);
                }
            }
            PHP);

        Assert::false($result->successful());
        Assert::string(\implode('\n', $result->errors))->contains('data providers');
        Assert::string(\implode('\n', $result->errors))->contains('test doubles');
        Assert::string($result->code)->contains('PHPUnit\Framework\TestCase');
    }

    #[Test]
    public function refusesCustomLaravelMigrationLifecycleOptions(): void
    {
        $result = (new PhpUnitToTestoMigrator())->migrate(<<<'PHP'
            <?php

            namespace Tests\Feature;

            use Illuminate\Foundation\Testing\RefreshDatabase;
            use Tests\TestCase;

            final class ExampleTest extends TestCase
            {
                use RefreshDatabase;

                protected bool $seed = true;

                protected function beforeRefreshingDatabase(): void {}

                public function testValue(): void {}
            }
            PHP);

        Assert::false($result->successful());
        Assert::string(\implode('\n', $result->errors))->contains('refresh hooks');
        Assert::string(\implode('\n', $result->errors))->contains('migration properties');
    }
}
