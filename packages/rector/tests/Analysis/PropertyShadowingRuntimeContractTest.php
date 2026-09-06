<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Analysis;

use Symfony\Component\Process\Process;
use Testo\Assert;
use Testo\Test;

/**
 * Runtime-parity contract for the PHP property-resolution semantics the database
 * preflight's ancestor scan is allowed to assume (the shadowing relaxation of
 * DatabaseConfigurationAnalyzer::ancestorPropertyConflict), the property-scan
 * sibling of HelperMatrixRuntimeContractTest.
 *
 * The Laravel database traits read every option through the exact idiom
 * `property_exists($this, 'option') ? $this->option : default`, evaluated on the
 * concrete test object. The preflight skips an ancestor option declaration when
 * the converted class itself declares the same name — lossless only while real
 * PHP keeps answering all of the following:
 *
 * 1. the most-derived declaration supplies the value the trait machinery reads,
 *    at every chain depth (so an ancestor's same-named declaration is inert once
 *    the class redeclares the option, and the lifted literal is what ran);
 * 2. without the redeclare, the ancestor's value IS the value read (so the
 *    un-shadowed residual stays fail-closed);
 * 3. an ancestor-private option never passes `property_exists()` from the
 *    consumer's scope (visibility-aware gate), so skipping it drops nothing;
 * 4. an ancestor-static option never resolves through `$this` (the read yields
 *    null, never the static value), so skipping it drops nothing;
 * 5. a class redeclare of a used trait's option coexists only when compatible,
 *    and then the class declaration wins the read (incompatible compositions are
 *    compile-time fatals the hierarchy rule owns, not provable values).
 *
 * Each probe is a self-contained PHP script executed by the real runtime — the
 * assertions pin PHP behavior, not this package's code, so a PHP semantics drift
 * that would invalidate the preflight's model fails here first.
 */
final class PropertyShadowingRuntimeContractTest
{
    #[Test]
    public function theMostDerivedDeclarationSuppliesTheValueTheTraitReads(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

trait Reader
{
    public function readSeed(): string
    {
        return property_exists($this, 'seed') ? $this->seed : 'none';
    }
}

class Ancestor
{
    protected string $seed = 'ancestor';
}

class Mid extends Ancestor
{
    protected string $seed = 'mid';
}

class Leaf extends Mid
{
    use Reader;

    protected string $seed = 'leaf';
}

class PlainChild extends Ancestor
{
    use Reader;
}

echo 'shadowed=', (new Leaf())->readSeed(), "\n";
echo 'inherited=', (new PlainChild())->readSeed(), "\n";
PHP);

