<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use Rector\NodeNameResolver\NodeNameResolver;

/** @internal Pure whole-class preflight for Laravel database strategy conversion. */
final class DatabaseConfigurationAnalyzer
{
    public const TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase' => 'Laratesto\Attribute\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTransactions' => 'Laratesto\Attribute\DatabaseTransactions',
        'Illuminate\Foundation\Testing\DatabaseMigrations' => 'Laratesto\Attribute\DatabaseMigrations',
        'Illuminate\Foundation\Testing\DatabaseTruncation' => 'Laratesto\Attribute\DatabaseTruncation',
    ];

    public const SEED_ATTRIBUTE = 'Illuminate\Foundation\Testing\Attributes\Seed';

    public const SEEDER_ATTRIBUTE = 'Illuminate\Foundation\Testing\Attributes\Seeder';

    private const PROPERTY_OPTIONS = [
        'Illuminate\Foundation\Testing\RefreshDatabase' => [
            'seed' => 'seed',
            'seeder' => 'seeder',
            'dropViews' => 'dropViews',
            'dropTypes' => 'dropTypes',
            'connectionsToTransact' => 'connections',
        ],
        'Illuminate\Foundation\Testing\DatabaseTransactions' => [
            'connectionsToTransact' => 'connections',
        ],
        'Illuminate\Foundation\Testing\DatabaseMigrations' => [
            'seed' => 'seed',
            'seeder' => 'seeder',
            'dropViews' => 'dropViews',
            'dropTypes' => 'dropTypes',
        ],
        'Illuminate\Foundation\Testing\DatabaseTruncation' => [
            'seed' => 'seed',
            'seeder' => 'seeder',
            'dropViews' => 'dropViews',
            'dropTypes' => 'dropTypes',
            'connectionsToTruncate' => 'connections',
            'tablesToTruncate' => 'tables',
            'exceptTables' => 'exceptTables',
        ],
    ];

    private const UNSUPPORTED_OVERRIDES = [
        'beforeRefreshingDatabase',
        'afterRefreshingDatabase',
        'beforeTruncatingDatabase',
        'afterTruncatingDatabase',
        'connectionsToTransact',
        'connectionsToTruncate',
        'tablesToTruncate',
        'exceptTables',
        'migrateFreshUsing',
        'shouldSeed',
        'seeder',
        'shouldDropViews',
        'shouldDropTypes',
        'beginDatabaseTransaction',
        'runDatabaseMigrations',
        'refreshDatabase',
        'refreshInMemoryDatabase',
        'refreshTestDatabase',
        'truncateDatabaseTables',
        'truncateTablesForAllConnections',
        'truncateTablesForConnection',
        'getAllTablesForConnection',
    ];

    private NodeFinder $nodeFinder;

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
    )
    {
        $this->nodeFinder = new NodeFinder();
    }

    public function analyze(Class_ $class): DatabaseConfigurationAnalysis
    {
        $uses = [];
        $nestedUses = [];

        // Same recursion depth as the HTTP analyzer: a `use` inside a nested
        // class-like must not slip through unconverted and unmarked.
        foreach ($this->nodeFinder->findInstanceOf($class->stmts, TraitUse::class) as $statement) {
            $direct = in_array($statement, $class->stmts, true);

            foreach ($statement->traits as $trait) {
                $name = $this->resolvedName($trait);
                if ($name !== null && isset(self::TRAITS[$name])) {
                    if ($direct) {
                        $uses[] = [$name, $statement];
                    } else {
                        $nestedUses[] = $name;
                    }
                }
            }
        }

        if ($uses === [] && $nestedUses === []) {
            return new DatabaseConfigurationAnalysis();
        }

        if ($nestedUses !== []) {
            return new DatabaseConfigurationAnalysis(
                sourceTrait: $nestedUses[0],
                targetAttribute: self::TRAITS[$nestedUses[0]],
                unsupportedReason: 'database trait use inside a nested class is not converted automatically',
            );
        }

        if (count($uses) !== 1) {
            return new DatabaseConfigurationAnalysis(
                sourceTrait: $uses[0][0],
                targetAttribute: self::TRAITS[$uses[0][0]],
                traitUse: $uses[0][1],
                unsupportedReason: 'multiple Laravel database traits are not converted automatically',
            );
        }

        [$sourceTrait, $traitUse] = $uses[0];
        $base = new DatabaseConfigurationAnalysis(
            sourceTrait: $sourceTrait,
            targetAttribute: self::TRAITS[$sourceTrait],
            traitUse: $traitUse,
        );

        if ($traitUse->adaptations !== []) {
            return $this->unsupported($base, 'database trait adaptations are not converted automatically');
        }

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();
            if (in_array($name, self::UNSUPPORTED_OVERRIDES, true)) {
                return $this->unsupported($base, sprintf('database override %s() requires manual migration', $name));
            }
        }

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->resolvedName($attribute->name) === self::TRAITS[$sourceTrait]) {
                    return $this->unsupported($base, 'source database trait and target attribute are both present');
                }
            }
        }

        $options = [];
        $properties = [];

        foreach (self::PROPERTY_OPTIONS[$sourceTrait] as $propertyName => $argumentName) {
            $matches = array_values(array_filter(
                $class->getProperties(),
                static function (Property $property) use ($propertyName): bool {
                    foreach ($property->props as $item) {
                        if ($item->name->toString() === $propertyName) {
                            return true;
                        }
                    }

                    return false;
                },
            ));

            if ($matches === []) {
                continue;
            }

            if (count($matches) !== 1) {
                return $this->unsupported($base, sprintf('database option $%s is declared more than once', $propertyName));
            }

            $property = $matches[0];
            $propertyItem = null;

            foreach ($property->props as $item) {
                if ($item->name->toString() === $propertyName) {
                    $propertyItem = $item;

                    break;
                }
            }

            $value = $propertyItem?->default;

            if ($property->isStatic() || $propertyItem === null || ! $this->isLiteral($value)) {
                return $this->unsupported($base, sprintf('database option $%s must be a non-static literal', $propertyName));
            }

            if (! $value instanceof Expr || ! $this->optionMatchesShape($argumentName, $value)) {
                return $this->unsupported($base, sprintf('database option $%s has an unsupported literal shape', $propertyName));
            }

            if ($this->propertyIsReadByClass($class, $propertyName)) {
                return $this->unsupported($base, sprintf('database option $%s is also used by class code', $propertyName));
            }

            $options[$argumentName] = $value;
            $properties[] = $propertyItem;
        }

        $attributes = [];
        if ($sourceTrait !== 'Illuminate\Foundation\Testing\DatabaseTransactions') {
            $seedAttributes = $this->attributesNamed($class, self::SEED_ATTRIBUTE);
            $seederAttributes = $this->attributesNamed($class, self::SEEDER_ATTRIBUTE);

            if (count($seedAttributes) > 1 || count($seederAttributes) > 1) {
                return $this->unsupported($base, 'duplicate Laravel Seed/Seeder attributes require manual migration');
            }

            if ($seedAttributes !== []) {
                if ($seedAttributes[0]->args !== []) {
                    return $this->unsupported($base, 'Laravel Seed attribute arguments are not supported');
                }

                $options['seed'] = new Expr\ConstFetch(new Name('true'));
                $attributes[] = $seedAttributes[0];
            }

            if ($seederAttributes !== []) {
                $attribute = $seederAttributes[0];
                if (count($attribute->args) !== 1
                    || $attribute->args[0]->unpack
                    || ($attribute->args[0]->name !== null && $attribute->args[0]->name->toString() !== 'class')
                    || ! $this->isSeederLiteral($attribute->args[0]->value)) {
                    return $this->unsupported($base, 'Laravel Seeder attribute must contain one literal class');
                }

                $options['seeder'] = $attribute->args[0]->value;
                $attributes[] = $attribute;
            }
        }

        return new DatabaseConfigurationAnalysis(
            sourceTrait: $sourceTrait,
            targetAttribute: self::TRAITS[$sourceTrait],
            traitUse: $traitUse,
            options: $options,
            removableProperties: $properties,
            removableAttributes: $attributes,
        );
    }

    public function resolvedName(Name $name): ?string
    {
        $resolved = $this->nodeNameResolver->getName($name);

        return $resolved === null ? null : ltrim($resolved, '\\');
    }

    private function unsupported(DatabaseConfigurationAnalysis $analysis, string $reason): DatabaseConfigurationAnalysis
    {
        return new DatabaseConfigurationAnalysis(
            sourceTrait: $analysis->sourceTrait,
            targetAttribute: $analysis->targetAttribute,
            traitUse: $analysis->traitUse,
            unsupportedReason: $reason,
        );
    }

    private function isLiteral(?Expr $expression): bool
    {
        if ($expression instanceof Scalar || $expression instanceof Expr\ConstFetch || $expression instanceof Expr\ClassConstFetch) {
            return true;
        }

        if (! $expression instanceof Expr\Array_) {
            return false;
        }

        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack || ! $this->isLiteral($item->value)) {
                return false;
            }

            if ($item->key !== null && ! $this->isLiteral($item->key)) {
                return false;
            }
        }

        return true;
    }

    private function isSeederLiteral(Expr $expression): bool
    {
        return $expression instanceof Scalar\String_
            || ($expression instanceof Expr\ClassConstFetch
                && $this->nodeNameResolver->isName($expression->name, 'class'));
    }

    private function optionMatchesShape(string $argument, Expr $expression): bool
    {
        return match ($argument) {
            'seed', 'dropViews', 'dropTypes' => $this->isBooleanLiteral($expression),
            'seeder' => $this->isSeederLiteral($expression),
            'connections' => $this->isStringList($expression, allowNull: true, requireNonEmpty: true),
            'tables', 'exceptTables' => $this->isTableSelection($expression),
            default => false,
        };
    }

    private function isBooleanLiteral(Expr $expression): bool
    {
        return $expression instanceof Expr\ConstFetch
            && in_array(strtolower($expression->name->toString()), ['true', 'false'], true);
    }

    private function isStringList(Expr $expression, bool $allowNull = false, bool $requireNonEmpty = false): bool
    {
        if (! $expression instanceof Expr\Array_
            || ($requireNonEmpty && $expression->items === [])) {
            return false;
        }

        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack || $item->key !== null) {
                return false;
            }

            if ($item->value instanceof Scalar\String_ && $item->value->value !== '') {
                continue;
            }

            if ($allowNull
                && $item->value instanceof Expr\ConstFetch
                && strtolower($item->value->name->toString()) === 'null') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function isTableSelection(Expr $expression): bool
    {
        if (! $expression instanceof Expr\Array_) {
            return false;
        }

        $isList = true;
        $isMap = true;

        foreach ($expression->items as $item) {
            if ($item === null || $item->unpack) {
                return false;
            }

            $isList = $isList
                && $item->key === null
                && $item->value instanceof Scalar\String_
                && $item->value->value !== '';

            $isMap = $isMap
                && $item->key instanceof Scalar\String_
                && $item->key->value !== ''
                && $this->isStringList($item->value);
        }

        return $isList || $isMap;
    }

    private function propertyIsReadByClass(Class_ $class, string $propertyName): bool
    {
        return $this->nodeFinder->findFirst(
            $class->stmts,
            static function (Node $node) use ($propertyName): bool {
                if (! $node instanceof Expr\PropertyFetch
                    && ! $node instanceof Expr\NullsafePropertyFetch) {
                    return false;
                }

                if (! $node->var instanceof Expr\Variable || $node->var->name !== 'this') {
                    return false;
                }

                if ($node->name instanceof Node\Identifier) {
                    return $node->name->toString() === $propertyName;
                }

                if ($node->name instanceof Scalar\String_) {
                    return $node->name->value === $propertyName;
                }

                // Dynamic property name ($this->{$opt}): the option may be read,
                // so fail closed instead of removing a live declaration.
                return true;
            },
        ) instanceof Node;
    }

    /** @return list<Attribute> */
    private function attributesNamed(Class_ $class, string $name): array
    {
        $attributes = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->resolvedName($attribute->name) === $name) {
                    $attributes[] = $attribute;
                }
            }
        }

        return $attributes;
    }
}
