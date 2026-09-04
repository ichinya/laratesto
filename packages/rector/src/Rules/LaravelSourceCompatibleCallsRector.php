<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use Laratesto\Rector\Analysis\HttpCompatibilityAnalyzer;
use Laratesto\Rector\Configuration\ConfiguredHierarchy;
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
        private readonly ConfiguredHierarchy $hierarchy,
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

        if (! $this->hierarchy->recognizesTestClass($node)) {
            return null;
        }

        $analysis = $this->analyzer->analyze($node, $this->topLevelClasses());
        $changed = false;

        foreach ($analysis->reasonsByCode as $code => $reasons) {
            $changed = ResidualMarker::mark(
                $node,
                $code,
                static::class,
                implode('; ', $reasons),
            ) || $changed;
        }

        $preflightBlockers = $this->responsePreflightBlockers();

        if (! $analysis->safe()
            || $preflightBlockers !== []
            || $this->hasStructuralBlockingMarker($node)
            || ! $analysis->hasTestResponseType) {
            // Fail closed: the swap is file-wide, so an unsafe sibling blocks it
            // for an otherwise fully convertible class. The pipeline still
            // migrates that class onto the Laratesto base, whose helpers return
            // Laratesto\Testing\LaravelResponse — not a TestResponse subtype — so
            // the surviving TestResponse declarations would TypeError at runtime.
            // Leave an actionable residual naming the blocker instead of silently
            // shipping a guaranteed crash; a class kept un-migrated by its own
            // structural marker stays on the Laravel runtime and needs no swap.
            if ($analysis->safe()
                && $analysis->hasTestResponseType
                && ! $this->hasStructuralBlockingMarker($node)
                && $preflightBlockers !== []) {
                $changed = ResidualMarker::mark(
                    $node,
                    ResidualCode::RESPONSE_UNSUPPORTED_API,
                    static::class,
                    $this->fileGateBlockedReason($preflightBlockers),
                ) || $changed;
            }

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

    /**
     * Recognized classes in this file whose own analysis is unsafe. The response
     * type swap is file-wide, so any one of them blocks the swap for the safe
     * siblings too — refactor() marks those siblings with an actionable residual
     * instead of swapping.
     *
     * @return list<non-empty-string> blocker descriptions in file order
     */
    private function responsePreflightBlockers(): array
    {
        $blockers = [];

        foreach ($this->topLevelClasses() as $class) {
            if (! $this->hierarchy->recognizesTestClass($class) || $this->analyzer->analyze($class)->safe()) {
                continue;
            }

            $name = $this->getName($class);
            $blockers[] = $name === null ? 'an anonymous sibling class' : sprintf('sibling class %s', $name);
        }

        return $blockers;
    }

    /**
     * @param list<non-empty-string> $blockers
     */
    private function fileGateBlockedReason(array $blockers): string
    {
        return sprintf(
            'the file-wide TestResponse to Laratesto\Testing\LaravelResponse swap was blocked by %s;'
            . ' migrate TestResponse types to Laratesto\Testing\LaravelResponse manually',
            implode(', ', $blockers),
        );
    }

    private function removeTestResponseImportWhenUnused(): void
    {
        if ($this->containsTestResponseName($this->getFile()->getNewStmts())) {
            return;
        }

        $this->pendingTestResponseImportRemovals[$this->getFile()->getFilePath()] = true;
    }

    /**
     * The swap rewrites only the recognized class, so any surviving TestResponse
     * reference elsewhere in the file — plain functions, traits, interfaces,
     * enums — still resolves through the import. Removing it would leave those
     * bare names fatally resolving to `<current-namespace>\TestResponse`.
     *
     * @param list<Node> $nodes
     */
    private function containsTestResponseName(array $nodes): bool
    {
        $nodeFinder = new NodeFinder();

        foreach ($nodes as $node) {
            if ($node instanceof FileNode) {
                if ($this->containsTestResponseName($node->stmts)) {
                    return true;
                }

                continue;
            }

            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                continue;
            }

            if ($node instanceof Stmt\Namespace_) {
                if ($this->containsTestResponseName($node->stmts)) {
                    return true;
                }

                continue;
            }

            /** @var list<Name> $names */
            $names = $nodeFinder->findInstanceOf($node, Name::class);
            foreach ($names as $name) {
                if ($this->isName($name, HttpCompatibilityAnalyzer::TEST_RESPONSE)) {
                    return true;
                }
            }
        }

        return false;
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
