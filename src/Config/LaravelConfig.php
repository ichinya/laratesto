<?php

declare(strict_types=1);

namespace Testo\Bridge\Laravel\Config;

/**
 * Configuration for the Laravel bridge.
 *
 * @api
 */
final readonly class LaravelConfig
{
    /**
     * @param non-empty-string $basePath Laravel application base path (the project root).
     *        The framework is bootstrapped from `{$basePath}/bootstrap/app.php`.
     * @param non-empty-string $environment Environment name. It is forced into `APP_ENV`
     *        before bootstrapping, so the framework loads `.env.{$environment}` when that file exists.
     * @param array<non-empty-string, mixed> $config Configuration values to override
     *        after the framework is bootstrapped (`config()->set($key, $value)`).
     */
    public function __construct(
        public string $basePath,
        public string $environment = 'testing',
        public array $config = [],
    ) {
        $basePath !== '' or throw new \InvalidArgumentException('The base path must not be empty.');
        \is_dir($basePath) or throw new \InvalidArgumentException(
            \sprintf('The Laravel base path "%s" does not exist.', $basePath),
        );
    }

    /**
     * Path to the framework bootstrap file.
     *
     * @return non-empty-string
     */
    public function bootstrapFile(): string
    {
        return $this->basePath . \DIRECTORY_SEPARATOR . 'bootstrap' . \DIRECTORY_SEPARATOR . 'app.php';
    }
}
