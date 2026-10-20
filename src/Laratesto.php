<?php

declare(strict_types=1);

namespace Laratesto;

use Laratesto\Runtime\HttpTestContext;
use Laratesto\Testing\HttpClient;

/**
 * Typed access to the active Laravel test execution.
 *
 * @api
 */
final class Laratesto
{
    private function __construct() {}

    public static function http(): HttpClient
    {
        return HttpTestContext::current()->client();
    }
}
