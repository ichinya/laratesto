<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

final class HttpReceiverProvenancePipelineTest
{
    #[Test]
    public function unrelatedNativeReceiversStayCleanAndResponseOrUnknownReceiversStayMarked(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-http-dto-' . \bin2hex(\random_bytes(8));
        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true));
        $cases = [
            'LocalDto' => 'public function example(): void { $dto = new OwnDto; $dto->assertDownload(); $dto->withoutExceptionHandling(); }',
            'AliasDto' => 'public function example(): void { $dto = new OwnDto; $alias = $dto; $alias->assertDownload(); }',
            'TypedDto' => 'public function example(OwnDto $dto): void { $dto->assertDownload(); }',
            'PropertyDto' => 'private OwnDto $dto; public function example(): void { $this->dto->assertDownload(); }',
            'MethodDto' => 'public function dto(): OwnDto { return new OwnDto; } public function example(): void { $this->dto()->assertDownload(); }',
            'OwnHelper' => 'public function withoutExceptionHandling(): static { return $this; } public function example(): void { $this->withoutExceptionHandling(); }',
            'ObjectType' => 'public function example(object $dto): void { $dto->assertDownload(); }',
            'Unknown' => 'public function example($dto): void { $dto->assertDownload(); }',
            'ResponseParameter' => 'public function example(ResponseAlias $response): void { $response->assertDownload(); }',
            'AliasResponse' => 'public function example(): void { $response = $this->get("/"); $alias = $response; $alias->assertDownload(); }',
            'Union' => 'public function example(OwnDto|ResponseAlias $value): void { $value->assertDownload(); }',
            'Reassigned' => 'public function example(): void { $dto = new OwnDto; $dto = $this->get("/"); $dto->assertDownload(); }',
            'ResponseNew' => 'public function example(): void { $response = new ResponseAlias(new \\Symfony\\Component\\HttpFoundation\\Response()); $response->assertDownload(); }',
            'TraitHelper' => 'public function example(): void { $alias = $this; $alias->withoutExceptionHandling(); }',
            'HelperAlias' => 'public function example(): void { $alias = $this; $alias->withoutExceptionHandling(); }',
        ];
        $supported = ['LocalDto', 'AliasDto', 'TypedDto', 'PropertyDto', 'MethodDto', 'OwnHelper'];
        try {
            \file_put_contents($tmpDir . '/corpus/OwnDto.php', '<?php namespace HttpDto; final class OwnDto { public function assertDownload(): void {} public function withoutExceptionHandling(): void {} }');
            foreach ($cases as $name => $body) {
                \file_put_contents($tmpDir . '/corpus/' . $name . '.php', '<?php namespace HttpDto; '
                    . 'use Illuminate\\Testing\\TestResponse as ResponseAlias; final class '
                    . $name . ($name === 'TraitHelper' ? ' { use \\Laratesto\\Testing\\InteractsWithLaravel; ' : ' extends \\Illuminate\\Foundation\\Testing\\TestCase { ') . $body . ' }');
            }
            foreach (\glob($tmpDir . '/corpus/*.php') ?: [] as $file) {
                $this->assertValidPhp($file);
            }
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            foreach ($cases as $name => $body) {
                $output = (string) \file_get_contents($tmpDir . '/corpus/' . $name . '.php');
                if (\in_array($name, $supported, true)) {
                    Assert::string($output)->notContains('laratesto-residual', $name);
                    Assert::string($output)->contains('extends \\Laratesto\\Testing\\LaravelTestCase', $name);
                } else {
                    Assert::string($output)->contains(\in_array($name, ['HelperAlias', 'TraitHelper'], true) ? 'HTTP_UNSUPPORTED_SIGNATURE' : 'RESPONSE_UNSUPPORTED_API', $name);
                }
                $this->assertValidPhp($tmpDir . '/corpus/' . $name . '.php');
            }
            $before = $this->snapshotDirectory($tmpDir . '/corpus');
            $this->runRector($rootDir, $tmpDir, [$tmpDir . '/corpus']);
            Assert::same($before, $this->snapshotDirectory($tmpDir . '/corpus'));
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private function assertValidPhp(string $path): void
    {
        \exec('php -l ' . \escapeshellarg($path) . ' 2>&1', $out, $code);

        Assert::same(0, $code, "php -l failed for {$path}: " . \implode("\n", $out));
    }

    private function runRector(string $rootDir, string $tmpDir, array $paths): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(\is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $cacheExport = \var_export($tmpDir . '/rector-cache', true);
        $pathsExport = \var_export($paths, true);
        $setExport = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
        \file_put_contents($tmpDir . '/rector.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Rector\Config\RectorConfig;

            return RectorConfig::configure()
                ->withCache(cacheDirectory: {$cacheExport})
                ->withPaths({$pathsExport})
                ->withSets([{$setExport}]);
            PHP);

        $process = \proc_open(
            [\PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi', '--clear-cache'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir,
        );
        Assert::true(\is_resource($process), 'proc_open failed');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
    }

    /**
     * @return array<string, string>
     */
    private function snapshotDirectory(string $directory): array
    {
        $hashes = [];

        foreach (\glob($directory . '/*.php') ?: [] as $file) {
            $hashes[$file] = (string) \file_get_contents($file);
        }

        \ksort($hashes);

        return $hashes;
    }

    private static function recursiveRemove(string $dir): void
    {
        if (! \is_dir($dir)) {
            return;
        }

        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            \is_dir($path) ? self::recursiveRemove($path) : @\unlink($path);
        }

        @\rmdir($dir);
    }
}
