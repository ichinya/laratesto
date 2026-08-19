<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Illuminate\Contracts\Foundation\Application;

/**
 * Marks a test case that receives the booted Laravel application.
 *
 * Implemented by {@see LaravelTestCase} and by the {@see InteractsWithLaravel} trait.
 *
 * @api
 */
interface LaravelApplicationAware
{
    /**
     * Called by the bridge before every test with a freshly booted application.
     */
    public function setLaravelApplication(Application $application): void;
}
