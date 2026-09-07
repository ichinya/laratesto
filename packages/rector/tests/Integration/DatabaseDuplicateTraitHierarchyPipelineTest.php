<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Attribute\DatabaseTransactions;
use Laratesto\Attribute\RefreshDatabase;
use Laratesto\Rector\Rules\LaravelBaseClassRector;
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Common\Reflection;
use Testo\Pipeline\Attribute\Interceptable;
use Testo\Test;

/**
 * MiMo finding 1: when a configured project base and a descendant both explicitly
 * use the same Laravel database trait, Laravel's class_uses_recursive collapsed the
 * duplication to ONE behavior, but the rector emitted the same Testo attribute on
 * BOTH classes — Testo's Reflection (MERGE_ALL over the whole hierarchy) then
 * returned two attributes and the InterceptorProvider ran the interceptor twice.
 *
 * The conversion now merges the duplicate: a class whose resolved ancestor already
 * carries the same strategy converts into NO attribute of its own and inherits the
 * ancestor's single one. The merge is only taken when the effective option
 * configuration below the topmost duplicate is provably identical to the
 * configuration that ancestor carries; anything else fails closed with
 * DATABASE_UNSUPPORTED_CONFIGURATION. Different database traits keep stacking, and
 * a single inherited attribute stays single.
 *
 * Cross-file bases, the three-level chain and the already-migrated ancestor need a
 * real pipeline (the per-rule fixtures only see one file), and the exactly-once
 * evidence is read back through the very reflection helper the Testo runtime uses.
 */
final class DatabaseDuplicateTraitHierarchyPipelineTest
{
    #[Test]
    public function duplicateTraitHierarchiesMergeIntoExactlyOneAttribute(): void
    {
        $rootDir = \dirname(__DIR__, 4);
        $tmpDir = \sys_get_temp_dir() . '/laratesto-rector-duplicate-' . \getmypid();

        Assert::true(\mkdir($tmpDir . '/corpus', 0777, true) || \is_dir($tmpDir . '/corpus'));

        try {
            \file_put_contents($tmpDir . '/corpus/Base.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Migration\Tests;

                use Illuminate\Foundation\Testing\RefreshDatabase;
                use Illuminate\Foundation\Testing\TestCase as FoundationTestCase;

                abstract class TestCase extends FoundationTestCase
                {
                    use RefreshDatabase;
                }

                abstract class FeatureCase extends TestCase
                {
                    use RefreshDatabase;
                }

                abstract class ApiTestCase extends FoundationTestCase
                {
                    use RefreshDatabase;

                    protected bool $seed = true;
                }

                #[\Laratesto\Attribute\RefreshDatabase]
                abstract class LegacyCase extends \Laratesto\Testing\LaravelTestCase
                {
                }
                PHP);

            \file_put_contents($tmpDir . '/corpus/Tests.php', <<<'PHP'
                <?php

                declare(strict_types=1);

                namespace Migration\Tests\Feature;

                use Illuminate\Foundation\Testing\DatabaseTransactions;
                use Illuminate\Foundation\Testing\RefreshDatabase;

                final class PlainDuplicateTest extends \Migration\Tests\TestCase
                {
                    use RefreshDatabase;

                    public function test_ok(): void {}
                }

                final class DeepDuplicateTest extends \Migration\Tests\FeatureCase
                {
                    use RefreshDatabase;

                    public function test_ok(): void {}
                }

                final class SameSeedTest extends \Migration\Tests\ApiTestCase
                {
                    use RefreshDatabase;

                    protected bool $seed = true;

                    public function test_ok(): void {}
                }

                final class InheritSeedTest extends \Migration\Tests\ApiTestCase
                {
                    use RefreshDatabase;

                    public function test_ok(): void {}
                }

                final class OtherSeedTest extends \Migration\Tests\ApiTestCase
                {
                    use RefreshDatabase;

                    protected bool $seed = false;

                    public function test_ok(): void {}
                }

                final class WrappedTransactionsTest extends \Migration\Tests\TestCase
                {
                    use DatabaseTransactions;

                    public function test_ok(): void {}
                }

                final class LegacyAdopterTest extends \Migration\Tests\LegacyCase
                {
                    use RefreshDatabase;

                    public function test_ok(): void {}
                }
                PHP);

            $paths = \var_export($tmpDir . '/corpus', true);
            $set = \var_export(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, true);
            \file_put_contents($tmpDir . '/rector.php', <<<PHP
                <?php

                declare(strict_types=1);

                use Laratesto\\Rector\\Rules\\LaravelBaseClassRector;
                use Rector\\Config\\RectorConfig;

                return RectorConfig::configure()
                    ->withPaths([{$paths}])
                    ->withSets([{$set}])
                    ->withConfiguredRule(LaravelBaseClassRector::class, [
                        LaravelBaseClassRector::BASE_CLASSES => [
                            'Migration\\\\Tests\\\\TestCase',
                            'Migration\\\\Tests\\\\FeatureCase',
                            'Migration\\\\Tests\\\\ApiTestCase',
                            'Migration\\\\Tests\\\\LegacyCase',
                            'Illuminate\\\\Foundation\\\\Testing\\\\TestCase',
                        ],
                    ]);
                PHP);

            $this->runRector($rootDir, $tmpDir);

            $base = (string) \file_get_contents($tmpDir . '/corpus/Base.php');
            $tests = (string) \file_get_contents($tmpDir . '/corpus/Tests.php');

            $between = static function (string $haystack, string $from, string $to): string {
                $start = \strpos($haystack, $from);
                $end = \strpos($haystack, $to);

                return \substr($haystack, (int) $start, $end === false ? null : $end - $start);
            };

            // The cross-file project bases convert exactly once each: the plain
            // base keeps the defaults, the seeded root carries its literal option,
            // and the option property is lifted into the attribute.
            Assert::string($base)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase]\n"
                . 'abstract class TestCase extends \Laratesto\Testing\LaravelTestCase',
            );
            Assert::string($base)->contains(
                "#[\Laratesto\Attribute\RefreshDatabase(seed: true)]\n"
                . 'abstract class ApiTestCase extends \Laratesto\Testing\LaravelTestCase',
            );
            Assert::string($base)->notContains('protected bool $seed');

