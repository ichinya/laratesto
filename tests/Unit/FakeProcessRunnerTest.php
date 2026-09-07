<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Rector\Console\ProcessOutcome;
use Laratesto\Tests\Support\FakeProcessRunner;
use Testo\Assert;
use Testo\Test;

/**
 * Focused coverage for the guard-test double: Git probe/status and the Rector run
 * are classified semantically by their subcommand token, so the canned outcomes
 * stay correct regardless of argv shape (option order, extra flags, no `-C`).
 */
final class FakeProcessRunnerTest
{
    #[Test]
    public function aRevParseTokenClassifiesAsTheGitProbe(): void
    {
        $runner = new FakeProcessRunner(
            rectorOutcome: new ProcessOutcome(7, 'rector', 'rector-err'),
            gitProbe: new ProcessOutcome(128, '', 'fatal: not a git repository'),
        );

        $outcome = $runner->run(['git', '-C', '/root', 'rev-parse', '--is-inside-work-tree'], '/root');

        Assert::same($outcome->exitCode, 128);
        Assert::same($outcome->stderr, 'fatal: not a git repository');
        Assert::same(1, $runner->gitProbeInvocations());
        Assert::same(0, $runner->gitStatusInvocations());
        Assert::same(0, $runner->rectorInvocations());
    }

    #[Test]
    public function aStatusTokenClassifiesAsTheGitStatusWithoutNeedingAPositionalIndex(): void
    {
        $runner = new FakeProcessRunner(
            gitStatus: new ProcessOutcome(0, "?? tests/Demo.php\0", ''),
        );

        // Deliberately re-shaped argv (no leading -C): classification must not
        // depend on where the subcommand sits.
        $outcome = $runner->run(['git', 'status', '--porcelain=v1', '-z', '--', 'tests'], '/root');

        Assert::same($outcome->exitCode, 0);
        Assert::same($outcome->stdout, "?? tests/Demo.php\0");
        Assert::same(1, $runner->gitStatusInvocations());
        Assert::same(0, $runner->gitProbeInvocations());
        Assert::same(0, $runner->rectorInvocations());
    }

    #[Test]
    public function aProcessSubcommandClassifiesAsTheRectorRun(): void
    {
        $runner = new FakeProcessRunner(
            rectorOutcome: new ProcessOutcome(2, '{"totals": {}}', ''),
        );

        $outcome = $runner->run(
            ['php', 'vendor/rector/rector/bin/rector', 'process', '--config', '/tmp/cfg.php', '--dry-run'],
            '/root',
        );

        Assert::same($outcome->exitCode, 2);
        Assert::same($outcome->stdout, '{"totals": {}}');
        Assert::same(1, $runner->rectorInvocations());
        Assert::same(0, $runner->gitProbeInvocations());
        Assert::same(0, $runner->gitStatusInvocations());
    }

    #[Test]
    public function everyInvocationIsRecordedWithItsWorkingDirectory(): void
    {
        $runner = new FakeProcessRunner();

        $runner->run(['git', '-C', '/a', 'rev-parse', '--is-inside-work-tree'], '/a');
        $runner->run(['php', 'rector', 'process', '--config', '/tmp/c.php'], '/b');

        Assert::same(
            $runner->invocations,
            [
                ['command' => ['git', '-C', '/a', 'rev-parse', '--is-inside-work-tree'], 'cwd' => '/a'],
                ['command' => ['php', 'rector', 'process', '--config', '/tmp/c.php'], 'cwd' => '/b'],
            ],
        );
    }

    #[Test]
    public function anUnconfiguredRectorRunDefaultsToACleanOutcome(): void
    {
        $runner = new FakeProcessRunner();

        $outcome = $runner->run(['php', 'rector', 'process'], '/root');

        Assert::same($outcome->exitCode, 0);
        Assert::same($outcome->stdout, '');
        Assert::same($outcome->stderr, '');
    }
}
