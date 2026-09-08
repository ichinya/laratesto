<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Faker\Factory;
use Faker\Generator;

/** Laravel's Faker helpers with the application supplied by LaravelPlugin. */
trait WithFaker
{
    protected $faker;

    protected function setUpFaker()
    {
        $this->faker = $this->makeFaker();
    }

    protected function faker($locale = null)
    {
        return $locale === null ? $this->faker : $this->makeFaker($locale);
    }

    protected function makeFaker($locale = null)
    {
        $application = $this->app();
        $locale ??= $application->make('config')->get('app.faker_locale', Factory::DEFAULT_LOCALE);

        return $application->bound(Generator::class)
            ? $application->make(Generator::class, ['locale' => $locale])
            : Factory::create($locale);
    }
}
