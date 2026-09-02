<?php

declare(strict_types=1);

namespace Laratesto\Rector\Console;

use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;

/**
 * Generates the throwaway Rector configuration for one migration run: the public
 * set, the processed paths and the explicit target-mode/base-class overrides.
 */
final class RectorConfigWriter
{
    /**
     * @param list<non-empty-string> $absolutePaths
     * @param list<non-empty-string> $extraBaseClasses
     * @return non-empty-string Path to the generated config (a unique temp file).
     * @throws \RuntimeException When the config cannot be written.
     */
    public function write(string $targetMode, array $absolutePaths, array $extraBaseClasses): string
    {
        // tempnam() creates an empty placeholder we do not use — drop it right away
        // instead of leaking one per run; the actual config lives next to it.
        $base = \tempnam(\sys_get_temp_dir(), 'laratesto-rector-');

        if ($base === false) {
            throw new \RuntimeException('Unable to create a temporary Rector configuration file.');
        }

        $config = $base . '.php';
        @\unlink($base);

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
                \implode(', ', [
                    \var_export('Tests\TestCase', true),
                    \var_export('Illuminate\Foundation\Testing\TestCase', true),
                    ... \array_map(static fn(string $base): string => \var_export($base, true), $extraBaseClasses),
                ]),
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

        $written = @\file_put_contents($config, <<<PHP
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

            PHP);

        if ($written === false) {
            @\unlink($config);

            throw new \RuntimeException(\sprintf('Unable to write the temporary Rector configuration to "%s".', $config));
        }

        return $config;
    }
}
