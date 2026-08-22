<?php

declare(strict_types=1);

namespace Laratesto\Console\Commands;

use Illuminate\Console\Command;
use Laratesto\Console\TestProcessRunner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run Testo first, followed by one installed legacy-compatible runner.
 */
final class RunTestsCommand extends Command
{
    protected $signature = 'test
        {paths?* : Test files or path globs to run}
        {--testo-only : Run Testo and skip Pest/PHPUnit}
        {--legacy-only : Skip Testo and run the installed Pest/PHPUnit runner}
        {--fail-fast : Do not start another runner after a runner fails}
        {--filter=* : Filter test names}
        {--group=* : Include test groups}
        {--suite=* : Include suites (Testo name)}
        {--testsuite=* : Include suites (PHPUnit/Pest-compatible alias)}
        {--path=* : Test files or path globs to run}
        {--coverage : Enable code coverage output}
        {--no-coverage : Disable configured code coverage}
        {--without-tty : Keep output non-interactive for CI compatibility}
        {--testo-arg=* : Pass an argument only to Testo}
        {--legacy-arg=* : Pass an argument only to Pest or PHPUnit}';

    protected $description = 'Run Testo, then Pest or PHPUnit when one is installed';

    public function __construct(
        private readonly TestProcessRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $testoOnly = (bool) $this->option('testo-only');
        $legacyOnly = (bool) $this->option('legacy-only');

        if ($testoOnly && $legacyOnly) {
            $this->components->error('--testo-only and --legacy-only cannot be combined.');

            return SymfonyCommand::INVALID;
        }
        if ($this->option('coverage') && $this->option('no-coverage')) {
            $this->components->error('--coverage and --no-coverage cannot be combined.');

            return SymfonyCommand::INVALID;
        }

        $basePath = \realpath(\base_path());
        if ($basePath === false) {
            $this->components->error('Unable to resolve the Laravel project root.');

            return SymfonyCommand::FAILURE;
        }

        $failed = false;
        $ran = false;

        if (!$legacyOnly) {
            $testo = $this->binary($basePath, 'testo');
            if ($testo === null) {
                $this->components->error('Testo is not installed at vendor/bin/testo.');
                $failed = true;
            } else {
                $ran = true;
                $failed = !$this->runStage('Testo', $this->testoCommand($testo), $basePath);
            }
        }

        if (!$testoOnly && !($failed && $this->option('fail-fast'))) {
            $legacy = $this->legacyBinary($basePath);
            if ($legacy === null) {
                if ($legacyOnly) {
                    $this->components->error('Neither Pest nor PHPUnit is installed in vendor/bin.');
                    $failed = true;
                } else {
                    $this->components->info('Pest/PHPUnit not installed; legacy test stage skipped.');
                }
            } else {
                [$name, $binary] = $legacy;
                $ran = true;
                $failed = !$this->runStage(
                    $name,
                    $this->legacyCommand($name, $binary),
                    $basePath,
                ) || $failed;
            }
        }

        if (!$ran) {
            return SymfonyCommand::FAILURE;
        }

        return $failed ? SymfonyCommand::FAILURE : SymfonyCommand::SUCCESS;
    }

    /** @param list<string> $command */
    private function runStage(string $name, array $command, string $basePath): bool
    {
        $this->components->info("Running {$name}");

        $exitCode = $this->runner->run(
            $command,
            $basePath,
            function (string $buffer, bool $error): void {
                $output = $this->getOutput()->getOutput();
                if ($error && $output instanceof ConsoleOutputInterface) {
                    $output = $output->getErrorOutput();
                }
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            },
        );

        if ($exitCode !== SymfonyCommand::SUCCESS) {
            $this->components->error("{$name} failed with exit code {$exitCode}.");
        }

        return $exitCode === SymfonyCommand::SUCCESS;
    }

    /** @return list<string> */
    private function testoCommand(string $binary): array
    {
        $command = [\PHP_BINARY, $binary, 'run'];

        foreach ($this->optionValues('filter') as $value) {
            $command[] = "--filter={$value}";
        }
        foreach ($this->optionValues('group') as $value) {
            $command[] = "--group={$value}";
        }
        foreach ($this->suiteValues() as $value) {
            $command[] = "--suite={$value}";
        }
        foreach ($this->pathValues() as $value) {
            $command[] = "--path={$value}";
        }
        if ($this->option('coverage')) {
            $command[] = '--coverage';
        }
        if ($this->option('no-coverage')) {
            $command[] = '--no-coverage';
        }

        return [...$command, ...$this->optionValues('testo-arg')];
    }

    /** @return list<string> */
    private function legacyCommand(string $name, string $binary): array
    {
        $command = [\PHP_BINARY, $binary];

        foreach ($this->optionValues('filter') as $value) {
            $command[] = "--filter={$value}";
        }
        foreach ($this->optionValues('group') as $value) {
            $command[] = "--group={$value}";
        }
        foreach ($this->suiteValues() as $value) {
            $command[] = "--testsuite={$value}";
        }
        if ($this->option('coverage')) {
            $command[] = $name === 'Pest' ? '--coverage' : '--coverage-text';
        }
        if ($this->option('no-coverage')) {
            $command[] = '--no-coverage';
        }

        return [
            ...$command,
            ...$this->pathValues(),
            ...$this->optionValues('legacy-arg'),
        ];
    }

    /** @return list<string> */
    private function pathValues(): array
    {
        $arguments = $this->argument('paths');
        $arguments = \is_array($arguments) ? $arguments : [];

        return $this->uniqueStrings([...$arguments, ...$this->optionValues('path')]);
    }

    /** @return list<string> */
    private function suiteValues(): array
    {
        return $this->uniqueStrings([
            ...$this->optionValues('suite'),
            ...$this->optionValues('testsuite'),
        ]);
    }

    /** @return list<string> */
    private function optionValues(string $name): array
    {
        $values = $this->option($name);

        return $this->uniqueStrings(\is_array($values) ? $values : []);
    }

    /** @param array<array-key, mixed> $values @return list<string> */
    private function uniqueStrings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (!\is_string($value) || $value === '' || \in_array($value, $strings, true)) {
                continue;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function binary(string $basePath, string $name): ?string
    {
        $path = $basePath . \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR . 'bin'
            . \DIRECTORY_SEPARATOR . $name;

        return \is_file($path) ? $path : null;
    }

    /** @return array{string, string}|null */
    private function legacyBinary(string $basePath): ?array
    {
        $pest = $this->binary($basePath, 'pest');
        if ($pest !== null) {
            return ['Pest', $pest];
        }

        $phpunit = $this->binary($basePath, 'phpunit');

        return $phpunit === null ? null : ['PHPUnit', $phpunit];
    }
}
