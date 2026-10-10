<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/events.php';
$application = require dirname(__DIR__, 3).'/laravel/bootstrap/app.php';
$application->instance('compatibility.owner', bin2hex(random_bytes(8)));
\Laratesto\Tests\Fixture\Compatibility\event('acquired', ['owner' => $application->make('compatibility.owner')]);
$application->booted(static function ($app): void {
    \Laratesto\Tests\Fixture\Compatibility\event('booted', ['owner' => $app->make('compatibility.owner')]);
});
return $application;