            // The intermediate base duplicates the plain base: it merges into the
            // root attribute and carries no strategy of its own. The window ends
            // before the next attribute line, so nothing from the neighbour leaks
            // into this assertion.
            $featureCase = $between($base, 'abstract class FeatureCase', '#[\Laratesto\Attribute\RefreshDatabase(seed: true)]');
            Assert::string($featureCase)->notContains('#[\Laratesto\Attribute');
            Assert::string($featureCase)->notContains('use RefreshDatabase;');

            // The already-migrated ancestor is a static fact: it stays untouched.
            $legacyCase = $between($base, 'abstract class LegacyCase', "\0");
            Assert::string($legacyCase)->contains('extends \Laratesto\Testing\LaravelTestCase');

            // Every duplicate below a converted ancestor merges: trait use gone,
            // no own attribute — the single base attribute covers them all. Each
            // window ends at the next class so later blocks never leak in.
            $plainDuplicate = $between($tests, 'final class PlainDuplicateTest', 'final class DeepDuplicateTest');
            Assert::string($plainDuplicate)->notContains('use RefreshDatabase;');
            Assert::string($plainDuplicate)->notContains('#[\Laratesto\Attribute');

            $deepDuplicate = $between($tests, 'final class DeepDuplicateTest', 'final class SameSeedTest');
            Assert::string($deepDuplicate)->notContains('use RefreshDatabase;');
            Assert::string($deepDuplicate)->notContains('#[\Laratesto\Attribute');

            $inheritSeed = $between($tests, 'final class InheritSeedTest', 'final class OtherSeedTest');
            Assert::string($inheritSeed)->notContains('use RefreshDatabase;');
            Assert::string($inheritSeed)->notContains('#[\Laratesto\Attribute');

            $legacyAdopter = $between($tests, 'final class LegacyAdopterTest', "\0");
            Assert::string($legacyAdopter)->notContains('use RefreshDatabase;');
            Assert::string($legacyAdopter)->notContains('#[\Laratesto\Attribute');

            // The identical literal seed survives the merge through the base
            // attribute: the duplicate declaration is lifted away with the trait.
            $sameSeed = $between($tests, 'final class SameSeedTest', 'final class InheritSeedTest');
            Assert::string($sameSeed)->notContains('use RefreshDatabase;');
            Assert::string($sameSeed)->notContains('#[\Laratesto\Attribute');
            Assert::string($sameSeed)->notContains('protected bool $seed');

            // A diverging configuration cannot be merged exactly: fail closed with
            // the actionable residual, keeping trait and property.
            $otherSeed = $between($tests, '/* laratesto-residual', 'final class WrappedTransactionsTest');
            Assert::string($otherSeed)->contains('laratesto-residual(code=DATABASE_UNSUPPORTED_CONFIGURATION');
            Assert::string($otherSeed)->contains('cannot be merged exactly (seed=false here, seed=true on the ancestor)');
            Assert::string($otherSeed)->contains('use RefreshDatabase;');
            Assert::string($otherSeed)->contains('protected bool $seed = false;');

