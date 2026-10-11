<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Lifecycle;

use Testo\Assert;
use Testo\Test;

final class FailureTest extends \Laratesto\Testing\LaravelTestCase
{
    private bool $failed = false;

    #[Test]
    public function primaryFailure(): void
    {
        $this->failed = true;
        $this->app()->instance('leaked', true);
        Assert::true(false, 'primary body failure');
    }

    protected function tearDownLaravel(): void
    {
        if ($this->failed) {
            $this->failed = false;
            throw new \Testo\Core\Exception\SkipTest('secondary teardown skip');
        }
    }

    #[Test]
    public function followingTestIsClean(): void { Assert::false($this->app()->bound('leaked')); }
}
