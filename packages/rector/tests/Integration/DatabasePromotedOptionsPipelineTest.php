<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Integration;

use Laratesto\Rector\Tests\Support\DatabasePipeline;
use Testo\Assert;
use Testo\Test;

final class DatabasePromotedOptionsPipelineTest
{
    #[Test]
    public function promotedOptionsStayInSourceAcrossClassesAndTraits(): void
    {
        $snapshot = DatabasePipeline::run([
            'Bases.php' => <<<'PHP'
                <?php
                namespace Tests\Promoted;
                abstract class PromotedBase extends \Illuminate\Foundation\Testing\TestCase
                {
                    public function __construct(protected bool $seed = true) {}
                }
                trait PromotedOptions
                {
                    public function __construct(private bool $seed = true) {}
                }
                trait NestedOptions { use PromotedOptions; }
                trait AncestorOptions
                {
                    public function __construct(protected bool $dropViews = true) {}
                }
                abstract class TraitBase extends \Illuminate\Foundation\Testing\TestCase { use AncestorOptions; }
                PHP,
            'Tests.php' => <<<'PHP'
                <?php
                namespace Tests\Promoted;
                use Illuminate\Foundation\Testing\RefreshDatabase;
                final class OwnTest extends \Illuminate\Foundation\Testing\TestCase
                {
                    use RefreshDatabase;
                    public function __construct(protected bool $seed = true) {}
                    public function testWorks(): void {}
                }
                final class AncestorTest extends PromotedBase
                {
                    use RefreshDatabase;
                    public function testWorks(): void {}
                }
                final class ShadowTest extends PromotedBase
                {
                    use RefreshDatabase;
                    protected bool $seed = false;
                    public function testWorks(): void {}
                }
                final class TraitTest extends \Illuminate\Foundation\Testing\TestCase
                {
                    use RefreshDatabase, NestedOptions;
                    public function testWorks(): void {}
                }
                final class AncestorTraitTest extends TraitBase
                {
                    use RefreshDatabase;
                    public function testWorks(): void {}
                }
                final class PlainTest extends \Illuminate\Foundation\Testing\TestCase
                {
                    use RefreshDatabase;
                    public function __construct(protected string $label = 'control') {}
                    public function testWorks(): void {}
                }
                PHP,
        ], ['Tests\Promoted\PromotedBase', 'Tests\Promoted\TraitBase']);

        $source = $snapshot['Tests.php'];
        Assert::same(5, substr_count($source, 'code=DATABASE_UNSUPPORTED_CONFIGURATION'));
        Assert::same(5, substr_count($source, 'promoted constructor property'));
        Assert::string($source)->contains('protected bool $seed = false;');
        Assert::string($source)->contains('public function __construct(protected bool $seed = true)');
        Assert::string($source)->contains('use RefreshDatabase, NestedOptions;');
        Assert::string($source)->contains('#[\Laratesto\Attribute\RefreshDatabase]');
        Assert::string($source)->contains("public function __construct(protected string \$label = 'control')");
        Assert::string($snapshot['Bases.php'])->contains('public function __construct(protected bool $seed = true)');
        Assert::string($snapshot['Bases.php'])->contains('public function __construct(private bool $seed = true)');
    }
}
