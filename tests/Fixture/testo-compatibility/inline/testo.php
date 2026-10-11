<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';
$location = getenv('LARATESTO_INLINE_CALLBACKS') === '1' && PHP_VERSION_ID >= 80500 ? __DIR__.'/php85' : __DIR__.'/basic';
$plugins = [new \Testo\Inline\InlineTestPlugin()];
if (getenv('LARATESTO_INLINE_LARAVEL') === '1') {
    $plugins[] = new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app'));
}
return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(name: 'Sources', location: [$location], plugins: $plugins),
]);
