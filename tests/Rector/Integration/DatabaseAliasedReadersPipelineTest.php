<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\DatabasePipeline;
use Testo\Assert;
use Testo\Test;

final class DatabaseAliasedReadersPipelineTest
{
    #[Test]
    public function aliasReadersKeepTheirOptionsAndUnrelatedObjectsStillConvert(): void
    {
        $files = [];
        foreach ([
            'Simple' => '$self = $this; return $self->seed;',
            'Chained' => '$a = $b = $this; return $a->seed;',
            'Transitive' => '$a = $this; $b = $a; $c = $b; return $c->seed;',
            'Reference' => '$a = $this; $b =& $a; return $b->seed;',
            'ReverseReference' => '$a = null; $b =& $a; $b = $this; return $a->seed;',
            'Nullsafe' => '$self = $this; return $self?->seed;',
            'Dynamic' => '$self = $this; $name = "seed"; return $self->{$name};',
            'LiteralName' => '$self = $this; return $self->{"seed"};',
            'Expression' => 'return ($self = $this)->seed;',
            'Closure' => '$self = $this; return (fn () => $self->seed)();',
            'Conditional' => '$self = mt_rand(0, 1) ? $this : new OptionDto(); return $self->seed;',
            'Coalesce' => '$other = null; $self = $other ?? $this; return $self->seed;',
        ] as $name => $read) {
            $files[$name . '.php'] = '<?php namespace Tests\\AliasOptions; final class ' . $name . 'Test extends \\Illuminate\\Foundation\\Testing\\TestCase'
                . ' { use \\Illuminate\\Foundation\\Testing\\RefreshDatabase; protected bool $seed = true;'
                . ' public function readOption(): bool { ' . $read . ' } public function testWorks(): void {} }';
        }
        $files['Control.php'] = <<<'PHP'
            <?php
            namespace Tests\AliasOptions;
            final class OptionDto { public bool $seed = false; }
            final class ControlTest extends \Illuminate\Foundation\Testing\TestCase
            {
                use \Illuminate\Foundation\Testing\RefreshDatabase;
                protected bool $seed = true;
                private string $label = 'control';
                public function readDto(): bool { $self = new OptionDto(); return $self->seed; }
                public function readLabel(): string { $self = $this; return $self->label; }
                public function testWorks(): void {}
            }
            PHP;
        $snapshot = DatabasePipeline::run($files);
        foreach ($snapshot as $name => $source) {
            if ($name === 'Control.php') {
                Assert::string($source)->contains('RefreshDatabase(seed: true)');
                Assert::string($source)->notContains('laratesto-residual');
                Assert::string($source)->contains('$self->seed');
            } else {
                Assert::string($source)->contains('code=DATABASE_UNSUPPORTED_CONFIGURATION');
                Assert::string($source)->contains('protected bool $seed = true;');
                Assert::string($source)->contains('use \\Illuminate\\Foundation\\Testing\\RefreshDatabase;');
                Assert::string($source)->notContains('#[\\Laratesto\\Attribute\\RefreshDatabase');
            }
        }
    }

    #[Test]
    public function ancestorAndComposedTraitAliasesParticipateInReaderSafety(): void
    {
        $snapshot = DatabasePipeline::run([
            'Bases.php' => <<<'PHP'
                <?php
                namespace Tests\AliasHierarchy;
                abstract class ReaderBase extends \Illuminate\Foundation\Testing\TestCase
                {
                    protected bool $seed = true;
                    public function readOption(): bool { $a = $b = $this; return $a?->seed; }
                }
                trait Reader
                {
                    public function readOption(): bool { $self = $this; $ref =& $self; return $ref->seed; }
                }
                trait NestedReader { use Reader; }
                abstract class TraitBase extends \Illuminate\Foundation\Testing\TestCase { use NestedReader; }
                abstract class PrivateBase extends \Illuminate\Foundation\Testing\TestCase
                {
                    public function __construct(private bool $seed = true) {}
                    public function readOption(): bool { $self = $this; return $self->seed; }
                }
                PHP,
            'Tests.php' => <<<'PHP'
                <?php
                namespace Tests\AliasHierarchy;
                use Illuminate\Foundation\Testing\RefreshDatabase;
                final class AncestorTest extends ReaderBase
                {
                    use RefreshDatabase;
                    protected bool $seed = false;
                    public function testWorks(): void {}
                }
                final class OwnTraitTest extends \Illuminate\Foundation\Testing\TestCase
                {
                    use RefreshDatabase, NestedReader;
                    protected bool $seed = false;
                    public function testWorks(): void {}
                }
                final class AncestorTraitTest extends TraitBase
                {
                    use RefreshDatabase;
                    protected bool $seed = false;
                    public function testWorks(): void {}
                }
                final class PrivateControlTest extends PrivateBase
                {
                    use RefreshDatabase;
                    protected bool $seed = false;
                    public function testWorks(): void {}
                }
                PHP,
        ], ['Tests\AliasHierarchy\ReaderBase', 'Tests\AliasHierarchy\TraitBase', 'Tests\AliasHierarchy\PrivateBase']);
        Assert::same(3, substr_count($snapshot['Tests.php'], 'code=DATABASE_UNSUPPORTED_CONFIGURATION'));
        Assert::same(3, substr_count($snapshot['Tests.php'], 'protected bool $seed = false;'));
        Assert::string($snapshot['Tests.php'])->contains('project code in Tests\AliasHierarchy\ReaderBase');
        Assert::string($snapshot['Tests.php'])->contains('project code in Tests\AliasHierarchy\Reader');
        Assert::same(1, substr_count($snapshot['Tests.php'], '#[\Laratesto\Attribute\RefreshDatabase(seed: false)]'));
    }
}
