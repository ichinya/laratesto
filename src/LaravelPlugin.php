<?php

declare(strict_types=1);

namespace Laratesto;

use Internal\Container\Container;
use Laratesto\Config\LaravelConfig;
use Laratesto\Pipeline\LaravelTestInterceptor;
use Laratesto\Runtime\LaravelApplicationFactory;
use Laratesto\Runtime\LaravelStateCleaner;
use Testo\Common\PluginConfigurator;
use Testo\Pipeline\InterceptorCollector;

/**
 * Laravel plugin for the Testo testing framework.
 *
 * Boots the framework around every test and guarantees state cleanup afterwards.
 * Register it for a suite in `testo.php`:
 *
 * ```php
 * new SuiteConfig(
 *     name: 'Laravel',
 *     location: ['tests/Feature'],
 *     plugins: [
 *         new NamingConventionPlugin(),
 *         new LaravelPlugin(new LaravelConfig(basePath: dirname(__DIR__))),
 *     ],
 * );
 * ```
 *
 * @api
 */
final readonly class LaravelPlugin implements PluginConfigurator
{
    public function __construct(
        public readonly LaravelConfig $config,
    ) {}

    #[\Override]
    public function configure(Container $container): void
    {
        $factory = new LaravelApplicationFactory($this->config);

        // The factory is shared with attribute interceptors (RefreshDatabase,
        // DatabaseTransactions) which are instantiated by the pipeline injector.
        $container->set($factory, LaravelApplicationFactory::class);

        $container
            ->get(InterceptorCollector::class)
            ->addInterceptor(new LaravelTestInterceptor(
                factory: $factory,
                cleaner: new LaravelStateCleaner(),
            ));
    }
}
