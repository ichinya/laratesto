<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class LaravelLogChannelsCompatibilityTest
{
    #[Test]
    public function nativeChannelsKeepLifecycleOwnershipAndRepeatPolicies(): void
    {
        $run = TestoCompatibilityRunner::run('log-channels');
        Assert::same(1, $run['exit']);
        Assert::same(9, $run['json']['totals']['total']);
        Assert::same(6, $run['json']['totals']['passed']);
        Assert::same(2, $run['json']['totals']['failed']);
        Assert::same(1, $run['json']['totals']['flaky']);
        $messages = self::messages($run);
        $encoded = json_encode($messages, JSON_THROW_ON_ERROR);
        Assert::string($encoded)->notContains('stale application')->notContains('bootstrap message')->notContains('private-binding')->notContains('private-context');
        $failure = self::forTest($messages, 'BaseCase::failure');
        foreach (['setup message', 'body message', 'teardown message', 'connection cleanup'] as $marker) {
            Assert::string(json_encode($failure))->contains($marker);
        }
        Assert::same(1, count(array_filter($failure, static fn($entry) => $entry['channel'] === 'sql' && str_contains($entry['content'], '/* body */'))));
        Assert::string(json_encode(self::forTest($messages, 'BaseCase::following')))->contains('[secondary]')->notContains('/* body */');
        Assert::string(json_encode(self::forTest($messages, 'BaseCase::database')))->contains('create table');
        Assert::string(json_encode(self::forTest($messages, 'TraitCase::plainTrait')))->contains('trait message');
        Assert::string(json_encode(self::forTest($messages, 'BaseCase::fiber')))->contains('fiber before suspension')->contains('fiber after suspension');
        $retry = array_values(array_filter(self::forTest($messages, 'BaseCase::retry'), static fn($entry) => str_contains($entry['content'], 'retry execution')));
        Assert::same([1, 2], array_column(array_column($retry, 'context'), 'execution'));
        $repeat = array_values(array_filter(self::forTest($messages, 'BaseCase::repeat'), static fn($entry) => str_contains($entry['content'], 'repeat execution')));
        Assert::same(1, count($repeat));
        Assert::same(2, $repeat[0]['context']['execution']);
        Assert::string(json_encode($run['json']['failures']))->contains('"channel":"sql"')->contains('"channel":"laravel"')->contains('secondary teardown failure');
        Assert::same(9, count($run['junit']->xpath('//testcase')));
        Assert::string($run['junit']->asXML())->notContains('body message');
    }

    #[Test]
    public function disabledAndDetailedCaptureKeepTheOriginalTestFailures(): void
    {
        foreach (['disabled', 'details'] as $mode) {
            $run = TestoCompatibilityRunner::run('log-channels', ['LARATESTO_LOG_MODE' => $mode]);
            Assert::same(1, $run['exit'], $mode);
            Assert::same(2, $run['json']['totals']['failed'], $mode);
            $messages = self::messages($run);
            if ($mode === 'disabled') {
                Assert::same([], $messages);
                continue;
            }
            $body = self::forTest($messages, 'BaseCase::failure');
            Assert::string(json_encode($body))->contains('private-binding')->contains('private-context');
            $failure = array_values(array_filter($run['json']['failures'], static fn($entry) => str_ends_with($entry['test'], 'FaultCase::fault')))[0];
            Assert::string($failure['message'])->contains('primary fault body failure');
            Assert::string(json_encode($failure))->contains('context serialization failed')->contains('secondary teardown failure');
            Assert::string(json_encode(self::forTest($messages, 'FaultCase::clean')))->contains('clean after fault');
        }
        $run = TestoCompatibilityRunner::run('log-channels', ['LARATESTO_LOG_FAULT' => 'setup']);
        Assert::same(1, $run['exit']);
        Assert::same(1, $run['json']['totals']['aborted']);
        Assert::string(json_encode($run['json']['failures']))->contains('primary setup failure');
        Assert::string(json_encode(self::forTest(self::messages($run), 'FaultCase::fault')))->contains('fault setup')->notContains('fault teardown');
        Assert::string(json_encode(self::forTest(self::messages($run), 'FaultCase::clean')))->contains('clean after fault');
    }

    #[Test]
    public function nativeTerminalRendersBothChannels(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, $root.'/vendor/bin/testo', 'run', '--config='.$root.'/tests/Fixture/testo-compatibility/log-channels/testo.php', '--filter=BaseCase::failure', '--no-ansi'], $root, timeout: 120);
        Assert::same(1, $process->run(), $process->getErrorOutput());
        Assert::string($process->getOutput())->contains('[sql]')->contains('[laravel]')->contains('body message')->contains('select ? as value');
    }

    private static function messages(array $run): array
    {
        return array_values(array_filter($run['events'], static fn($entry) => $entry['event'] === 'message' && in_array($entry['channel'], ['sql', 'laravel'], true)));
    }

    private static function forTest(array $messages, string $suffix): array
    {
        return array_values(array_filter($messages, static fn($entry) => str_ends_with($entry['test'] ?? '', $suffix)));
    }
}
