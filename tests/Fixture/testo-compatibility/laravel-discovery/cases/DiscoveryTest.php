<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Discovery;

use Laratesto\Testing\InteractsWithLaravel;
use Laratesto\Testing\LaravelTestCase;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

abstract class ProjectTestCase extends LaravelTestCase
{
    public function inheritedTest(): void
    {
        Assert::same($this->app()->environment(), 'testing');
    }
}

#[Test]
final class BaseClassTest extends ProjectTestCase
{
    public function ownTest(): void
    {
        Assert::true($this->app()->bound('config'));
    }
}

#[Test]
final class TraitTest
{
    use InteractsWithLaravel {
        clearLaravelApplicationReferences as public aliasedHelper;
    }

    public function ownTest(): void
    {
        Assert::same($this->app()->environment(), 'testing');
    }
}

#[Test]
final class OverrideTest extends LaravelTestCase
{
    public function setLaravelApplication(?\Illuminate\Contracts\Foundation\Application $application = null): void
    {
        if ($application !== null) {
            parent::setLaravelApplication($application);
            return;
        }
        Assert::same($this->app()->environment(), 'testing');
    }
}

#[Test]
final class UnrelatedTest
{
    public function beginPhpUnitOutputCapture(): void
    {
        Assert::true(true);
    }

    public function neverTest(): never
    {
        Expect::exception(\RuntimeException::class);
        throw new \RuntimeException('expected');
    }
}

#[Test]
function freeFunction(): void
{
    Assert::true(true);
}
