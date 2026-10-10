<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
require $root.'/vendor/autoload.php';
$temporary = getenv('LARATESTO_PUBLIC_APP_STORAGE');
$application = \Illuminate\Foundation\Application::configure(basePath: $root)
    ->withProviders([\Laratesto\LaratestoServiceProvider::class])->create();
$application->useStoragePath($temporary.'/storage');
$application->useBootstrapPath($temporary.'/bootstrap');
exit($application->handleCommand(new \Symfony\Component\Console\Input\ArgvInput()));
