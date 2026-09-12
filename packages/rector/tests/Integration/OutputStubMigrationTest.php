<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Illuminate\Filesystem\Filesystem;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Laratesto\Testing\PhpUnitCompatibility;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class OutputStubMigrationTest
{
    #[Test]
    public function stubGenerationPreservesDisabledAutomaticReturnValues(): void
    {
        $stub = PhpUnitCompatibility::createStub(StubWithTypedReturn::class, StubWithoutReturnValuesSource::class);

        $caught = false;
        try {
            $stub->value();
        } catch (\Throwable $failure) {
            $caught = true;
            Assert::true(str_contains($failure->getMessage(), 'value'), 'Exception must mention the unconfigured method.');
        }

        Assert::true($caught, 'Disabled return generation must reject an unconfigured typed method.');
    }

    #[Test]
    public function outputAndStubBehaviorSurvivesMigration(): void
    {
        $root = dirname(__DIR__, 4);
        $temporary = sys_get_temp_dir().'/laratesto-output-stub-'.bin2hex(random_bytes(6));
        mkdir($temporary.'/corpus', 0777, true);
        $file = $temporary.'/corpus/OutputStubTest.php';
        copy($root.'/tests/Fixture/issue10/OutputStubTest.php', $file);
        $parameter = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'createStub'))->getParameters()[0]->getName();
        file_put_contents($file, str_replace('originalClassName:', $parameter.':', file_get_contents($file)));
        $environment = ['LARATESTO_ISSUE10_APP' => $root.'/tests/Fixture/laravel', 'APP_ENV' => 'testing'];
        try {
            $sourceReport = simplexml_load_file($root.'/tests/Fixture/issue10/junit/OutputStubTest.junit.xml');
            Assert::same(5, count($sourceReport->xpath('//testcase')));
            Assert::same(1, count($sourceReport->xpath('//testcase/failure')));
            Assert::same(0, count($sourceReport->xpath('//testcase/error')));
            $set = var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            $cache = var_export($temporary.'/cache', true);
            file_put_contents($temporary.'/rector.php', '<?php return static function (\\Rector\\Config\\RectorConfig $config): void { $config->import('.$set.'); $config->cacheDirectory('.$cache.'); $config->disableParallel(); };');
            $command = [PHP_BINARY, $root.'/vendor/bin/rector', 'process', $temporary.'/corpus', '--config', $temporary.'/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'];
            $this->run($command, $root, $environment);
            $first = file_get_contents($file);
            Assert::false(str_contains($first, 'laratesto-residual'), $first);
            Assert::string($first)->contains('PhpUnitCompatibility::createStub');
            $this->run([PHP_BINARY, '-l', $file], $root, $environment);
            $this->run($command, $root, $environment);
            Assert::same($first, file_get_contents($file), 'A second migration must preserve every byte.');
            $location = var_export([$temporary.'/corpus'], true);
            $app = var_export($root.'/tests/Fixture/laravel', true);
            file_put_contents($temporary.'/testo.php', '<?php return new \\Testo\\Application\\Config\\ApplicationConfig(suites: [new \\Testo\\Application\\Config\\SuiteConfig(name: "Migration", location: '.$location.', plugins: [new \\Testo\\Convention\\NamingConventionPlugin(), new \\Laratesto\\LaravelPlugin(new \\Laratesto\\Config\\LaravelConfig(basePath: '.$app.'))])]);');
            $this->run([PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config', $temporary.'/testo.php', '--log-json='.$temporary.'/result.json'], $root, $environment, 1);
            $report = json_decode(file_get_contents($temporary.'/result.json'), true, flags: JSON_THROW_ON_ERROR);
            Assert::same(['total' => 5, 'passed' => 4, 'failed' => 1], $report['totals'], json_encode($report['failures']));
            $failures = array_column($report['failures'], 'test');
            sort($failures);
            Assert::same(['Tests\\OutputStubTest::testWrongOutputFails'], $failures);
        } finally {
            (new Filesystem())->deleteDirectory($temporary);
        }
    }

    private function run(array $command, string $root, array $environment, int $expectedExit = 0): void
    {
        $process = new Process($command, $root, $environment, timeout: 120);
        $exit = $process->run();
        Assert::same($expectedExit, $exit, $exit === $expectedExit ? '' : $process->getOutput().$process->getErrorOutput());
    }
}

#[\PHPUnit\Framework\Attributes\DisableReturnValueGenerationForTestDoubles]
final class StubWithoutReturnValuesSource extends \PHPUnit\Framework\TestCase
{
    public static function makeStub(): \PHPUnit\Framework\MockObject\Stub
    {
        return parent::createStub(StubWithTypedReturn::class);
    }
}

class StubWithTypedReturn
{
    public function value(): int { return 99; }
}
