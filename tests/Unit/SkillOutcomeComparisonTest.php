<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

#[Test]
final class SkillOutcomeComparisonTest
{
    public function comparesRealOutcomeDifferencesAndRejectsIncompleteInputs(): void
    {
        $directory = sys_get_temp_dir() . '/laratesto-outcomes-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $before = '<testsuite><testcase class="Tests\\Example" name="testPass"/></testsuite>';
        $cases = [
            [$before, 0],
            ['<testsuite><testcase classname="Tests\\Example" name="testPass"><failure/></testcase></testsuite>', 1],
            ['<testsuite><testcase classname="Tests\\Example" name="testPass"><skipped/></testcase></testsuite>', 1],
            ['<testsuite><testcase classname="Tests\\Example" name="testOther"/></testsuite>', 1],
            ['<testsuite xmlns:testo="https://php-testo.github.io/schema/junit/1"><testcase classname="Tests\\Example" name="testPass" testo:status="risky"/></testsuite>', 1],
            ['<testsuite/>', 2],
            ['<broken', 2],
            ['<testsuite><testcase name="testPass"/></testsuite>', 2],
            ['<!DOCTYPE testsuite><testsuite/>', 2],
        ];
        try {
            file_put_contents($directory . '/before.xml', $before);
            foreach ($cases as [$after, $expected]) {
                file_put_contents($directory . '/after.xml', $after);
                $process = new Process([PHP_BINARY, dirname(__DIR__, 2) . '/skills/laratesto-migrate-from-phpunit/scripts/compare-outcomes.php',
                    $directory . '/before.xml', $directory . '/after.xml'], timeout: 15);
                Assert::same($process->run(), $expected, $process->getOutput() . $process->getErrorOutput());
            }
        } finally {
            unlink($directory . '/before.xml');
            unlink($directory . '/after.xml');
            rmdir($directory);
        }
    }

    public function normalizesRunnerDatasetSuffixesAndCountsEveryRun(): void
    {
        $directory = sys_get_temp_dir() . '/laratesto-datasets-' . bin2hex(random_bytes(8));
        mkdir($directory);
        try {
            file_put_contents($directory . '/before.xml', '<testsuite><testcase classname="Tests.Example" name="testPass with data set #0"/><testcase classname="Tests.Example" name="testPass with data set #1"/></testsuite>');
            file_put_contents($directory . '/after.xml', '<testsuite xmlns:testo="https://php-testo.github.io/schema/junit/1"><testcase classname="Tests\\Example" name="testPass [0]" testo:data-set="0" testo:status="passed"/><testcase classname="Tests\\Example" name="testPass [1]" testo:data-set="1" testo:status="passed"/></testsuite>');
            $command = [PHP_BINARY, dirname(__DIR__, 2) . '/skills/laratesto-migrate-from-phpunit/scripts/compare-outcomes.php',
                $directory . '/before.xml', $directory . '/after.xml'];
            $process = new Process($command, timeout: 15);
            Assert::same($process->run(), 0, $process->getOutput() . $process->getErrorOutput());
            file_put_contents($directory . '/after.xml', '<testsuite><testcase classname="Tests\\Example" name="testPass"/></testsuite>');
            $process = new Process($command, timeout: 15);
            Assert::same($process->run(), 1, 'Losing a dataset must be detected.');
        } finally {
            unlink($directory . '/before.xml');
            unlink($directory . '/after.xml');
            rmdir($directory);
        }
    }
}
