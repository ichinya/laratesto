<?php

declare(strict_types=1);

namespace Laratesto\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitor;
use PhpParser\Comment;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Testo\Bridge\Rector\Testing\TestRectorFixtures;

/**
 * Converts Laravel database traits to Laratesto class attributes.
 *
 * ```php
 * use Illuminate\Foundation\Testing\RefreshDatabase;
 *
 * final class UsersTest extends Tests\TestCase
 * {
 *     use RefreshDatabase;
 * }
 * ```
 * becomes
 * ```php
 * use Laratesto\Testing\LaravelTestCase;
 *
 * #[\Laratesto\Attribute\RefreshDatabase]
 * final class UsersTest extends LaravelTestCase
 * {
 * }
 * ```
 *
 * Trait options configured as class properties (`$seed`, `$seeder`, `$connection`,
 * `$tablesToTruncate`, `$dropViews`, `$dropTypes` — literal values only) migrate into
 * attribute arguments; the property is dropped when nothing else references it.
 *
 * Custom trait hooks (`beforeRefreshingDatabase`, `afterRefreshingDatabase`,
 * `beforeTruncatingDatabase`, `afterTruncatingDatabase`) have no automatic counterpart:
 * the trait is kept in place and the class gets a greppable residual marker — the
 * scanner (ticket 04) surfaces it in the report; the migration is manual.
 */
