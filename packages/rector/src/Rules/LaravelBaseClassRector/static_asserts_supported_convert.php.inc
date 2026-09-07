<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class StaticAssertsTest extends TestCase
{
    public static function acmeLabel(): string
    {
        return 'acme';
    }

    public function test_static_asserts(): void
    {
        self::assertSame('x', 'x');
        static::assertTrue(true);
        self::assertCount(2, ['a', 'b']);
        self::assertEmpty([], 'nothing in there');
        self::assertNotEmpty(['acme']);
        self::assertGreaterThan(0, strlen(self::acmeLabel()));

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('boom');

        throw new \RuntimeException('boom');
    }

    public function test_static_skip(): void
    {
        self::markTestSkipped('not ready yet');
    }
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class StaticAssertsTest extends \Laratesto\Testing\LaravelTestCase
{
    public static function acmeLabel(): string
    {
        return 'acme';
    }

    #[\Testo\Test]
    public function test_static_asserts(): void
    {
        self::assertSame('x', 'x');
        static::assertTrue(true);
        self::assertCount(2, ['a', 'b']);
        self::assertEmpty([], 'nothing in there');
        self::assertNotEmpty(['acme']);
        self::assertGreaterThan(0, strlen(self::acmeLabel()));

        self::expectException(\RuntimeException::class);
        self::expectExceptionMessage('boom');

        throw new \RuntimeException('boom');
    }

    #[\Testo\Test]
    public function test_static_skip(): void
    {
        self::markTestSkipped('not ready yet');
    }
}
