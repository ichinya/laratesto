<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class AssertionTraceCompatibilityTest
{
    #[Test]
    public function nativeCliReportsAssertionCallSitesAndPreservesCallbackLocations(): void
    {
        $root = dirname(__DIR__, 2);
        $fixture = $root.'/tests/Fixture/testo-compatibility/assertion-traces';
        $report = $root.'/storage/framework/testing/assertion-traces-'.bin2hex(random_bytes(8)).'.json';
        $process = new Process([PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config='.$fixture.'/testo.php', '--no-ansi', '--log-json='.$report], $root, timeout: 120);
        try {
            Assert::same(1, $process->run(), $process->getErrorOutput());
            Assert::false(\Composer\InstalledVersions::isInstalled('phpunit/phpunit'));
            $json = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            Assert::same(16, $json['totals']['total']);
            Assert::same(1, $json['totals']['passed']);
            Assert::same(13, $json['totals']['failed']);
            Assert::same(2, $json['totals']['error']);
            $failures = [];
            foreach ($json['failures'] as $failure) {
                $failures[substr($failure['test'], strrpos($failure['test'], '::') + 2)] = $failure;
            }

            $file = $fixture.'/cases/AssertionTraceTest.php';
            foreach (file($file) as $index => $line) {
                if (!preg_match('/\/\/ trace: (\w+)/', $line, $match)) {
                    continue;
                }
                $failure = $failures[$match[1]];
                Assert::string($process->getOutput())->contains($failure['trace'][0]);
                if ($match[1] === 'callbackException') {
                    Assert::same(\RuntimeException::class, $failure['exception']);
                    Assert::same('User callback exception', $failure['message']);
                    Assert::same(realpath($file), $failure['file']);
                    Assert::same($index + 1, $failure['line']);
                    Assert::true(count($failure['trace']) > 2, 'The callback frame must remain visible.');
                    continue;
                }
                Assert::string($failure['trace'][0])->startsWith('#0 '.realpath($file).':'.($index + 1).': ');
            }
        } finally {
            is_file($report) && unlink($report);
        }
    }
}
