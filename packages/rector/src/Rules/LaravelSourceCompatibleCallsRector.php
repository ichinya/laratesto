<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Residuals\ResidualCode;
use Laratesto\Rector\Residuals\ResidualMarker;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/** Classifies HTTP/TestResponse signatures and rewrites response types only after a green preflight. */
#[TestRectorFixtures('LaravelSourceCompatibleCallsRector')]
final class LaravelSourceCompatibleCallsRector extends AbstractRector
{
    /** @var array<string, true> */
    private array $pendingTestResponseImportRemovals = [];

    public function __construct(
        private readonly HttpCompatibilityAnalyzer $analyzer,
    ) {}

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Classify Laravel HTTP/TestResponse signatures and convert only fully compatible response types',
            [
                new CodeSample(
                    '$response = $this->postJson(\'/api/users\', [\'name\' => \'A\']);',
                    '$response = $this->postJson(\'/api/users\', [\'name\' => \'A\']);',
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
            return $this->removeQueuedTestResponseImport($node);
        }

        if (! $this->isLaravelTestClass($node)) {
            return null;
        }

        $analysis = $this->analyzer->analyze($node);
        $changed = false;

        foreach ($analysis->reasonsByCode as $code => $reasons) {
            $changed = ResidualMarker::mark(
                $node,
                $code,
                static::class,
                implode('; ', $reasons),
            ) || $changed;
        }

        if (! $analysis->safe()
            || ! $this->fileResponsePreflightSafe()
            || $this->hasStructuralBlockingMarker($node)
            || ! $analysis->hasTestResponseType) {
            return $changed ? $node : null;
        }

        $this->traverseNodesWithCallable($node->stmts, function (Node $inner): ?Node {
            if ($inner instanceof Name && $this->isName($inner, HttpCompatibilityAnalyzer::TEST_RESPONSE)) {
                return new FullyQualified(HttpCompatibilityAnalyzer::LARAVEL_RESPONSE);
            }

            return null;
        });
        $this->removeTestResponseImportWhenUnused();

        return $node;
    }

    private function fileResponsePreflightSafe(): bool
    {
        foreach ($this->topLevelClasses() as $class) {
            if ($this->isLaravelTestClass($class) && ! $this->analyzer->analyze($class)->safe()) {
                return false;
            }
        }

        return true;
    }

    private function removeTestResponseImportWhenUnused(): void
    {
        $statements = $this->getFile()->getNewStmts();
        $nodeFinder = new NodeFinder();

        /** @var list<Class_> $classes */
        $classes = $nodeFinder->findInstanceOf($statements, Class_::class);
        foreach ($classes as $class) {
            /** @var list<Name> $names */
            $names = $nodeFinder->findInstanceOf($class, Name::class);
            foreach ($names as $name) {
                if ($this->isName($name, HttpCompatibilityAnalyzer::TEST_RESPONSE)) {
                    return;
                }
            }
        }

        $this->pendingTestResponseImportRemovals[$this->getFile()->getFilePath()] = true;
    }

    private function removeQueuedTestResponseImport(FileNode $fileNode): ?FileNode
    {
        $filePath = $this->getFile()->getFilePath();
        if (! isset($this->pendingTestResponseImportRemovals[$filePath])) {
            return null;
        }

        unset($this->pendingTestResponseImportRemovals[$filePath]);

        return $fileNode->removeImports([HttpCompatibilityAnalyzer::TEST_RESPONSE]) ? $fileNode : null;
    }

    private function isLaravelTestClass(Class_ $class): bool
    {
        return $class->extends !== null && $this->isNames($class->extends, [
            'Laratesto\Testing\LaravelTestCase',
            'LaravelTestCase',
            'Tests\TestCase',
            'Illuminate\Foundation\Testing\TestCase',
        ]);
    }

    private function hasStructuralBlockingMarker(Class_ $class): bool
    {
        foreach ([
            ResidualCode::CLASS_UNSAFE_HIERARCHY,
            ResidualCode::LIFECYCLE_UNSUPPORTED,
            ResidualCode::DATABASE_UNSUPPORTED_CONFIGURATION,
        ] as $code) {
            if (ResidualMarker::isMarked($class, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Class_> */
    private function topLevelClasses(): array
    {
        $classes = [];

        foreach ($this->getFile()->getOldStmts() as $statement) {
            $statements = $statement instanceof \Rector\PhpParser\Node\FileNode ? $statement->stmts : [$statement];

            foreach ($statements as $inner) {
                foreach ($inner instanceof Stmt\Namespace_ ? $inner->stmts : [$inner] as $candidate) {
                    if ($candidate instanceof Class_) {
                        $classes[] = $candidate;
                    }
                }
            }
        }

        return $classes;
    }
}