#[TestRectorFixtures('LaravelDatabaseTraitsRector')]
final class LaravelDatabaseTraitsRector extends AbstractRector
{
    /**
     * Laravel PHPUnit database trait => Laratesto attribute.
     */
    private const array TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase' => 'Laratesto\Attribute\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTransactions' => 'Laratesto\Attribute\DatabaseTransactions',
        'Illuminate\Foundation\Testing\DatabaseMigrations' => 'Laratesto\Attribute\DatabaseMigrations',
        'Illuminate\Foundation\Testing\DatabaseTruncation' => 'Laratesto\Attribute\DatabaseTruncation',
    ];

    /**
     * Property configuring a trait => attribute argument it becomes
     * (`tablesToTruncate` is the PHPUnit trait's name for the table list).
     */
    private const array PROPERTY_OPTIONS = [
        'seed' => 'seed',
        'seeder' => 'seeder',
        'connection' => 'connection',
        'tablesToTruncate' => 'tables',
        'dropViews' => 'dropViews',
        'dropTypes' => 'dropTypes',
    ];

    /**
     * Customization hooks that block automatic conversion of their trait.
     */
    private const array HOOK_METHODS = [
        'beforeRefreshingDatabase',
        'afterRefreshingDatabase',
        'beforeTruncatingDatabase',
        'afterTruncatingDatabase',
    ];

    private const string TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convert Laravel database traits (RefreshDatabase, DatabaseTransactions, DatabaseMigrations, DatabaseTruncation) to Laratesto attributes',
            [
                new CodeSample(
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\RefreshDatabase;
                        use Illuminate\Foundation\Testing\TestCase;

                        final class UsersTest extends TestCase
                        {
                            use RefreshDatabase;
                        }
                        PHP,
                    <<<'PHP'
                        use Illuminate\Foundation\Testing\TestCase;

                        #[\Laratesto\Attribute\RefreshDatabase]
                        final class UsersTest extends TestCase
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
        return [Class_::class, Use_::class];
    }

    /**
     * @param Class_|Use_ $node
     */
    #[\Override]
    public function refactor(Node $node): null|Node|int
    {
        if ($node instanceof Use_) {
            return $this->refactorUse($node);
        }

        if (! $this->isLaravelTestClass($node)) {
            return null;
        }

        return $this->convertTraits($node);
    }

    private function isLaravelTestClass(Class_ $node): bool
    {
        if ($node->extends === null) {
            return false;
        }

        if ($this->isName($node->extends, self::TARGET_BASE)) {
            return true;
        }

        return $this->isNames($node->extends, [
            'Tests\TestCase',
            'Illuminate\Foundation\Testing\TestCase',
        ]);
    }

    private function refactorUse(Use_ $node): null|Node|int
    {
        $uses = [];

        foreach ($node->uses as $use) {
            if ($this->isNames($use->name, array_keys(self::TRAITS))
                && $this->fileConvertsTrait($use->name)) {
                continue;
            }

            $uses[] = $use;
        }

        if ($uses === []) {
            return NodeVisitor::REMOVE_NODE;
        }

        if (\count($uses) === \count($node->uses)) {
            return null;
        }

        $node->uses = $uses;

        return $node;
    }

    /**
     * Whether the file holds a Laravel test class whose use of this trait this run
     * converts (a plain PHPUnit class, or hooks blocking conversion, keeps the import —
     * removing it there would break the leftover `use TraitName;` in the class body).
     */
    private function fileConvertsTrait(Name $trait): bool
    {
        if ($this->fileHasHookMethods()) {
            return false;
        }

        foreach ($this->topLevelStmts() as $stmt) {
            if (! $stmt instanceof Class_ || ! $this->isLaravelTestClass($stmt)) {
                continue;
            }

            foreach ($stmt->stmts as $classStmt) {
                if ($classStmt instanceof TraitUse) {
                    foreach ($classStmt->traits as $candidate) {
                        if ($this->isName($candidate, $this->getName($trait) ?? '')) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function convertTraits(Class_ $node): ?Node
    {
        $changed = false;

        foreach ($node->stmts as $key => $stmt) {
            if (! $stmt instanceof TraitUse) {
                continue;
            }

            foreach ($stmt->traits as $trait) {
                $attribute = $this->traitTarget($trait);

                if ($attribute === null) {
                    continue;
                }

                if ($this->fileHasHookMethods()) {
                    // Manual migration required — leave the trait, mark the class.
                    $this->addResidualMarker($node, $attribute);

                    continue;
                }

                $this->addAttribute($node, $attribute, $this->collectOptions($node, $trait));
                $this->removeTraitUse($node, $key, $stmt);
                $changed = true;
            }
        }

        return $changed ? $node : null;
    }

    private function traitTarget(Name $trait): ?string
    {
        foreach (self::TRAITS as $from => $to) {
            if ($this->isName($trait, $from)) {
                return $to;
            }
        }

        return null;
    }

    /**
     * Literal trait-option properties of the class, keyed by attribute argument name.
     *
     * @return array<non-empty-string, Arg>
     */
    private function collectOptions(Class_ $class, Name $trait): array
    {
        $options = [];

        foreach ($class->getProperties() as $property) {
            $name = $property->props[0]->name?->toString() ?? null;

            if ($name === null || ! isset(self::PROPERTY_OPTIONS[$name])) {
                continue;
            }

            // No default value (or a non-literal one) — leave the option alone.
            $default = $property->props[0]->default;

            if (! $this->isLiteralValue($default)) {
                continue;
            }

            if ($this->propertyIsReferenced($class, $name)) {
                continue;
            }

            $options[self::PROPERTY_OPTIONS[$name]] = new Arg($default, name: new Identifier(self::PROPERTY_OPTIONS[$name]));
            $this->removeProperty($class, $property);
        }

        return $options;
    }

    private function isLiteralValue(?Node\Expr $expr): bool
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Node\Expr\ConstFetch) {
            return true;
        }

        if ($expr instanceof Node\Expr\Array_) {
            foreach ($expr->items as $item) {
                if ($item === null || ! $this->isLiteralValue($item->value)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function propertyIsReferenced(Class_ $class, string $name): bool
    {
        $referenced = false;

        $this->traverseNodesWithCallable($class->stmts, function (Node $node) use ($name, &$referenced): void {
            if ($node instanceof Expr\PropertyFetch
                && $node->var instanceof Expr\Variable
                && $node->var->name === 'this'
                && $node->name instanceof Identifier
                && $node->name->toString() === $name) {
                $referenced = true;
            }
        });

        return $referenced;
    }

    private function removeProperty(Class_ $class, Property $property): void
    {
        foreach ($class->stmts as $key => $stmt) {
            if ($stmt === $property) {
                unset($class->stmts[$key]);
                $class->stmts = array_values($class->stmts);

                return;
            }
        }
    }

    private function removeTraitUse(Class_ $class, int $key, TraitUse $use): void
    {
        unset($class->stmts[$key]);
        $class->stmts = array_values($class->stmts);
    }

    /**
     * @param array<non-empty-string, Arg> $options
     */
    private function addAttribute(Class_ $class, string $attribute, array $options): void
    {
        // Idempotency: an existing attribute of the same name wins.
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($this->isName($attr->name, $attribute)) {
                    return;
                }
            }
        }

        $class->attrGroups[] = new AttributeGroup([
            new Attribute(new FullyQualified($attribute), array_values($options)),
        ]);
    }

    /**
     * Attaches the canonical residual marker (see ticket 04): replace-or-skip, never
     * duplicated.
     */
    private function addResidualMarker(Class_ $class, string $attribute): void
    {
        $marker = \sprintf(
            'laratesto-residual(rule=%s): %s has custom hook methods — migrate manually',
            static::class,
            (new FullyQualified($attribute))->getLast(),
        );

        foreach ($class->getComments() as $comment) {
            if (\str_contains($comment->getText(), 'laratesto-residual')) {
                return;
            }
        }

        $comments = $class->getAttribute(AttributeKey::COMMENTS) ?? [];
        $comments[] = new Comment('/* ' . $marker . ' */');
        $class->setAttribute(AttributeKey::COMMENTS, $comments);
    }

    private function fileHasHookMethods(): bool
    {
        foreach ($this->topLevelStmts() as $stmt) {
            if (! $stmt instanceof Stmt\ClassLike) {
                continue;
            }

            foreach ($stmt->getMethods() as $method) {
                $name = $this->getName($method->name);

                if ($name !== null && \in_array($name, self::HOOK_METHODS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Class-like statements of the file, unwrapped from the Rector FileNode and
     * namespace blocks.
     *
     * @return list<Stmt>
     */
    private function topLevelStmts(): array
    {
        $result = [];

        foreach ($this->getFile()->getOldStmts() as $stmt) {
            $stmts = $stmt instanceof FileNode ? $stmt->stmts : [$stmt];

            foreach ($stmts as $inner) {
                $result[] = $inner instanceof Stmt\Namespace_ ? $inner->stmts : [$inner];
            }
        }

        return array_merge(...$result);
    }
}
