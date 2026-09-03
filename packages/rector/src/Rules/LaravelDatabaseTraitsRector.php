<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\DatabaseConfigurationAnalysis;
use Laratesto\Rector\Analysis\DatabaseConfigurationAnalyzer;
use Laratesto\Rector\Configuration\ConfiguredHierarchy;
use Laratesto\Rector\Residuals\ResidualCode;
use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/** Atomically converts one statically supported Laravel database trait. */
#[TestRectorFixtures('LaravelDatabaseTraitsRector')]
final class LaravelDatabaseTraitsRector extends AbstractRector
{
    /** @var array<string, list<non-empty-string>> */
    private array $pendingImportRemovals = [];

    public function __construct(
        private readonly DatabaseConfigurationAnalyzer $analyzer,
        private readonly ConfiguredHierarchy $hierarchy,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Atomically convert supported Laravel database traits and literal configuration to Laratesto attributes',
            [
                new CodeSample(
                    <<<'PHP'
                        final class UsersTest extends \Illuminate\Foundation\Testing\TestCase
                        {
                            use \Illuminate\Foundation\Testing\RefreshDatabase;
                        }
                        PHP,
                    <<<'PHP'
                        #[\Laratesto\Attribute\RefreshDatabase]
                        final class UsersTest extends \Illuminate\Foundation\Testing\TestCase
                        {
                        }
                        PHP,
                ),
            ],
        );
    }

    #[\Override]
    public function getNodeTypes(): array
    {
        return [FileNode::class, Class_::class];
    }

    /** @param FileNode|Class_ $node */
    #[\Override]
    public function refactor(Node $node): null|Node|int
    {
        if ($node instanceof FileNode) {
            return $this->removeQueuedImports($node);
        }

        if (! $this->hierarchy->recognizesTestClass($node) || $this->hasBlockingMarker($node)) {
            return null;
        }

        $analysis = $this->analyzer->analyze($node);

        if ($analysis->unsupportedReason !== null) {
            $changed = ResidualMarker::mark(
                $node,
                ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
                static::class,
                $analysis->unsupportedReason,
            );

            return $changed ? $node : null;
        }

        if (! $analysis->supported()) {
            return null;
        }

        $this->applyConversion($node, $analysis);
        $symbols = [(string) $analysis->sourceTrait];
        foreach ($analysis->removableAttributes as $attribute) {
            $name = $this->analyzer->resolvedName($attribute->name);
            $name !== null and $symbols[] = $name;
        }
        $this->queueImportsWithoutRemainingUses(array_values(array_unique($symbols)));

        return $node;
    }

    private function applyConversion(Class_ $class, DatabaseConfigurationAnalysis $analysis): void
    {
        $arguments = [];
        foreach (['seed', 'seeder', 'dropViews', 'dropTypes', 'connections', 'tables', 'exceptTables'] as $name) {
            if (isset($analysis->options[$name])) {
                $arguments[] = new Arg($analysis->options[$name], name: new Identifier($name));
            }
        }

        $class->attrGroups[] = new AttributeGroup([
            new Attribute(new FullyQualified((string) $analysis->targetAttribute), $arguments),
        ]);

        foreach ($class->stmts as $key => $statement) {
            if ($statement === $analysis->traitUse) {
                /** @var TraitUse $statement */
                $statement->traits = array_values(array_filter(
                    $statement->traits,
                    fn(Node\Name $trait): bool => $this->analyzer->resolvedName($trait) !== $analysis->sourceTrait,
                ));

                if ($statement->traits === []) {
                    unset($class->stmts[$key]);
                }
            }

            // Lossless property rewrite: only the converted option items are lifted
            // into the attribute; sibling declarations, type, flags, attributes and
            // comments of the statement survive.
            if ($statement instanceof Property) {
                $statement->props = array_values(array_filter(
                    $statement->props,
                    static fn(PropertyProperty $item): bool => ! in_array($item, $analysis->removableProperties, true),
                ));

                if ($statement->props === []) {
                    unset($class->stmts[$key]);
                }
            }
        }
        $class->stmts = array_values($class->stmts);

        foreach ($class->attrGroups as $groupKey => $group) {
            $group->attrs = array_values(array_filter(
                $group->attrs,
                static fn(Attribute $attribute): bool => ! in_array($attribute, $analysis->removableAttributes, true),
            ));

            if ($group->attrs === []) {
                unset($class->attrGroups[$groupKey]);
            }
        }
        $class->attrGroups = array_values($class->attrGroups);
    }

    private function hasBlockingMarker(Class_ $class): bool
    {
        foreach ([
            ResidualCode::CLASS_UNSAFE_HIERARCHY,
            ResidualCode::LIFECYCLE_UNSUPPORTED,
            ResidualCode::HTTP_UNSUPPORTED_SIGNATURE,
            ResidualCode::RESPONSE_UNSUPPORTED_API,
            ResidualCode::ARTISAN_INTERACTION_UNSUPPORTED,
        ] as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Queues each symbol's import for removal unless a remaining class-like still resolves
     * the bare name. Traits, enums and interfaces share the file's import table, so a
     * same-file `trait ReusableSetup { use RefreshDatabase; }` keeps the import alive even
     * after the converted class drops its trait use.
     *
     * @param list<non-empty-string> $symbols
     */
    private function queueImportsWithoutRemainingUses(array $symbols): void
    {
        $statements = $this->getFile()->getNewStmts();
        $nodeFinder = new NodeFinder();

        /** @var list<ClassLike> $classLikes */
        $classLikes = $nodeFinder->findInstanceOf($statements, ClassLike::class);

        foreach ($symbols as $key => $symbol) {
            foreach ($classLikes as $classLike) {
                /** @var list<Node\Name> $names */
                $names = $nodeFinder->findInstanceOf($classLike, Node\Name::class);
                foreach ($names as $name) {
                    if ($this->analyzer->resolvedName($name) === $symbol) {
                        unset($symbols[$key]);
                        continue 2;
                    }
                }
            }
        }

        if ($symbols === []) {
            return;
        }

        $filePath = $this->getFile()->getFilePath();
        $this->pendingImportRemovals[$filePath] = array_values(array_unique([
            ...($this->pendingImportRemovals[$filePath] ?? []),
            ...$symbols,
        ]));
    }

    private function removeQueuedImports(FileNode $fileNode): ?FileNode
    {
        $filePath = $this->getFile()->getFilePath();
        $symbols = $this->pendingImportRemovals[$filePath] ?? [];
        unset($this->pendingImportRemovals[$filePath]);

        return $symbols !== [] && $fileNode->removeImports($symbols) ? $fileNode : null;
    }
}
