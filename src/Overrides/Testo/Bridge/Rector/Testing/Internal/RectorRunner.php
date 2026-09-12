<?php

declare(strict_types=1);

namespace Testo\Bridge\Rector\Testing\Internal;

use Psr\Log\LoggerInterface;
use Rector\Application\ApplicationFileProcessor;
use Rector\Configuration\ConfigurationFactory;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Contract\Rector\RectorInterface;
use Rector\DependencyInjection\LazyContainerFactory;
use Rector\NodeTypeResolver\Reflection\BetterReflection\SourceLocatorProvider\DynamicSourceLocatorProvider;
use Rector\PhpParser\NodeTraverser\RectorNodeTraverser;
use Rector\Autoloading\AdditionalAutoloader;
use Internal\Path;
use Rector\Testing\Fixture\FixtureSplitter;
use Testo\Assert;
use Testo\Common\Messenger;

/**
 * Laratesto override of the Testo Rector fixture runner.
 *
 * The stock runner creates a bare Rector container that does not see the
 * PHPUnit compatibility shim or the Laravel framework test source.  This breaks
 * fixture tests for any rule that reflects on \Illuminate\Foundation\Testing\TestCase
 * or its PHPUnit parent when phpunit/phpunit is not installed.  We keep the
 * original behaviour intact and only add the autoload paths needed to resolve
 * those classes from source.
 *
 * @internal
 * @psalm-internal Testo\Bridge\Rector
 */
final readonly class RectorRunner
{
    private ApplicationFileProcessor $fileProcessor;
    private DynamicSourceLocatorProvider $sourceLocator;
    private ConfigurationFactory $configurationFactory;
    private AdditionalAutoloader $additionalAutoloader;
    private LoggerInterface $channel;

    /**
     * @param list<class-string<RectorInterface>> $rules
     */
    public function __construct(Messenger $messenger, array $rules)
    {
        $this->channel = $messenger->channel('rector-fixture.php');
        $rectorConfig = (new LazyContainerFactory())->create();
        $rectorConfig->boot();

        $rectorConfig->autoloadPaths(self::discoverAutoloadPaths());

        foreach ($rules as $rule) {
            $rectorConfig->rule($rule);
        }

        $tagged = $rectorConfig->tagged(RectorInterface::class);
        $rectors = \is_iterable($tagged) ? \iterator_to_array($tagged, false) : [];
        $rectorConfig->make(RectorNodeTraverser::class)->refreshPhpRectors($rectors);

        $this->fileProcessor = $rectorConfig->make(ApplicationFileProcessor::class);
        $this->sourceLocator = $rectorConfig->make(DynamicSourceLocatorProvider::class);
        $this->configurationFactory = $rectorConfig->make(ConfigurationFactory::class);
        $this->additionalAutoloader = $rectorConfig->make(AdditionalAutoloader::class);
    }

    /**
     * @return list<non-empty-string>
     */
    private static function discoverAutoloadPaths(): array
    {
        // This file sits at src/Overrides/Testo/Bridge/Rector/Testing/Internal.
        // Seven levels up reaches the package root in the monorepo, or
        // vendor/ichinya/laratesto in a consumer install.
        $packageRoot = \realpath(\dirname(__DIR__, 7));
        if ($packageRoot === false) {
            return [];
        }

        $vendorDir = \is_file($packageRoot . '/vendor/autoload.php')
            ? $packageRoot . '/vendor'
            : \dirname($packageRoot) . '/vendor';

        $paths = [
            $packageRoot . '/src/Shim/PhpUnit',
            $packageRoot . '/src/Shim/SebastianBergmann',
            $packageRoot . '/src/Testing',
        ];

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing/Concerns')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Foundation/Testing/Concerns';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Testing')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Testing';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Http')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Http';
        }

        if (\is_dir($vendorDir . '/laravel/framework/src/Illuminate/Mail')) {
            $paths[] = $vendorDir . '/laravel/framework/src/Illuminate/Mail';
        }

        return \array_filter($paths, 'is_dir');
    }

    public function assertConverts(Path $fixturePath): void
    {
        $contents = (string) \file_get_contents((string) $fixturePath);
        [$input, $expected] = FixtureSplitter::containsSplit($contents)
            ? FixtureSplitter::splitFixtureFileContents($contents)
            : [$contents, $contents];
        $this->channel->debug("# Input:\n$input");
        $this->channel->debug("# Expected:\n$expected");

        $inputFile = $this->writeTempFile($input);

        try {
            SimpleParameterProvider::setParameter(Option::SOURCE, [$inputFile]);

            $this->sourceLocator->reset();
            $this->additionalAutoloader->autoloadPaths();
            $this->sourceLocator->setFilePath($inputFile);
            $configuration = $this->configurationFactory->createForTests([$inputFile]);
            $this->fileProcessor->processFiles([$inputFile], $configuration);

            $changed = (string) \file_get_contents($inputFile);
        } finally {
            @\unlink($inputFile);
        }

        Assert::same($changed, $expected, \sprintf('Fixture "%s" was not converted as expected', $fixturePath->name()));
    }

    /**
     * @return non-empty-string
     */
    private function writeTempFile(string $contents): string
    {
        $base = \tempnam(\sys_get_temp_dir(), 'testo-rector-');
        $base === false and throw new \RuntimeException('Unable to create a temporary fixture file');

        $path = $base . '.php';
        \rename($base, $path);
        \file_put_contents($path, $contents);

        return $path;
    }
}
