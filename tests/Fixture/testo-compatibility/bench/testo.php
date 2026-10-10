<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/events.php';

#[\Testo\Pipeline\Attribute\InterceptorOptions(order: \Testo\Pipeline\Attribute\InterceptorOptions::ORDER_CLOSE_TO_TEST + 1)]
final class ControlledBenchMeasurements implements \Testo\Pipeline\Middleware\TestRunInterceptor
{
    public function runTest(\Testo\Core\Context\TestInfo $info, callable $next): \Testo\Core\Context\TestResult
    {
        $result = $next($info);
        if (!str_ends_with($info->name, 'controlledFailure') || !$result->result instanceof \Testo\Bench\Dto\BenchResult) {
            return $result;
        }
        // Synthetic means test the verdict/report boundary, never timing performance.
        $bench = $result->result;
        $controlled = array_map(static fn($index) => new \Testo\Bench\Dto\CaseResult($index === 0 ? 2.0 : 1.0, $index === 0 ? 2.0 : 1.0, 0.0, 0, $index === 0 ? 2.0 : 1.0, 0.0), array_keys($bench->cases));
        return $result->withResult(new \Testo\Bench\Dto\BenchResult($bench->cases, $controlled, \Testo\Bench\Internal\Explanator::prepareLines($bench->cases, $controlled)));
    }
}
$plugins = [new \Testo\Bench\BenchmarkPlugin(), new class implements \Testo\Common\PluginConfigurator {
    public function configure(\Internal\Container\Container $container): void { $container->get(\Testo\Pipeline\InterceptorCollector::class)->addInterceptor(new ControlledBenchMeasurements()); }
}];
if (getenv('LARATESTO_BENCH_LARAVEL') === '1') {
    $plugins[] = new \Laratesto\LaravelPlugin(new \Laratesto\Config\LaravelConfig(basePath: dirname(__DIR__).'/app'));
}
return new \Testo\Application\Config\ApplicationConfig(
    plugins: \Testo\Application\Config\Plugin\ApplicationPlugins::without(\Testo\Output\JUnit\JUnitPlugin::class)->with(new \Testo\Output\JUnit\JUnitPlugin(testTypes: [])),
    suites: [new \Testo\Application\Config\SuiteConfig(name: 'Sources', location: [__DIR__.'/cases'], plugins: $plugins)],
);
