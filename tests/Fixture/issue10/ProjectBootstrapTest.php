<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as FrameworkTestCase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Tests\PageAssertions as Assert;

final class PageAssertions
{
    public function has(string $key): self
    {
        \PHPUnit\Framework\Assert::assertSame('title', $key);
        return $this;
    }
}

abstract class TestCase extends FrameworkTestCase
{
    public function createApplication()
    {
        $app = require getenv('LARATESTO_ISSUE10_APP').'/bootstrap/app.php';
        $app->booting(function () use ($app): void {
            $app->instance('issue10.marker', 'custom bootstrap');
        });
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('issue10.setup', true);
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }
}

final class ProjectBootstrapTest extends TestCase
{
    use WithFaker;

    public function testBootstrapAndTypedRequest(): void
    {
        $this->assertSame('custom bootstrap', $this->app->make('issue10.marker'));
        $this->assertTrue($this->app['config']->get('issue10.setup'));
        $this->app['router']->get('/issue10', fn () => ['items' => ['one', 'two']]);
        $uri = '/issue10';
        $page = $this->getJson($uri);
        $page->assertOk()->assertJsonCount(2, 'items')->assertJsonMissing(['unknown' => true]);
        $this->app['router']->post('/issue10-headers', fn () => ['header' => request()->header('X-Issue10')]);
        $this->post('/issue10-headers', headers: ['X-Issue10' => 'kept'])->assertJsonPath('header', 'kept');
    }

    public function testFakerIsReadyBeforeBody(): void
    {
        $this->assertIsString($this->faker->name());
        $this->assertInstanceOf(\Faker\Generator::class, $this->faker('fr_FR'));
    }

    public function testNestedClassKeepsItsOwnParentAndApplication(): void
    {
        $service = new class('nested') extends \RuntimeException {
            public $app = 'service';
            public function __construct(string $message)
            {
                parent::__construct($message);
            }
            public function application(): string
            {
                return $this->app;
            }
        };
        $this->assertSame('nested', $service->getMessage());
        $this->assertSame('service', $service->application());
    }

    public function testExtendedAssertions(): void
    {
        $this->assertIsInt(1);
        $this->assertIsBool(false);
        $this->assertIsObject(new \stdClass());
        $this->assertNotFalse(0);
        $this->assertMatchesRegularExpression('/^abc/', 'abcdef');
        $this->assertStringStartsWith('abc', 'abcdef');
        $this->assertFileExists(__FILE__);
        $this->assertFileDoesNotExist(__FILE__.'.missing');
        $this->assertDirectoryExists(__DIR__);
        $this->assertDirectoryDoesNotExist(__DIR__.'/missing');
    }

    public function testFacadeAssertions(): void
    {
        \Illuminate\Support\Facades\Event::fake();
        \Illuminate\Support\Facades\Event::assertNothingDispatched();
        \Illuminate\Support\Facades\Event::dispatch('issue10.event');
        \Illuminate\Support\Facades\Event::assertDispatched('issue10.event');
    }

    public function testPackageMacroPreservesItsCallbackTypeAndAlias(): void
    {
        $this->app['router']->get('/issue10-macro', fn () => 'page');
        $this->get('/issue10-macro')->assertInertia(fn (Assert $page) => $page->has('title'));
    }

    public function testNegativeFrameworkAssertionStillFails(): void
    {
        $this->app['router']->get('/issue10-negative', fn () => ['items' => ['one']]);
        $this->getJson('/issue10-negative')->assertJsonCount(2, 'items');
    }

    public function testNegativeFacadeAssertionStillFails(): void
    {
        \Illuminate\Support\Facades\Event::fake();
        \Illuminate\Support\Facades\Event::assertDispatched('not.dispatched');
    }
}