            // Different database traits legitimately stack: the descendant keeps
            // its OWN attribute for the other strategy while inheriting the base
            // refresh — one instance of each, never two of the same.
            $wrapped = $between($tests, '#[\Laratesto\Attribute\DatabaseTransactions]', 'final class LegacyAdopterTest');
            Assert::string($wrapped)->contains('#[\Laratesto\Attribute\DatabaseTransactions]');
            Assert::string($wrapped)->notContains('use DatabaseTransactions;');

            // Exactly-once evidence, read through the very reflection helper the
            // Testo runtime uses (parents included, MERGE_ALL): every merged
            // hierarchy exposes exactly ONE RefreshDatabase attribute instance, the
            // seeded configuration is the inherited one, and the stacking case has
            // one instance per strategy.
            require_once $tmpDir . '/corpus/Base.php';
            require_once $tmpDir . '/corpus/Tests.php';

            Assert::same(1, self::countAttributes(\Migration\Tests\Feature\PlainDuplicateTest::class, RefreshDatabase::class));
            Assert::same(1, self::countAttributes(\Migration\Tests\Feature\DeepDuplicateTest::class, RefreshDatabase::class));
            Assert::same(1, self::countAttributes(\Migration\Tests\Feature\LegacyAdopterTest::class, RefreshDatabase::class));
            Assert::same(1, self::countAttributes(\Migration\Tests\Feature\InheritSeedTest::class, RefreshDatabase::class));

            $inheritedSeed = self::attributesOf(\Migration\Tests\Feature\SameSeedTest::class, RefreshDatabase::class);
            Assert::same(1, \count($inheritedSeed));
            Assert::true($inheritedSeed[0]->newInstance()->seed);

            $wrappedAttributes = self::attributesOf(
                \Migration\Tests\Feature\WrappedTransactionsTest::class,
                Interceptable::class,
            );
            Assert::same(1, self::countIn($wrappedAttributes, RefreshDatabase::class));
            Assert::same(1, self::countIn($wrappedAttributes, DatabaseTransactions::class));

            // Idempotency: the second run over its own output changes nothing.
            $hashes = self::hashCorpus($tmpDir . '/corpus');
            $this->runRector($rootDir, $tmpDir);
            Assert::same($hashes, self::hashCorpus($tmpDir . '/corpus'), 'A second apply must be byte-for-byte idempotent.');
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    /**
     * @return list<\ReflectionAttribute>
     */
    private static function attributesOf(string $class, string $attributeClass): array
    {
        // The exact lookup the Testo runtime performs (AttributesInterceptor):
        // parents included, instance-of matching, MERGE_ALL across the hierarchy.
        return Reflection::fetchClassAttributes(
            $class,
            attributeClass: $attributeClass,
            flags: \ReflectionAttribute::IS_INSTANCEOF,
        );
    }

    private static function countAttributes(string $class, string $attributeClass): int
    {
        return self::countIn(self::attributesOf($class, $attributeClass), $attributeClass);
    }

    /**
     * @param list<\ReflectionAttribute> $attributes
     */
    private static function countIn(array $attributes, string $attributeClass): int
    {
        return \count(\array_filter(
            $attributes,
            static fn (\ReflectionAttribute $attribute): bool => $attribute->getName() === $attributeClass,
        ));
    }

    /**
     * @return array<string, string>
     */
    private static function hashCorpus(string $directory): array
    {
        $hashes = [];

        foreach (\glob($directory . '/*.php') ?: [] as $file) {
            $hashes[(string) $file] = \md5((string) \file_get_contents((string) $file));
        }

        return $hashes;
    }

    private function runRector(string $rootDir, string $tmpDir): void
    {
        $rectorBin = $rootDir . '/vendor/rector/rector/bin/rector';
        Assert::true(\is_file($rectorBin), 'rector binary not found at ' . $rectorBin);

        $process = \proc_open(
            [\PHP_BINARY, $rectorBin, 'process', '--config', $tmpDir . '/rector.php', '--no-progress-bar', '--no-ansi'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $rootDir,
        );
        Assert::true(\is_resource($process), 'proc_open failed for the rector run');
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        $exitCode = \proc_close($process);

        Assert::same(0, $exitCode, "rector failed.\nSTDOUT:\n{$stdout}\nSTDERR:\n{$stderr}");
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
