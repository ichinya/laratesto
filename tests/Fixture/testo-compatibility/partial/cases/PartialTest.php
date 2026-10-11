<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Partial;

use function Laratesto\Tests\Fixture\Compatibility\event;
use Testo\Assert;
use Testo\Test;

final class PartialSetupTest extends \Laratesto\Testing\LaravelTestCase
{
    private bool $skip = true;

    protected function setUpLaravel(): void
    {
        event('setup');
        if ($this->skip) {
            $this->skip = false;
            throw new \Testo\Core\Exception\SkipTest('runtime setup skip');
        }
    }
    protected function tearDownLaravel(): void { event('teardown'); }
    public function clearLaravelApplicationReferences(): void
    {
        parent::clearLaravelApplicationReferences();
        event('references', ['cleared' => $this->app === null]);
    }
    #[Test]
    public function skippedBody(): void { event('unexpected body'); }
    #[Test]
    public function nextBody(): void { event('body'); Assert::notNull($this->app()); }
}

final class PartialInjectionTest extends \Laratesto\Testing\LaravelTestCase
{
    public function setLaravelApplication(\Illuminate\Contracts\Foundation\Application $application): void
    {
        parent::setLaravelApplication($application);
        throw new \RuntimeException('partial injection');
    }
    public function clearLaravelApplicationReferences(): void
    {
        parent::clearLaravelApplicationReferences();
        event('references', ['cleared' => $this->app === null]);
    }
    #[Test]
    public function neverRuns(): void { event('unexpected body'); }
}
