<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Trait_;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\PhpParser\AstResolver;

/** @internal Whole-hierarchy preflight for Laravel database strategy conversion. */
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

    /**
     * Hooks the three migration-command strategies share through
     * CanConfigureMigrationCommands (RefreshDatabase, DatabaseMigrations and
     * DatabaseTruncation each `use` it): the "migrate:fresh" argument builder
     * and the property_exists()-gated option readers it consults.
     */
    private const MIGRATION_COMMAND_HOOKS = [
        'migrateFreshUsing',
        'shouldDropViews',
        'shouldDropTypes',
        'shouldSeed',
        'seeder',
    ];

    /**
     * The strategies whose migrate:fresh runs against the DEFAULT connection
     * no matter what the source connection selection contains: the selection
     * never picked the migration (or seeding) target, so only a provably
     * default-only selection can lift into the target's `connections`
     * argument.
     */
    private const DEFAULT_MIGRATION_TRAITS = [
        'Illuminate\Foundation\Testing\RefreshDatabase',
        'Illuminate\Foundation\Testing\DatabaseTruncation',
    ];

    /**
     * Methods of each database trait's composition whose class-level override
     * carries live behavior today but nothing after conversion: the Testo
     * interceptor replaces the trait machinery and never calls back into it.
     * Derived from the framework sources (`^12.0 || ^13.0`, verified at the
     * v12.0.0 and v13.0.0 tags, the 12.x/13.x heads and the installed
     * 12.69.1): the trait's own methods plus everything it inherits from the
     * shared concern. The sets are the union across those versions —
     * `updateLocalCacheOfInMemoryDatabases` only exists from later 12.x on —
     * so every supported version is covered. A method outside the active
     * trait's set belongs to a different strategy, never executed for this
     * class, and must not block the conversion.
     */
    private const UNSUPPORTED_OVERRIDES = [
        'Illuminate\Foundation\Testing\RefreshDatabase' => [
            ...self::MIGRATION_COMMAND_HOOKS,
            'refreshDatabase',
            'usingInMemoryDatabases',
            'usingInMemoryDatabase',
            'restoreInMemoryDatabase',
            'refreshTestDatabase',
            'updateLocalCacheOfInMemoryDatabases',
            'migrateDatabases',
            'beginDatabaseTransaction',
            'connectionsToTransact',
            'beforeRefreshingDatabase',
            'afterRefreshingDatabase',
        ],
        'Illuminate\Foundation\Testing\DatabaseMigrations' => [
            ...self::MIGRATION_COMMAND_HOOKS,
            'runDatabaseMigrations',
            'refreshTestDatabase',
            'beforeRefreshingDatabase',
            'afterRefreshingDatabase',
        ],
        'Illuminate\Foundation\Testing\DatabaseTransactions' => [
            'beginDatabaseTransaction',
            'connectionsToTransact',
        ],
        'Illuminate\Foundation\Testing\DatabaseTruncation' => [
            ...self::MIGRATION_COMMAND_HOOKS,
            'truncateDatabaseTables',
            'truncateTablesForAllConnections',
            'truncateTablesForConnection',
            'getAllTablesForConnection',
            'tableExistsIn',
            'connectionsToTruncate',
            'tablesToTruncate',
            'exceptTables',
            'beforeTruncatingDatabase',
            'afterTruncatingDatabase',
        ],
    ];

    /**
     * The walk stops at the framework base and the already-migrated target base:
     * below them no project code can declare database machinery.
     */
    private const FRAMEWORK_BASE = 'Illuminate\Foundation\Testing\TestCase';

    private const TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    /**
     * Guard against cyclic or pathologically deep extends chains, mirroring
     * LaravelBaseClassRector::MAX_CHAIN_DEPTH: an over-deep walk is not provable
     * and therefore not flagged.
     */
    private const MAX_CHAIN_DEPTH = 10;

    private NodeFinder $nodeFinder;

    private Standard $prettyPrinter;
    /** @var array<non-empty-string, list<lowercase-string&non-empty-string>> */
    private array $unsupportedOverrides;

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly AstResolver $astResolver,
    )
    {
        $this->nodeFinder = new NodeFinder();
        $this->prettyPrinter = new Standard();
        $this->unsupportedOverrides = array_map(
            static fn (array $hooks): array => array_map('strtolower', $hooks),
            self::UNSUPPORTED_OVERRIDES,
        );
    }

    /**
     * @param list<ClassLike> $localClasses The class-like nodes declared in the
     *        file under analysis (old statements), used to resolve the extends
     *        chain and used traits without autoload before AstResolver is
     *        consulted.
     */
    public function analyze(Class_ $class, array $localClasses = []): DatabaseConfigurationAnalysis
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
            $hidden = $this->hiddenComposedStrategyReason($class, $localClasses);

            if ($hidden !== null) {
                return new DatabaseConfigurationAnalysis(
                    sourceTrait: $hidden['trait'],
                    targetAttribute: self::TRAITS[$hidden['trait']],
                    unsupportedReason: $hidden['reason'],
                );
            }

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

        $unsupportedOverrides = $this->unsupportedOverrides[$sourceTrait];

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();

            // PHP resolves method names case-insensitively, so a case-variant
            // declaration is still the same override of the trait machinery.
            if (in_array(strtolower($name), $unsupportedOverrides, true)) {
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

            // Lifting the declaration repoints every project reader above the
            // class: fail closed unless every live reader is provably inert.
            $readerReason = $this->inheritedReaderConflictReason($class, $localClasses, $propertyName);
            if ($readerReason !== null) {
                return $this->unsupported($base, $readerReason);
            }

            // Both traits' migrate:fresh always targets the DEFAULT connection,
            // while the attribute's connections argument repoints it: fail
            // closed unless the selection is provably default-only.
            if (in_array($sourceTrait, self::DEFAULT_MIGRATION_TRAITS, true) && $argumentName === 'connections') {
                $scopeReason = $this->connectionsMigrationScopeConflictReason(
                    $value,
                    $this->connectionsSelectionResidualReason($sourceTrait),
                );
                if ($scopeReason !== null) {
                    return $this->unsupported($base, $scopeReason);
                }

                // A provably default-only selection is exactly what the
                // attribute's absent argument expresses: the declaration lifts
                // into nothing beyond the property removal.
                $properties[] = $propertyItem;

                continue;
            }

            // DatabaseTruncation's table/exclusion maps are keyed by connection
            // name in the attribute, but the trait looks them up with the
            // source selector — for the remaining convertible default-only
            // scope that is the null key. The lookup misses every literal
            // name, so the trait falls back to the whole map, whose array
            // values match no table, while the attribute resolves the selector
            // to the connection name first and selects the listed tables:
            // fail closed instead of silently mapping null to a runtime name.
            if ($sourceTrait === 'Illuminate\Foundation\Testing\DatabaseTruncation'
                && in_array($argumentName, ['tables', 'exceptTables'], true)
                && $this->isConnectionKeyedMap($value)
            ) {
                return $this->unsupported($base, $this->tableMapResidualReason($argumentName));
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

            $inherited = $this->inheritedSeedAttributes($class, $localClasses);

            if ($inherited['conflict'] !== null) {
                return $this->unsupported($base, $inherited['conflict']);
            }

            if ($seedAttributes !== []) {
                if ($seedAttributes[0]->args !== []) {
                    return $this->unsupported($base, 'Laravel Seed attribute arguments are not supported');
                }

                $options['seed'] = new Expr\ConstFetch(new Name('true'));
                $attributes[] = $seedAttributes[0];
            } elseif ($inherited['seed']) {
                // Laravel 13's shouldSeed() walks every ancestor for the Seed
                // attribute and answers true before the property fallback: the
                // inherited attribute seeds, and it also shadows the class's
                // own literal $seed declaration.
                $options['seed'] = new Expr\ConstFetch(new Name('true'));
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
            } elseif ($inherited['seeder'] !== null) {
                // Laravel 13's seeder() walks nearest-first and answers before
                // the property fallback: the inherited attribute shadows the
                // class's own literal $seeder as well.
                $options['seeder'] = $inherited['seeder'];
            }
        }

        // Traits flatten their full composition tree into every consuming class,
        // so a project trait can supply live option properties that the class
        // scan above never sees (PHP-Parser does not flatten trait properties)
        // — and, through an insteadof adaptation on its own use statement, it
        // can supply a live hook override while the source trait's use stays
        // adaptation-free.
        $seenTraits = [];
        $directTraitReason = $this->traitCompositionConflictReason(
            $this->directTraitNames($class),
            true,
            $class,
            $localClasses,
            self::PROPERTY_OPTIONS[$sourceTrait],
            $seenTraits,
            $this->unsupportedOverrides[$sourceTrait],
        );

        if ($directTraitReason !== null) {
            return $this->unsupported($base, $directTraitReason);
        }

        // A direct use does not make hidden duplicates harmless: the composed
        // trait's framework use flattens into the class too, so the conversion
        // would add the attribute on top of machinery that survives it.
        $hiddenDuplicate = $this->hiddenComposedStrategyReason($class, $localClasses);

        if ($hiddenDuplicate !== null) {
            return $this->unsupported($base, $hiddenDuplicate['reason']);
        }

        $mergeIntoAncestor = false;

        $ancestorReason = $this->ancestorConflictReason(
            $class,
            $localClasses,
            $sourceTrait,
            $seenTraits,
            $options,
            $mergeIntoAncestor,
        );

        if ($ancestorReason !== null) {
            return $this->unsupported($base, $ancestorReason);
        }

        return new DatabaseConfigurationAnalysis(
            sourceTrait: $sourceTrait,
            targetAttribute: self::TRAITS[$sourceTrait],
            traitUse: $traitUse,
            options: $options,
            removableProperties: $properties,
            removableAttributes: $attributes,
            mergeIntoAncestor: $mergeIntoAncestor,
        );
    }

    public function resolvedName(Name $name): ?string
    {
        $resolved = $this->nodeNameResolver->getName($name);

        return $resolved === null ? null : ltrim($resolved, '\\');
    }

    /**
     * Why converting this class's database trait would silently break against the
     * resolved project ancestors, or null when the conversion is hierarchy-safe.
     *
     * Two ancestor shapes matter:
     *
     * 1. An ancestor declaring an option property. The Laravel traits define no
     *    option properties; they read them through `property_exists($this, ...)`,
     *    which sees inherited public and protected declarations — so an ancestor's
     *    option is live for the trait-carrying class today and its value would
     *    vanish into the class-level attribute the conversion adds. (Trait machinery
     *    METHODS declared on an ancestor are not scanned: a trait import in the
     *    child overrides same-named inherited methods, so such an override never
     *    executed and nothing live is lost.)
     *
     * 2. An ancestor already carrying the same database strategy — an explicit
     *    trait use of the same trait, or the migrated target attribute. Laravel
     *    deduplicated that duplication to one behavior (`class_uses_recursive`
     *    collapses the trait to a single entry), but a second class-level
     *    attribute here would make the Testo reflection (which merges the whole
     *    hierarchy) run the interceptor twice. The conversion therefore merges
     *    instead: the trait use converts into NO attribute and the class inherits
     *    the ancestor's single one. The merge is only provably lossless when the
     *    effective option configuration below the topmost duplicate equals the
     *    configuration that ancestor carries — otherwise the conversion fails
     *    closed with DATABASE_UNSUPPORTED_CONFIGURATION.
     *
     * An option name the concrete class itself declares is the exception: PHP
     * resolves both the `property_exists()` gate and the `$this->option` read to
     * the most-derived declaration, so an ancestor's same-named declaration never
     * carried live behavior at any chain depth — the class-level scan has already
     * proven the child's declaration a single non-static supported unread literal
     * and lifted it into the attribute. Project readers observing the declaration
     * from above are a separate guard: the trait machinery's reads are replaced by
     * the attribute, but ancestor, ancestor-trait and composed-trait methods keep
     * executing, and inheritedReaderConflictReason() fails the lift closed for
     * them unless every reader is provably inert.
     *
     * A class without a database trait never reaches this scan: without the trait the
     * machinery never ran, so unrelated ancestor members are not flagged.
     *
     * Walks the extends chain through the file's own classes first, then through
     * AstResolver — the exact resolution order the other hierarchy walks use.
     * Unresolvable names are not flagged: the classification stays exactly as
     * provable as before, cycles and over-deep chains terminate the walk (the
     * hierarchy rule owns their diagnosis). Each resolved ancestor also contributes
     * its own trait uses to the trait scan: a project ancestor's used trait
     * flattens its option properties into that ancestor, where they are inherited
     * exactly like inline declarations.
     *
     * @param array<non-empty-string, Expr> $ownOptions
     * @param list<ClassLike> $localClasses
     * @param array<string, true> $seenTraits Shared with the direct trait scan, so a
     *        trait used both directly and through an ancestor is inspected once,
     *        under the direct (wider) visibility rules first.
     * @param bool $mergeIntoAncestor Out flag: set when the conversion must merge
     *        into an ancestor's attribute instead of adding its own.
     */
    private function ancestorConflictReason(
        Class_ $class,
        array $localClasses,
        string $sourceTrait,
        array &$seenTraits,
        array $ownOptions,
        bool &$mergeIntoAncestor,
    ): ?string {
        $current = $class->extends === null ? null : $this->resolvedName($class->extends);

        if ($current === null) {
            return null;
        }

        $optionProperties = self::PROPERTY_OPTIONS[$sourceTrait];
        $shadowedOptions = [];
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                $name = $item->name->toString();

                if (isset($optionProperties[$name])) {
                    $shadowedOptions[$name] = true;
                }
            }
        }
        $seen = [];
        $ancestorTraitNames = [];

        /**
         * Bottom-up duplicates of the same database strategy, nearest ancestor
         * first: `name` plus the options its hierarchy position carries.
         *
         * @var list<array{name: non-empty-string, options: array<non-empty-string, Expr>}> $duplicates
         */
        $duplicates = [];

        for ($depth = 0; $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current === self::FRAMEWORK_BASE || $current === self::TARGET_BASE || isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;

            $ancestor = $this->resolveAncestor($current, $localClasses);

            if (! $ancestor instanceof Class_) {
                break;
            }

            [$duplicateReason, $duplicateOptions] = $this->duplicateConfiguration(
                $ancestor,
                $current,
                $sourceTrait,
                $localClasses,
            );

            if ($duplicateReason !== null) {
                return $duplicateReason;
            }

            if ($duplicateOptions !== null) {
                // A duplicate ancestor's option declarations are configuration, not
                // shadowing danger: they are lifted into the ancestor's position of
                // the hierarchy and compared for exactness below.
                $duplicates[] = ['name' => $current, 'options' => $duplicateOptions];
            } else {
                $reason = $this->ancestorPropertyConflict($ancestor, $current, $optionProperties, $shadowedOptions);

                if ($reason !== null) {
                    return $reason;
                }
            }

            $ancestorTraitNames = array_merge($ancestorTraitNames, $this->directTraitNames($ancestor));

            if ($ancestor->extends === null) {
                break;
            }

            $current = $this->resolvedName($ancestor->extends);

            if ($current === null) {
                break;
            }
        }

        if ($duplicates !== []) {
            $mergeReason = $this->duplicateMergeConflict($ownOptions, $duplicates, $sourceTrait);

            if ($mergeReason !== null) {
                return $mergeReason;
            }

            // The child's own project-trait composition scan already ran in
            // analyze(); with the ancestor configuration proven identical, the
            // trait use converts into nothing and the ancestor's single attribute
            // keeps covering this class.
            $mergeIntoAncestor = true;

            return null;
        }

        return $this->traitCompositionConflictReason(
            $ancestorTraitNames,
            false,
            $class,
            $localClasses,
            $optionProperties,
            $seenTraits,
        );
    }

    /**
     * The options a duplicate ancestor carries at its hierarchy position, or
     * [null, null] when the ancestor is not a duplicate of this strategy at all.
     * An ancestor explicitly using the same trait runs the exact same conversion:
     * its own preflight must succeed (otherwise the hierarchy is half-migrated and
     * the merge would silently drop this class's machinery) and its lifted options
     * are the configuration it carries. An ancestor carrying the migrated target
     * attribute instead is already final: its literal arguments are read directly,
     * and anything non-literal cannot be proven equal.
     *
     * @param list<ClassLike> $localClasses
     * @return array{non-empty-string|null, array<non-empty-string, Expr>|null}
     */
    private function duplicateConfiguration(
        Class_ $ancestor,
        string $ancestorName,
        string $sourceTrait,
        array $localClasses,
    ): array {
        if ($this->directlyUsesDatabaseTrait($ancestor, $sourceTrait)) {
            $analysis = $this->analyze($ancestor, $localClasses);

            if ($analysis->unsupportedReason !== null) {
                return [
                    sprintf(
                        'ancestor %s also uses %s but cannot be converted automatically: %s',
                        $ancestorName,
                        $sourceTrait,
                        $analysis->unsupportedReason,
                    ),
                    null,
                ];
            }

            return [null, $analysis->options];
        }

        [$carries, $attributeOptions] = $this->migratedAttributeConfiguration($ancestor, self::TRAITS[$sourceTrait]);

        if (! $carries) {
            return [null, null];
        }

        if ($attributeOptions === null) {
            return [
                sprintf(
                    'ancestor %s already carries %s with arguments that cannot be proven equal - align the duplicated database configuration manually',
                    $ancestorName,
                    self::TRAITS[$sourceTrait],
                ),
                null,
            ];
        }

        return [null, $attributeOptions];
    }

    /**
     * Whether the class-like's own TraitUse statements use the given framework
     * database trait. Only direct statements count: a use inside a nested
     * class-like belongs to that class-like, mirroring directTraitNames().
     */
    private function directlyUsesDatabaseTrait(ClassLike $classLike, string $sourceTrait): bool
    {
        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                if ($this->resolvedName($trait) === $sourceTrait) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the class carries the migrated target attribute and which literal
     * options it declares. Returns [true, null] when the attribute is present but
     * its arguments cannot be proven (positional or non-literal arguments — the
     * rules only ever emit named literals), [false, null] when absent.
     *
     * @return array{bool, array<non-empty-string, Expr>|null}
     */
    private function migratedAttributeConfiguration(Class_ $class, string $targetAttribute): array
    {
        $matches = [];

        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->resolvedName($attribute->name) === $targetAttribute) {
                    $matches[] = $attribute;
                }
            }
        }

        if ($matches === []) {
            return [false, null];
        }

        if (count($matches) > 1) {
            return [true, null];
        }

        $options = [];

        foreach ($matches[0]->args as $arg) {
            if ($arg->name === null || $arg->unpack || ! $this->isLiteral($arg->value)) {
                return [true, null];
            }

            $options[$arg->name->toString()] = $arg->value;
        }

        return [true, $options];
    }

    /**
     * Why the duplicate-trait merge cannot be proven lossless. The post-migration
     * configuration of the class is the topmost duplicate ancestor's attribute
     * (every lower duplicate merges away, so only that one keeps an attribute);
     * the pre-migration configuration resolves each option name to its nearest
     * declaration below that ancestor. Both must agree exactly on presence and
     * value — a wrong "equal" verdict here would be a silent behavior change.
     *
     * @param array<non-empty-string, Expr> $ownOptions
     * @param list<array{name: non-empty-string, options: array<non-empty-string, Expr>}> $duplicates
     */
    private function duplicateMergeConflict(array $ownOptions, array $duplicates, string $sourceTrait): ?string
    {
        // Nearest-declaration resolution: the class's own options shadow everything
        // inherited, and each duplicate ancestor shadows the ones above it (the
        // list runs bottom-up).
        $effective = $ownOptions;

        foreach ($duplicates as $duplicate) {
            foreach ($duplicate['options'] as $name => $value) {
                if (! array_key_exists($name, $effective)) {
                    $effective[$name] = $value;
                }
            }
        }

        $top = $duplicates[count($duplicates) - 1];

        foreach ($effective as $name => $value) {
            if (! array_key_exists($name, $top['options'])) {
                return sprintf(
                    'ancestor %s also uses %s and the database configurations cannot be merged exactly (%s exists only here) - remove the duplicated trait configuration manually',
                    $top['name'],
                    $sourceTrait,
                    $this->renderOptions([$name => $value]),
                );
            }

            if (! $this->sameLiteral($value, $top['options'][$name])) {
                return sprintf(
                    'ancestor %s also uses %s and the database configurations cannot be merged exactly (%s here, %s on the ancestor) - remove the duplicated trait configuration manually',
                    $top['name'],
                    $sourceTrait,
                    $this->renderOptions([$name => $value]),
                    $this->renderOptions([$name => $top['options'][$name]]),
                );
            }
        }

        foreach ($top['options'] as $name => $value) {
            if (! array_key_exists($name, $effective)) {
                return sprintf(
                    'ancestor %s also uses %s and the database configurations cannot be merged exactly (%s exists only on the ancestor) - remove the duplicated trait configuration manually',
                    $top['name'],
                    $sourceTrait,
                    $this->renderOptions([$name => $value]),
                );
            }
        }

        return null;
    }

    /**
     * Exact literal comparison, deliberately spelled out through the printer:
     * `true` and `TRUE` are different sources and render differently, so any
     * ambiguity fails closed on the merge instead of guessing equivalence.
     */
    private function sameLiteral(Expr $left, Expr $right): bool
    {
        return $this->prettyPrinter->prettyPrintExpr($left) === $this->prettyPrinter->prettyPrintExpr($right);
    }

    /**
     * Human-facing option rendering for residual reasons, sanitized for the
     * marker grammar (`;` separates contributions, `*` breaks the comment form).
     *
     * @param array<non-empty-string, Expr> $options
     */
    private function renderOptions(array $options): string
    {
        if ($options === []) {
            return 'defaults';
        }

        $parts = [];

        foreach ($options as $name => $value) {
            $parts[] = $name . '=' . str_replace([';', '*', "\n"], ['"', 'x', ' '], $this->prettyPrinter->prettyPrintExpr($value));
        }

        return implode(', ', $parts);
    }

    /**
     * Same-file lookup first, then reflection — the resolution order
     * LaravelBaseClassRector::resolveClassNode() uses, so a base defined in the file
     * currently being processed resolves without autoload. Same-file traits share
     * the lookup table and are skipped here: an extends target is always a class.
     *
     * @param list<ClassLike> $localClasses
     */
    private function resolveAncestor(string $className, array $localClasses): ?Class_
    {
        foreach ($localClasses as $local) {
            if ($local instanceof Class_
                && $local->namespacedName !== null
                && $local->namespacedName->toString() === $className) {
                return $local;
            }
        }

        try {
            $resolved = $this->astResolver->resolveClassFromName($className);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof Class_ ? $resolved : null;
    }

    /**
     * The first live option declaration the ancestor carries, as a fail-closed
     * reason, or null when the conversion is hierarchy-safe.
     *
     * An option name the concrete class itself declares is skipped at every chain
     * level: PHP resolves a redeclared property to its most-derived declaration,
     * so both the trait machinery's `property_exists($this, ...)` gate and the
     * `$this->option` read answer from the class's own declaration, and the
     * ancestor's same-named declaration is inert. When the class does not declare
     * the name, the most-derived non-private non-static ancestor declaration is
     * exactly the value the machinery reads, and the reason names it.
     *
     * Private members are skipped: `property_exists()` is visibility-aware, so the
     * gate already answers false for an ancestor-private option and the traits
     * fall back to their defaults — the private value never carried behavior.
     * Static members are skipped too: the traits read their options through
     * `$this`, which never resolves a static declaration (an ancestor-static read
     * warns and yields null), and mixing static with non-static down a chain is a
     * compile-time fatal the hierarchy rule owns, not a provable value.
     *
     * @param array<non-empty-string, non-empty-string> $optionProperties
     * @param array<non-empty-string, true> $shadowedOptions Option names the
     *        converted class itself declares; the class-level scan has already
     *        proven each one a single non-static supported unread literal and
     *        lifted it into the attribute.
     */
    private function ancestorPropertyConflict(
        Class_ $ancestor,
        string $ancestorName,
        array $optionProperties,
        array $shadowedOptions,
    ): ?string {
        foreach ($ancestor->getProperties() as $property) {
            if ($property->isPrivate() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $item) {
                $name = $item->name->toString();

                if (isset($optionProperties[$name]) && ! isset($shadowedOptions[$name])) {
                    return sprintf('database option $%s on ancestor %s requires manual migration', $name, $ancestorName);
                }
            }
        }

        return null;
    }

    /**
     * Why converting this class's database trait would silently drop an option
     * property supplied by a used project trait, or null when the conversion is
     * trait-safe. Traits flatten their full composition tree into every consuming
     * class at runtime, and the Laravel traits read their options through
     * `property_exists($this, ...)` from the consumer's scope — so a trait-supplied
     * option is live exactly like an inline declaration, yet `Class_::getProperties()`
     * never sees it (PHP-Parser does not flatten trait properties).
     *
     * Only TraitUse statements on the class-like's own body flatten into it: a trait
     * use inside a nested class-like belongs to that class-like. The four framework
     * database traits declare no option properties and are skipped. Names the class
     * itself declares are skipped as well: the class's own declaration wins the
     * composition (or the file fatals), and the class-level scan already lifted
     * that literal into the attribute.
     *
     * A used trait that cannot be resolved — or resolves to a non-trait — cannot be
     * proven option-free and fails the conversion closed. Cyclic compositions
     * terminate on the seen-set: every member of a cycle was already collected, and
     * the composition itself is a runtime fatal the hierarchy rule owns.
     *
     * @param list<non-empty-string> $traitNames
     * @param array<non-empty-string, non-empty-string> $optionProperties
     * @param list<ClassLike> $localClasses
     * @param array<string, true> $seenTraits Shared with the other scan phase, so a
     *        trait used both directly and through an ancestor is inspected once,
     *        under the direct (wider) visibility rules first.
     * @param list<lowercase-string&non-empty-string> $hookOverrides The active
     *        trait's live machinery hooks; checked in the consumer's own scope
     *        only, where every visibility qualifies. Empty for the ancestor
     *        walk: an ancestor trait's hook is dead for the descendant because
     *        the descendant's source-trait machinery overrides every inherited
     *        same-named method.
     */
    private function traitCompositionConflictReason(
        array $traitNames,
        bool $sameClassScope,
        Class_ $class,
        array $localClasses,
        array $optionProperties,
        array &$seenTraits,
        array $hookOverrides = [],
    ): ?string {
        while ($traitNames !== []) {
            $traitName = array_shift($traitNames);

            if (isset($seenTraits[$traitName])) {
                continue;
            }

            $seenTraits[$traitName] = true;

            $trait = $this->resolveTraitLike($traitName, $localClasses);

            if (! $trait instanceof Trait_) {
                return sprintf('used trait %s could not be resolved, so its database options require manual migration', $traitName);
            }

            $reason = $this->traitPropertyConflict($trait, $traitName, $sameClassScope, $class, $optionProperties)
                ?? $this->traitHookConflict($trait, $traitName, $hookOverrides);

            if ($reason !== null) {
                return $reason;
            }

            // The whole composition tree flattens into the consumer, so a used-by-used
            // trait contributes exactly like a direct one.
            $traitNames = array_merge($traitNames, $this->directTraitNames($trait));
        }

        return null;
    }

    /**
     * The trait names of the class-like's own TraitUse statements, skipping the
     * framework database traits: a trait use inside a nested class-like belongs
     * to that class-like, not to this one, so only the body's direct statements
     * flatten their properties into it.
     *
     * @return list<non-empty-string>
     */
    private function directTraitNames(ClassLike $classLike): array
    {
        $names = [];

        foreach ($classLike->stmts as $statement) {
            if (! $statement instanceof TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                $name = $this->resolvedName($trait);

                if ($name !== null && ! isset(self::TRAITS[$name])) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Same-file lookup first, then reflection — the resolution order the ancestor
     * walk uses, so a trait defined in the file currently being processed resolves
     * without autoload. Null when the symbol cannot be resolved as a trait.
     *
     * @param list<ClassLike> $localClasses
     */
    private function resolveTraitLike(string $traitName, array $localClasses): ?Trait_
    {
        foreach ($localClasses as $local) {
            if ($local instanceof Trait_
                && $local->namespacedName !== null
                && $local->namespacedName->toString() === $traitName) {
                return $local;
            }
        }

        try {
            $resolved = $this->astResolver->resolveClassFromName($traitName);
        } catch (\Throwable) {
            return null;
        }

        return $resolved instanceof Trait_ ? $resolved : null;
    }

    /**
     * The first live option declaration the trait supplies, as a fail-closed
     * reason. The composition flattens into the consumer with the consumer's
     * scope: a directly used trait lives in the consumer's own scope, where even
     * private members are reachable (`property_exists()` and `$this->option` both
     * see them); a trait an ancestor uses is inherited, so private members stay
     * private to the ancestor and are invisible to the descendant. Static
     * properties are skipped in both scopes: the traits read their options
     * through `$this`, which never resolves to a static declaration.
     *
     * @param array<non-empty-string, non-empty-string> $optionProperties
     */
    private function traitPropertyConflict(
        Trait_ $trait,
        string $traitName,
        bool $sameClassScope,
        Class_ $class,
        array $optionProperties,
    ): ?string {
        $ownNames = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $item) {
                $ownNames[$item->name->toString()] = true;
            }
        }

        foreach ($trait->getProperties() as $property) {
            if ($property->isStatic() || (! $sameClassScope && $property->isPrivate())) {
                continue;
            }

            foreach ($property->props as $item) {
                $name = $item->name->toString();

                if (isset($optionProperties[$name]) && ! isset($ownNames[$name])) {
                    return sprintf('database option $%s on trait %s requires manual migration', $name, $traitName);
                }
            }
        }

        return null;
    }

    /**
     * Why a hook method the trait supplies requires manual migration, or null
     * when it supplies none. The composition flattens into the consumer with
     * the consumer's scope, and the machinery calls its hooks through
     * `$this`, which resolves even static and private trait members — so any
     * visibility qualifies. When the source trait declares the same hook, the
     * shape is one of: a runtime fatal (two traits, no adaptation), an
     * insteadof adaptation selecting the project body while the source
     * trait's separate use statement stays adaptation-free (live), or an
     * adaptation selecting the source body (dead, but not provable without
     * deciding adaptation semantics). All three fail closed rather than
     * guess: the class can be restructured or migrated by hand.
     *
     * @param list<lowercase-string&non-empty-string> $hookOverrides
     */
    private function traitHookConflict(Trait_ $trait, string $traitName, array $hookOverrides): ?string
    {
        if ($hookOverrides === []) {
            return null;
        }

        foreach ($trait->getMethods() as $method) {
            $name = $method->name->toString();

            // PHP resolves method names case-insensitively; see the class-level scan.
            if (in_array(strtolower($name), $hookOverrides, true)) {
                return sprintf('database override %s() on trait %s requires manual migration', $name, $traitName);
            }
        }

        return null;
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

    /**
     * Why the connection selection of a DEFAULT-migration strategy
     * (RefreshDatabase, DatabaseTruncation) cannot lift into the attribute's
     * connections argument, or null when it provably selects only the default
     * connection.
     *
     * The source traits run their single migrate:fresh against the DEFAULT
     * connection no matter what the selection contains: the selection only
     * picks the trait's side scope (the per-test transactions for
     * RefreshDatabase, the table truncation for DatabaseTruncation), and
     * DatabaseTruncation's later db:seed calls pass no --database either. The
     * target attribute repoints migrate:fresh AND db:seed at every selected
     * connection instead (RefreshDatabaseInterceptor::migrateFresh() and
     * DatabaseTruncationInterceptor::migrateFresh()/seed() issue
     * `--database=$name` per entry), so a named selection silently wipes a
     * different schema and skips the default migration, and an empty selection
     * migrates nothing where the trait still migrated the default. The runtime
     * selection semantics are an explicit author choice and stay untouched:
     * only the unproven LIFT is refused.
     *
     * Proven equivalent is exactly a list with ONE null entry: the traits
     * resolve `connection(null)` to the default connection for their side
     * scope and always migrate the default, and the attribute's absent
     * argument resolves to the same default-only behavior. Multiple entries,
     * duplicates and empty strings cannot be proven equivalent to the runtime
     * default — `[]` and non-list literals already fail the literal-shape
     * guard upstream, so everything reaching this helper with entries is a
     * named selection.
     */
    private function connectionsMigrationScopeConflictReason(Expr $value, string $namedSelectionReason): ?string
    {
        if ($this->isSingleDefaultSelection($value)) {
            return null;
        }

        return $namedSelectionReason;
    }

    /**
     * A list with exactly one unkeyed null entry: the only selection shape
     * statically equivalent to the runtime default-only scope.
     */
    private function isSingleDefaultSelection(Expr $value): bool
    {
        return $value instanceof Expr\Array_
            && count($value->items) === 1
            && $value->items[0] !== null
            && $value->items[0]->key === null
            && $value->items[0]->value instanceof Expr\ConstFetch
            && strtolower($value->items[0]->value->name->toString()) === 'null';
    }

    /**
     * The residual wording for a named selection, naming the converting
     * strategy's option and its actual source scope: RefreshDatabase's
     * selection only picks the per-test transaction scope, DatabaseTruncation's
     * only the table truncation, and both keep their first migrate:fresh
     * (DatabaseTruncation also its later db:seed) on the default connection.
     */
    private function connectionsSelectionResidualReason(string $sourceTrait): string
    {
        return match ($sourceTrait) {
            'Illuminate\Foundation\Testing\RefreshDatabase'
                => 'database option $connectionsToTransact changes the migration scope: the trait always runs migrate:fresh on the default connection, while the attribute would refresh the selected connections - migrate it manually',
            'Illuminate\Foundation\Testing\DatabaseTruncation'
                => 'database option $connectionsToTruncate changes the migration and seeding scope: the trait only truncates the selected connections while its first migrate:fresh and every later db:seed run on the default connection, but the attribute would migrate and seed every selected connection - migrate it manually',
        };
    }

    /**
     * Whether the table/exclusion literal is a connection-KEYED map (every item
     * carries a literal string key and a list of tables) rather than a flat
     * table list: the keyed shape resolves differently on the two sides of the
     * conversion. Empty lists and flat lists return false — they remain
     * provably equivalent positive controls.
     */
    private function isConnectionKeyedMap(Expr $value): bool
    {
        if (! $value instanceof Expr\Array_ || $value->items === []) {
            return false;
        }

        foreach ($value->items as $item) {
            if ($item === null || $item->unpack || $item->key === null || ! $item->value instanceof Expr\Array_) {
                return false;
            }
        }

        return true;
    }

    /**
     * The residual wording for a connection-keyed map, naming the option and
     * the actual source lookup: the trait indexes the map with the null
     * default selector (a null key never matches a literal name) and falls
     * back to the whole map, whose array values match no table, so the keyed
     * shape keeps or truncates everything except the migrations table — the
     * attribute resolves the selector to the connection name and applies the
     * listed tables instead.
     */
    private function tableMapResidualReason(string $argumentName): string
    {
        return match ($argumentName) {
            'tables' => 'database option $tablesToTruncate keyed by connection name changes the truncation selection: the trait looks the map up with the null default selector, falls back to the whole map that matches no table and truncates nothing, while the attribute would truncate the listed tables on the resolved connection - migrate it manually',
            'exceptTables' => 'database option $exceptTables keyed by connection name changes the exclusion selection: the trait looks the map up with the null default selector, falls back to the whole map that matches no table and excludes nothing beyond the migrations table, while the attribute would exclude the listed tables on the resolved connection - migrate it manually',
        };
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
            fn (Node $node): bool => $this->instancePropertyRead($node, $propertyName) !== null,
        ) instanceof Node;
    }

    /**
     * Whether one node is an instance read of $propertyName: null when the node is
     * not an instance fetch of the name, false for a statically named read
     * (`$this->seed`, nullsafe `$this?->seed`, literal-string `$this->{'seed'}`),
     * true for a dynamic property name whose target cannot be proven.
     */
    private function instancePropertyRead(Node $node, string $propertyName): ?bool
    {
        if (! $node instanceof Expr\PropertyFetch
            && ! $node instanceof Expr\NullsafePropertyFetch) {
            return null;
        }

        if (! $node->var instanceof Expr\Variable || $node->var->name !== 'this') {
            return null;
        }

        if ($node->name instanceof Node\Identifier) {
            return $node->name->toString() === $propertyName ? false : null;
        }

        if ($node->name instanceof Scalar\String_) {
            return $node->name->value === $propertyName ? false : null;
        }

        // Dynamic property name ($this->{$opt}): the option may be read,
        // so fail closed instead of removing a live declaration.
        return true;
    }

    /**
     * Why lifting the class's own option property into the attribute would change
     * what project code observes by reading it, or null when the lift is
     * reader-safe.
     *
     * The replaced trait machinery's reads are accounted for by the attribute, but
     * every PROJECT reader keeps executing: methods of the resolved ancestors,
     * methods of the traits those ancestors compose, and methods of the project
     * traits the converting class itself composes — trait methods execute with
     * the consuming class's scope. While the class carries the declaration it is
     * the value those readers observe; removing it silently repoints the reads at
     * whatever the hierarchy resolves next. One reader shape is proven inert: a
     * reader whose own scope owns a private declaration of the name answers from
     * that private slot before and after the lift, because a more-derived
     * redeclare never shadows it there (PropertyShadowingRuntimeContractTest pins
     * the slot semantics). Everything else fails closed with a stable residual —
     * a private slot in any other scope shields nothing, and equal lifted and
     * inherited literals are not chased by a value-proof engine.
     *
     * Statically named instance access is a reader; a dynamic property NAME
     * (`$this->{$option}`) cannot be proven either way; a static property fetch
     * never resolves an instance declaration, and the mixed static/non-static
     * hierarchy shapes are compile-time fatals the hierarchy rule owns. A
     * composition trait that cannot be resolved has unknown methods, so the walk
     * fails closed on it. Ancestor classes that cannot be resolved terminate the
     * walk, mirroring ancestorConflictReason(): the hierarchy rule owns their
     * diagnosis.
     *
     * @param list<ClassLike> $localClasses
     */
    private function inheritedReaderConflictReason(
        Class_ $class,
        array $localClasses,
        string $propertyName,
    ): ?string {
        $className = $class->namespacedName?->toString() ?? 'the converting class';

        [$childTraitReaders, $unresolvable] = $this->composedReaderSites($class, $propertyName, $localClasses, $className);

        $readers = $childTraitReaders;
        $privateSlotScopes = [];

        $current = $class->extends === null ? null : $this->resolvedName($class->extends);
        $seen = [];

        for ($depth = 0; $current !== null && $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current === self::FRAMEWORK_BASE || $current === self::TARGET_BASE || isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;

            $ancestor = $this->resolveAncestor($current, $localClasses);

            if (! $ancestor instanceof Class_) {
                break;
            }

            if ($this->hasOwnPrivateDeclaration($ancestor, $propertyName, $localClasses)) {
                $privateSlotScopes[$current] = true;
            }

            [$ancestorTraitReaders, $traitUnresolvable] = $this->composedReaderSites($ancestor, $propertyName, $localClasses, $current);
            $unresolvable ??= $traitUnresolvable;

            $readers = array_merge($readers, $this->ownReaderSites($ancestor, $propertyName, $current), $ancestorTraitReaders);

            $current = $ancestor->extends === null ? null : $this->resolvedName($ancestor->extends);
        }

        // A composition trait whose methods are unknown may be a reader itself.
        if ($unresolvable !== null) {
            return sprintf('used trait %s could not be resolved, so its database option reads require manual migration', $unresolvable);
        }

        foreach ($readers as $reader) {
            if ($reader['dynamic']) {
                return sprintf(
                    'database option $%s may be read dynamically by project code in %s and requires manual migration',
                    $propertyName,
                    $reader['location'],
                );
            }
        }

        foreach ($readers as $reader) {
            if (! isset($privateSlotScopes[$reader['scope']])) {
                return sprintf(
                    'database option $%s is read by project code in %s and requires manual migration',
                    $propertyName,
                    $reader['location'],
                );
            }
        }

        return null;
    }

    /**
     * Walks the class-like's project-trait composition tree depth-first (seen-set
     * terminated, framework database traits excluded like directTraitNames()) and
     * visits every composed trait. Returns the first trait name that could not be
     * resolved, or null when the whole tree resolved.
     *
     * @param list<ClassLike> $localClasses
     * @param \Closure(Trait_, non-empty-string): void $visit
     */
    private function eachComposedTrait(ClassLike $classLike, array $localClasses, \Closure $visit): ?string
    {
        $traitNames = $this->directTraitNames($classLike);
        $seenTraits = [];

        while ($traitNames !== []) {
            $traitName = array_shift($traitNames);

            if (isset($seenTraits[$traitName])) {
                continue;
            }

            $seenTraits[$traitName] = true;

            $trait = $this->resolveTraitLike($traitName, $localClasses);

            if (! $trait instanceof Trait_) {
                return $traitName;
            }

            $visit($trait, $traitName);

            $traitNames = array_merge($traitNames, $this->directTraitNames($trait));
        }

        return null;
    }

    /**
     * The reader sites in the class-like's project-trait composition, together
     * with the first unresolvable composition trait. A reader site carries the
     * consuming scope (whose private slots decide inertness) and the declaring
     * symbol (named in the residual reason).
     *
     * @param list<ClassLike> $localClasses
     * @return array{0: list<array{scope: non-empty-string, location: non-empty-string, dynamic: bool}>, 1: ?non-empty-string}
     */
    private function composedReaderSites(ClassLike $classLike, string $propertyName, array $localClasses, string $scope): array
    {
        $readers = [];

        $unresolvable = $this->eachComposedTrait(
            $classLike,
            $localClasses,
            function (Trait_ $trait, string $traitName) use (&$readers, $propertyName, $scope): void {
                foreach ($trait->getMethods() as $method) {
                    $site = $this->methodReaderSite($method, $propertyName, $scope, $traitName);

                    if ($site !== null) {
                        $readers[] = $site;
                    }
                }
            },
        );

        return [$readers, $unresolvable];
    }

    /**
     * The reader sites in the class's own methods at one ancestor level. The
     * converting class's own reads have their own residual shape
     * (propertyIsReadByClass) and are never scanned here.
     *
     * @return list<array{scope: non-empty-string, location: non-empty-string, dynamic: bool}>
     */
    private function ownReaderSites(Class_ $class, string $propertyName, string $scope): array
    {
        $readers = [];

        foreach ($class->getMethods() as $method) {
            $site = $this->methodReaderSite($method, $propertyName, $scope, $scope);

            if ($site !== null) {
                $readers[] = $site;
            }
        }

        return $readers;
    }

    /**
     * The reader site one method contributes for $propertyName, or null. Any
     * instance fetch of the statically named property counts — including nullsafe
     * and literal-string dynamic spellings; a dynamic property NAME is flagged so
     * the caller can fail closed.
     *
     * @return array{scope: non-empty-string, location: non-empty-string, dynamic: bool}|null
     */
    private function methodReaderSite(ClassMethod $method, string $propertyName, string $scope, string $location): ?array
    {
        $dynamic = false;
        $reads = false;

        $fetches = array_merge(
            $this->nodeFinder->findInstanceOf($method, Expr\PropertyFetch::class),
            $this->nodeFinder->findInstanceOf($method, Expr\NullsafePropertyFetch::class),
        );

        foreach ($fetches as $fetch) {
            $read = $this->instancePropertyRead($fetch, $propertyName);

            if ($read === true) {
                $dynamic = true;

                break;
            }

            $reads = $reads || $read === false;
        }

        if ($dynamic) {
            return ['scope' => $scope, 'location' => $location, 'dynamic' => true];
        }

        return $reads ? ['scope' => $scope, 'location' => $location, 'dynamic' => false] : null;
    }

    /**
     * Whether the class carries a private non-static declaration of the property
     * in its own body or its composed traits — the reader-exempting own slot: a
     * reader whose scope owns that slot answers from it before and after the
     * lift, whatever more-derived declarations exist.
     *
     * @param list<ClassLike> $localClasses
     */
    private function hasOwnPrivateDeclaration(Class_ $class, string $propertyName, array $localClasses): bool
    {
        if ($this->hasPrivatePropertyItem($class, $propertyName)) {
            return true;
        }

        $found = false;

        $this->eachComposedTrait(
            $class,
            $localClasses,
            function (Trait_ $trait) use (&$found, $propertyName): void {
                if ($this->hasPrivatePropertyItem($trait, $propertyName)) {
                    $found = true;
                }
            },
        );

        return $found;
    }

    private function hasPrivatePropertyItem(ClassLike $classLike, string $propertyName): bool
    {
        foreach ($classLike->getProperties() as $property) {
            if (! $property->isStatic() && $property->isPrivate()) {
                foreach ($property->props as $item) {
                    if ($item->name->toString() === $propertyName) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Why a framework database strategy the class itself never `use`s still
     * requires a manual migration, or null when none is visible.
     *
     * PHP flattens a project trait's whole composition tree — its framework
     * trait uses included — into every consuming class, and the extends chain
     * flattens the ancestor's composition the same way. A `RefreshDatabase`
     * use that lives only inside a project trait therefore ran live machinery
     * for the class before the migration, yet the class carries no trait use
     * this rule converts: converting the class would drop the machinery
     * silently, so the strategy must stay visible through a residual instead.
     * The same scan runs for classes that DO use a framework trait directly,
     * where a second hidden use is a duplication the conversion cannot remove
     * (the project trait survives in source and keeps flattening).
     *
     * Scanned: the class's project-trait composition tree and, through every
     * resolvable ancestor, the ancestor's own subtree and composition tree —
     * any TraitUse naming a framework database trait inside those subtrees
     * counts, including uses inside nested class-likes. An ancestor that also
     * carries the migrated attribute for a found strategy is NOT skipped:
     * attribute presence never proves the project trait was rewritten (no
     * rule rewrites traits), so the composed strategy still flattens into the
     * hierarchy and must stay flagged. Only the ancestor's DIRECT framework
     * trait use stays out of scope here: a strategy inherited that way
     * survives the ancestor's own conversion as attribute inheritance, and a
     * duplicate of the class's own direct use is the duplicate machinery's
     * contract.
     *
     * A class whose resolvable ancestors carry ONLY migrated attributes and
     * whose composed traits name no framework strategy passes untouched: the
     * scan simply returns null.
     *
     * Unresolvable ancestors terminate the walk silently, mirroring the other
     * hierarchy walks. A composed trait that cannot be resolved fails the scan
     * closed: its composition is unknown, so a hidden strategy cannot be ruled
     * out for a class that would otherwise migrate clean.
     *
     * @param list<ClassLike> $localClasses
     * @return array{trait: non-empty-string, reason: non-empty-string}|null
     */
    private function hiddenComposedStrategyReason(Class_ $class, array $localClasses): ?array
    {
        $own = $this->composedTraitsStrategyReason($class, $localClasses, null);

        if ($own !== null) {
            return $own;
        }

        $current = $class->extends === null ? null : $this->resolvedName($class->extends);
        $seen = [];

        for ($depth = 0; $current !== null && $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current === self::FRAMEWORK_BASE || $current === self::TARGET_BASE || isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;

            $ancestor = $this->resolveAncestor($current, $localClasses);

            if (! $ancestor instanceof Class_) {
                break;
            }

            // No suppression for an ancestor that also carries the migrated
            // attribute: attribute presence never proves the project trait was
            // rewritten (no rule rewrites traits), so the composed strategy
            // still flattens into the hierarchy and must stay flagged.
            $inherited = $this->composedTraitsStrategyReason($ancestor, $localClasses, $current);

            if ($inherited !== null) {
                return $inherited;
            }

            $current = $ancestor->extends === null ? null : $this->resolvedName($ancestor->extends);
        }

        return null;
    }

    /**
     * The first framework database strategy inside the class-like's composed
     * project traits, as a fail-closed reason carrying the strategy and the
     * residual wording, or null. $ancestorScope names the ancestor whose
     * composition is scanned (null for the converting class itself).
     *
     * @param list<ClassLike> $localClasses
     * @param non-empty-string|null $ancestorScope
     * @return array{trait: non-empty-string, reason: non-empty-string}|null
     */
    private function composedTraitsStrategyReason(
        Class_ $class,
        array $localClasses,
        ?string $ancestorScope,
    ): ?array {
        $found = null;

        $unresolvable = $this->eachComposedTrait(
            $class,
            $localClasses,
            function (Trait_ $trait, string $traitName) use (&$found, $ancestorScope): void {
                if ($found !== null) {
                    return;
                }

                $strategy = $this->frameworkStrategyInSubtree($trait);

                if ($strategy === null) {
                    return;
                }

                $via = $ancestorScope === null
                    ? sprintf('framework database strategy %s arrives through project trait %s and is not converted automatically - migrate it manually', $strategy, $traitName)
                    : sprintf('framework database strategy %s arrives through project trait %s on ancestor %s and is not converted automatically - migrate it manually', $strategy, $traitName, $ancestorScope);

                $found = ['trait' => $strategy, 'reason' => $via];
            },
        );

        if ($found !== null) {
            return $found;
        }

        if ($unresolvable !== null) {
            return [
                'trait' => 'Illuminate\Foundation\Testing\RefreshDatabase',
                'reason' => sprintf(
                    'used trait %s could not be resolved, so a hidden database strategy cannot be ruled out - migrate it manually',
                    $unresolvable,
                ),
            ];
        }

        return null;
    }

    /**
     * The first framework database trait used anywhere in the class-like's own
     * subtree — direct TraitUse statements and uses inside nested class-likes
     * alike — or null.
     */
    private function frameworkStrategyInSubtree(ClassLike $classLike): ?string
    {
        foreach ($this->nodeFinder->findInstanceOf($classLike, TraitUse::class) as $traitUse) {
            foreach ($traitUse->traits as $trait) {
                $name = $this->resolvedName($trait);

                if ($name !== null && isset(self::TRAITS[$name])) {
                    return $name;
                }
            }
        }

        return null;
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

    /**
     * The Laravel Seed/Seeder attribute situation on the resolved ancestors
     * above the class.
     *
     * Laravel 13's CanConfigureMigrationCommands reads both attributes through
     * the whole reflection chain: shouldSeed() answers true as soon as ANY
     * level carries a Seed attribute (before the property fallback), and
     * seeder() takes the NEAREST level's first Seeder attribute (also before
     * the property fallback, so an inherited Seeder shadows the class's own
     * literal $seeder). Laravel 12 ignores both attributes entirely and reads
     * only the properties; the conversion targets the newer supported
     * semantics, consistent with the existing own-level attribute lift.
     *
     * Walk contract: same-file ancestors first, then AstResolver; the walk
     * stops at the framework and target bases (no project attributes above
     * them), at cycles and at the chain-depth cap (the hierarchy rule owns
     * those diagnostics), and terminates silently on an unresolvable ancestor
     * like the other hierarchy walks. A nearest Seeder is validated with the
     * own-level rules (one attribute, one non-unpacked literal class argument);
     * deeper shadowed levels are dead code and are not validated.
     *
     * @param list<ClassLike> $localClasses
     * @return array{seed: bool, seeder: ?Expr, conflict: ?non-empty-string}
     */
    private function inheritedSeedAttributes(Class_ $class, array $localClasses): array
    {
        $result = ['seed' => false, 'seeder' => null, 'conflict' => null];

        $current = $class->extends === null ? null : $this->resolvedName($class->extends);
        $seen = [];

        for ($depth = 0; $current !== null && $depth <= self::MAX_CHAIN_DEPTH; $depth++) {
            if ($current === self::FRAMEWORK_BASE || $current === self::TARGET_BASE || isset($seen[$current])) {
                break;
            }

            $seen[$current] = true;

            $ancestor = $this->resolveAncestor($current, $localClasses);

            if (! $ancestor instanceof Class_) {
                break;
            }

            $seedAttributes = $this->attributesNamed($ancestor, self::SEED_ATTRIBUTE);

            if ($seedAttributes !== []) {
                if (count($seedAttributes) > 1) {
                    $result['conflict'] = sprintf('duplicate Laravel Seed attributes on ancestor %s require manual migration', $current);

                    return $result;
                }

                if ($seedAttributes[0]->args !== []) {
                    $result['conflict'] = sprintf('Laravel Seed attribute arguments on ancestor %s are not supported', $current);

                    return $result;
                }

                $result['seed'] = true;
            }

            if ($result['seeder'] === null) {
                $seederAttributes = $this->attributesNamed($ancestor, self::SEEDER_ATTRIBUTE);

                if ($seederAttributes !== []) {
                    $attribute = $seederAttributes[0];

                    if (count($seederAttributes) > 1) {
                        $result['conflict'] = sprintf('duplicate Laravel Seeder attributes on ancestor %s require manual migration', $current);

                        return $result;
                    }

                    if (count($attribute->args) !== 1
                        || $attribute->args[0]->unpack
                        || ($attribute->args[0]->name !== null && $attribute->args[0]->name->toString() !== 'class')
                        || ! $this->isSeederLiteral($attribute->args[0]->value)) {
                        $result['conflict'] = sprintf('Laravel Seeder attribute on ancestor %s must contain one literal class', $current);

                        return $result;
                    }

                    $value = $attribute->args[0]->value;
                    if ($value instanceof Scalar\String_) {
                        $result['seeder'] = new Scalar\String_($value->value);
                    } elseif ($value instanceof Expr\ClassConstFetch && $value->class instanceof Name) {
                        $name = match (strtolower($value->class->toString())) {
                            'self' => $current,
                            'parent' => $ancestor->extends === null ? null : $this->resolvedName($ancestor->extends),
                            default => $this->resolvedName($value->class),
                        };
                        if ($name === null || strtolower($name) === 'static') {
                            $result['conflict'] = sprintf('Laravel Seeder attribute on ancestor %s must contain one literal class', $current);

                            return $result;
                        }

                        // A node from another file carries offsets into THAT file.
                        // Rebuild the literal to avoid reusing those tokens in the
                        // child's format-preserving printer or its namespace scope.
                        $result['seeder'] = new Expr\ClassConstFetch(new Name\FullyQualified($name), 'class');
                    } else {
                        $result['conflict'] = sprintf('Laravel Seeder attribute on ancestor %s must contain one literal class', $current);

                        return $result;
                    }
                }
            }

            if ($ancestor->extends === null) {
                break;
            }

            $current = $this->resolvedName($ancestor->extends);
        }

        return $result;
    }
}