        // The class's own redeclare wins over every inherited declaration ('mid'
        // and 'ancestor' are both dead), while without the redeclare the
        // ancestor's value is exactly what the trait machinery reads.
        Assert::same("shadowed=leaf\ninherited=ancestor", $output);
    }

    #[Test]
    public function anAncestorPrivateOptionNeverPassesThePropertyExistsGate(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

trait Reader
{
    public function readSeed(): bool
    {
        return property_exists($this, 'seed') ? $this->seed : false;
    }
}

class Ancestor
{
    private bool $seed = true;
}

class Child extends Ancestor
{
    use Reader;
}

$child = new Child();

echo 'gate=', var_export(property_exists($child, 'seed'), true), "\n";
echo 'idiom=', var_export($child->readSeed(), true), "\n";
PHP);

        // The visibility-aware gate answers false from the consumer's scope, so
        // the Laravel idiom falls back to its default: the ancestor-private value
        // never carried behavior and the scan may skip it.
        Assert::same("gate=false\nidiom=false", $output);
    }

    #[Test]
    public function anOwnPrivateSlotAnswersItsOwnScopeReaderAcrossTheLift(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

class OwnerBefore
{
    private bool $seed = true;

    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

final class ChildBefore extends OwnerBefore
{
    protected bool $seed = false;
}

class OwnerAfter
{
    private bool $seed = true;

    public function observedSeed(): bool
    {
        return $this->seed;
    }
}

final class ChildAfter extends OwnerAfter
{
}

echo 'before=', var_export((new ChildBefore())->observedSeed(), true), "\n";
echo 'after=', var_export((new ChildAfter())->observedSeed(), true), "\n";
PHP);

        // The owner's private slot wins in the owner's scope: the child's
        // protected redeclare never shadows it for a reader declared in the
        // same class, so lifting the child declaration cannot repoint this
        // reader — the shape the preflight exempts as provably inert.
        Assert::same("before=true\nafter=true", $output);
    }

    #[Test]
    public function anIntermediatePrivateSlotShieldsNoOtherScopeReader(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

class ReaderBefore
{
    public function observedSeed(): mixed
    {
        return $this->seed ?? 'missing';
    }
}

abstract class MiddleBefore extends ReaderBefore
{
    private bool $seed = true;
}

final class LeafBefore extends MiddleBefore
{
    protected bool $seed = false;
}

class ReaderAfter
{
    public function observedSeed(): mixed
    {
        return $this->seed ?? 'missing';
    }
}

abstract class MiddleAfter extends ReaderAfter
{
    private bool $seed = true;
}

final class LeafAfter extends MiddleAfter
{
}

echo 'before=', var_export((new LeafBefore())->observedSeed(), true), "\n";
echo 'after=', var_export((new LeafAfter())->observedSeed(), true), "\n";
PHP);

        // The reader's scope is the grandparent: the intermediate private slot
        // is invisible there and the lookup continues above it, so removing
        // the leaf declaration silently repoints the read from the leaf value
        // to nothing — the shape the preflight must fail closed on.
        Assert::same("before=false\nafter='missing'", $output);
    }

    #[Test]
    public function anAncestorStaticOptionNeverResolvesThroughThis(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

trait Reader
{
    public function readStat()
    {
        return property_exists($this, 'stat') ? $this->stat : 'GATE_FALSE';
    }
}

class Ancestor
{
    protected static bool $stat = true;
}

class Child extends Ancestor
{
    use Reader;
}

echo 'gate=', var_export(property_exists(new Child(), 'stat'), true), "\n";
echo 'read=', var_export((new Child())->readStat(), true), "\n";
PHP);

        // property_exists() sees the static slot, but `$this->stat` warns and
        // yields null instead of the static value: a static option never reached
        // the trait machinery through instance reads and the scan may skip it.
        Assert::same("gate=true\nread=NULL", $output);
    }

    #[Test]
    public function aClassRedeclareOfATraitOptionMustBeCompatibleAndThenWins(): void
    {
        $output = $this->runProbe(<<<'PHP'
<?php

declare(strict_types=1);

trait Reader
{
    public function readSeed(): string
    {
        return property_exists($this, 'seed') ? $this->seed : 'none';
    }
}

trait ProjectOptions
{
    protected string $seed = 'shared';
}

class Consumer
{
    use Reader;

    use ProjectOptions;

    protected string $seed = 'shared';
}

echo 'gate=', var_export(property_exists(new Consumer(), 'seed'), true), "\n";
echo 'read=', (new Consumer())->readSeed(), "\n";
PHP);

        // A compatible class declaration (same visibility, same default — the
        // engine refuses anything else at composition time) composes with the
        // trait's and its declaration serves the read. Values cannot diverge by
        // rule, so the runtime form of the trait scan's own-name skip is exact:
        // the class redeclare is identity, never a lost value.
        Assert::same("gate=true\nread=shared", $output);
    }

    #[Test]
    public function anIncompatibleTraitOptionRedeclareIsACompileTimeFatal(): void
    {
        [, $output] = $this->runScript(<<<'PHP'
<?php

declare(strict_types=1);

trait ProjectOptions
{
    protected string $seed = 'trait';
}

class Consumer
{
    use ProjectOptions;

    protected string $seed = 'class';
}
PHP);

        // A class declaration that differs from the trait's is refused at
        // composition time: such a hierarchy never runs, so the preflight owns no
        // value for it and the hierarchy rule owns the diagnosis. The exit code
        // is platform-dependent (a Windows PHP fatal can exit 0 through
        // Process), so the composition-fatal text itself is the pinned contract.
        Assert::string($output)->contains('define the same property');
    }

    /**
     * Runs one probe script with the real runtime and returns its exact stdout.
     */
    private function runProbe(string $script): string
    {
        [$exitCode, $output] = $this->runScript($script);

        Assert::same(0, $exitCode, "probe failed.\nOUTPUT:\n{$output}");

        return $output;
    }

    /**
     * @return array{int, string} Process exit code and combined output.
     */
    private function runScript(string $script): array
    {
        $tmpDir = sys_get_temp_dir() . '/laratesto-shadow-probe-' . getmypid();
        Assert::true(mkdir($tmpDir, 0777, true) || is_dir($tmpDir));

        try {
            // The script runs with the error spellings PHP uses on stdout: a
            // compile-time fatal must reach the combined output as text, while a
            // runtime failure would mean the probe itself is broken.
            $path = $tmpDir . '/probe.php';
            Assert::true(file_put_contents($path, $script) !== false);

            $process = new Process([PHP_BINARY, $path], $tmpDir, timeout: 30.0);
            $process->run();

            $output = $process->getOutput() . "\n" . $process->getErrorOutput();

            return [$process->getExitCode() ?? 1, rtrim($output)];
        } finally {
            self::recursiveRemove($tmpDir);
        }
    }

    private static function recursiveRemove(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
