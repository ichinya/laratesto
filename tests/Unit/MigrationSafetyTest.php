<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Migration\PhpUnitToTestoMigrator;
use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

final class MigrationSafetyTest
{
    private const BASE = '<?php namespace Tests; use Illuminate\Foundation\Testing\TestCase as BaseTestCase; abstract class TestCase extends BaseTestCase {}';

    #[Test]
    public function refusesConflictingAliasesIncludingCaseAndGroupedImports(): void
    {
        foreach (['use Some\Page as Assert;', 'use Some\Page as aSsErT;', 'use Some\{Page as Assert};'] as $import) {
            $source = "<?php\nnamespace Tests\\Unit;\nuse PHPUnit\\Framework\\TestCase;\n{$import}\nclass CollisionTest extends TestCase { public function testValue() { self::assertTrue(true); } }";
            $result = (new PhpUnitToTestoMigrator())->migrate($source);
            Assert::false($result->successful());
            Assert::string(\implode(' ', $result->errors))->contains('import');
        }
        $source = "<?php\nnamespace Tests\\Unit;\nuse PHPUnit\\Framework\\TestCase;\nclass Assert extends TestCase { public function testValue() { self::assertTrue(true); } }";
        $result = (new PhpUnitToTestoMigrator())->migrate($source);
        Assert::false($result->successful());
        Assert::string(\implode(' ', $result->errors))->contains('declared class');
    }

    #[Test]
    public function commentedAndIndentedTraitsProduceLoadableClasses(): void
    {
        foreach (["    ", "\t", "        "] as $index => $indent) {
            $source = "<?php\nnamespace Tests\\Feature;\nuse Tests\\TestCase;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nclass Commented{$index}Test extends TestCase {\n{$indent}use RefreshDatabase; // keep this comment\npublic function testValue() { self::assertTrue(true); }\n}";
            $result = (new PhpUnitToTestoMigrator())->migrate($source, self::BASE);
            Assert::true($result->successful(), \implode('; ', $result->errors));
            Assert::string($result->code)->contains('// keep this comment');
            $file = \tempnam(\sys_get_temp_dir(), 'laratesto-load-');
            try {
                \file_put_contents($file, $result->code);
                $process = new Process([\PHP_BINARY, '-r', 'require $argv[1]; require $argv[2];', \dirname(__DIR__, 2) . '/vendor/autoload.php', $file]);
                $exit = $process->run();
                Assert::same(0, $exit, $process->getErrorOutput());
            } finally {
                \unlink($file);
            }
        }
    }

    #[Test]
    public function refusesUnknownAndCustomBootstrap(): void
    {
        $source = "<?php\nnamespace Tests\\Feature;\nuse Tests\\TestCase;\nclass BootstrapTest extends TestCase { public function testValue() { self::assertTrue(true); } }";
        foreach ([null, \str_replace('{}', '{ public function createApplication() { return app(); } }', self::BASE), \str_replace('{}', '{ protected function setUp(): void {} }', self::BASE)] as $base) {
            $result = (new PhpUnitToTestoMigrator())->migrate($source, $base);
            Assert::false($result->successful());
            Assert::same($source, $result->code);
            Assert::string(\implode(' ', $result->errors))->contains('bootstrap');
        }
    }

    #[Test]
    public function refusesMissingHelpersAndResponseMacros(): void
    {
        foreach (['$this->withoutDeprecationHandling();', '$this->seed();', '$this->get("/")->assertDownload();', '$this->get("/")->assertStreamedContent("body");'] as $call) {
            $source = "<?php\nnamespace Tests\\Feature;\nuse Tests\\TestCase;\nclass HelperTest extends TestCase { public function testValue() { {$call} } }";
            $result = (new PhpUnitToTestoMigrator())->migrate($source, self::BASE);
            Assert::false($result->successful());
            Assert::string(\implode(' ', $result->errors))->contains('Unsupported');
        }
    }
}
