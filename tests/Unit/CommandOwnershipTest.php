<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Console\Commands\RunTestsCommand;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class CommandOwnershipTest
{
    #[Test]
    public function preservesTheExistingCommandUnlessReplacementIsExplicit(): void
    {
        $root = \dirname(__DIR__, 2);
        $script = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            $app = require $argv[1] . '/tests/Fixture/laravel/bootstrap/app.php';
            $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class, static function ($app) use ($argv) {
                $app['config']->set('laratesto.replace_test_command', $argv[2] === '1');
            });
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $commands = $kernel->all();
            echo json_encode([get_class($commands['test']), get_class($commands['laratesto:test'])], JSON_THROW_ON_ERROR);
            PHP;
        foreach (['0' => 'App\Commands\CompetingTestCommand', '1' => RunTestsCommand::class] as $enabled => $owner) {
            $process = new Process([\PHP_BINARY, '-r', $script, $root, (string) $enabled], $root);
            $exit = $process->run();
            Assert::same(0, $exit, $process->getErrorOutput());
            Assert::same([$owner, RunTestsCommand::class], \json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        }
        $defaults = require $root . '/config/laratesto.php';
        Assert::false($defaults['replace_test_command']);
    }
}
