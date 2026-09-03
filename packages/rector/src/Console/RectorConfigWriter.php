<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Laratesto\Rector\Configuration\BaseClassConfiguration;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;

/**
 * Generates the throwaway Rector configuration for one migration run: the public
 * set, the processed paths and the explicit target-mode/base-class overrides.
 * Base class names are written in canonical `Tests\ApiTestCase` form — defaults
 * first, extras canonicalized through
 * {@see BaseClassConfiguration::canonicalBaseClasses()}, duplicates removed.
 */
final class RectorConfigWriter
{
    /**
     * @param (\Closure(): non-empty-string)|null $targetPathAllocator Test seam:
     *        produces the final config path instead of the tempnam()-based
     *        default, so tests can point the write at an adversarial target.
     */
    public function __construct(
        private readonly ?\Closure $targetPathAllocator = null,
    ) {
    }

    /**
     * @param list<non-empty-string> $absolutePaths
     * @param list<non-empty-string> $extraBaseClasses
     * @return non-empty-string Path to the freshly created config (a unique temp file).
     * @throws \RuntimeException When the config cannot be written, or when the target
     *         path already exists at creation time — e.g. a link planted between
     *         path allocation and creation — which is refused, never written through.
     * @throws \InvalidArgumentException When an extra base class is empty or malformed.
     */
    public function write(string $targetMode, array $absolutePaths, array $extraBaseClasses): string
    {
        $pathsCode = \implode(', ', \array_map(
            static fn(string $path): string => \var_export($path, true),
            $absolutePaths,
        ));

        $setCode = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);

        $baseConfiguration = $extraBaseClasses === []
            ? ''
            : \sprintf(
                '%s::BASE_CLASSES => [%s],',
                '\\' . LaravelBaseClassRector::class,
                \implode(', ', \array_map(
                    static fn(string $base): string => \var_export($base, true),
                    BaseClassConfiguration::canonicalBaseClasses([
                        ...BaseClassConfiguration::DEFAULT_BASE_CLASSES,
                        ...$extraBaseClasses,
                    ]),
                )),
            );

        $overrideCode = \sprintf(
                <<<'PHP'
                    // The set already registered the rule; this re-configures the same instance.
                    $rectorConfig->ruleWithConfiguration(%s::class, [
                        %s
                        %s::TARGET_MODE => %s,
                    ]);

                    PHP,
                \var_export(LaravelBaseClassRector::class, true),
                $baseConfiguration,
                '\\' . LaravelBaseClassRector::class,
                \var_export($targetMode, true),
            );

        $body = <<<PHP
            <?php

            declare(strict_types=1);

            use Laratesto\\Rector\\Rules\\LaravelBaseClassRector;
            use Laratesto\\Rector\\Set\\LaratestoRectorSetList;
            use Rector\\Config\\RectorConfig;

            \$builder = RectorConfig::configure()
                ->withPaths([{$pathsCode}])
                ->withSets([{$setCode}]);

            return static function (RectorConfig \$rectorConfig) use (\$builder): void {
                \$builder(\$rectorConfig);
            {$overrideCode}};

            PHP;

        $config = $this->allocateTargetPath();

        // Two fail-closed gates. lstat() refuses every pre-existing entry —
        // including the dangling reparse points Windows' exclusive create
        // happily "creates through". The exclusive create (`x` = O_CREAT|O_EXCL)
        // then closes the race atomically: a link planted between the gates
        // makes the open fail instead of being followed.
        \clearstatcache(true);

        $refusal = \sprintf('Refusing to write the temporary Rector configuration to "%s": the target path already exists and may be a planted link.', $config);

        if (@\lstat($config) !== false) {
            throw new \RuntimeException($refusal);
        }

        $handle = @\fopen($config, 'x');

        if ($handle === false) {
            throw new \RuntimeException($refusal);
        }

        $written = @\fwrite($handle, $body);
        $flushed = @\fflush($handle);
        $closed = @\fclose($handle);

        if ($written === false || $written !== \strlen($body) || $flushed === false || $closed === false) {
            @\unlink($config);

            throw new \RuntimeException(\sprintf('Unable to write the temporary Rector configuration to "%s".', $config));
        }

        return $config;
    }

    /**
     * Produces the final config path: the `.php` sibling of a unique temp base,
     * whose extensionless placeholder is dropped right away instead of leaking
     * one per run. The path is handed back WITHOUT creating it — the exclusive
     * create in write() refuses any pre-existing entry, links included.
     */
    private function allocateTargetPath(): string
    {
        if ($this->targetPathAllocator !== null) {
            return ($this->targetPathAllocator)();
        }

        $base = \tempnam(\sys_get_temp_dir(), 'laratesto-rector-');

        if ($base === false) {
            throw new \RuntimeException('Unable to create a temporary Rector configuration file.');
        }

        @\unlink($base);

        return $base . '.php';
    }
}
