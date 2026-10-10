<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class TestoPublicCliCompatibilityTest
{
    #[Test]
    public function realPublicCommandForwardsSelectorsReportsAndChildExit(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root.'/storage/framework/testing/public-'.bin2hex(random_bytes(8));
        foreach (['bootstrap/cache', 'storage/logs'] as $path) { mkdir($directory.'/'.$path, 0777, true); }
        try {
            Assert::false(\Composer\InstalledVersions::isInstalled('phpunit/phpunit'));
            foreach (['passes' => 0, 'fails' => 1] as $method => $expectedExit) {
                $process = new Process([PHP_BINARY, $root.'/tests/Fixture/testo-compatibility/cli/artisan.php', 'laratesto:test', '--testo-only', '--without-tty', '--testsuite=Compatibility', '--filter=SmokeTest::'.$method,
                    '--testo-arg=--config='.$root.'/tests/Fixture/testo-compatibility/cli/testo.php', '--testo-arg=--log-json='.$directory.'/'.$method.'.json', '--testo-arg=--log-junit='.$directory.'/'.$method.'.xml'],
                    $root, ['LARATESTO_PUBLIC_APP_STORAGE' => $directory], timeout: 120);
                Assert::same($expectedExit, $process->run(), $process->getOutput().$process->getErrorOutput());
                $report = json_decode(file_get_contents($directory.'/'.$method.'.json'), true, flags: JSON_THROW_ON_ERROR);
                Assert::same(['total' => 1, 'assertions' => 1, $expectedExit === 0 ? 'passed' : 'failed' => 1], $report['totals']);
                $junit = simplexml_load_file($directory.'/'.$method.'.xml');
                Assert::same(1, count($junit->xpath('//testcase')));
                Assert::same($expectedExit, count($junit->xpath('//testcase/failure')));
            }
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }
}
