<?php

declare(strict_types=1);

namespace Laratesto\Testing;

/**
 * Base class for Laravel tests running under Testo.
 *
 * Unlike `Illuminate\Foundation\Testing\TestCase` it does not extend PHPUnit.
 * The bridge boots a fresh application before every test and injects it here.
 *
 * ```php
 * final class UserControllerTest extends LaravelTestCase
 * {
 *     public function testIndex(): void
 *     {
 *         $this->get('/users')->assertOk();
 *     }
 * }
 * ```
 *
 * @api
 */
abstract class LaravelTestCase implements LaravelApplicationAware
{
    use InteractsWithLaravel;
}
