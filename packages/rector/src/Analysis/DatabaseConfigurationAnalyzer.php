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

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly AstResolver $astResolver,
    )
    {
        $this->nodeFinder = new NodeFinder();
        $this->prettyPrinter = new Standard();
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

        // Traits flatten their full composition tree into every consuming class,
        // so a project trait can supply live option properties that the class
        // scan above never sees (PHP-Parser does not flatten trait properties).
        $seenTraits = [];
        $directTraitReason = $this->traitCompositionConflictReason(
            $this->directTraitNames($class),
            true,
            $class,
            $localClasses,
            self::PROPERTY_OPTIONS[$sourceTrait],
            $seenTraits,
        );

        if ($directTraitReason !== null) {
            return $this->unsupported($base, $directTraitReason);
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
                $reason = $this->ancestorPropertyConflict($ancestor, $current, $optionProperties);

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
     * reason. Private members are skipped: `property_exists()` may see them, but the
     * trait-scope read `$this->option` cannot reach them, so they never carried
     * behavior. Static properties are skipped for the same reason: the traits read
     * their options through `$this`, which never resolves to a static declaration.
     *
     * @param array<non-empty-string, non-empty-string> $optionProperties
     */
    private function ancestorPropertyConflict(
        Class_ $ancestor,
        string $ancestorName,
        array $optionProperties,
    ): ?string {
        foreach ($ancestor->getProperties() as $property) {
            if ($property->isPrivate() || $property->isStatic()) {
                continue;
            }

            foreach ($property->props as $item) {
                $name = $item->name->toString();

                if (isset($optionProperties[$name])) {
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
     */
    private function traitCompositionConflictReason(
        array $traitNames,
        bool $sameClassScope,
        Class_ $class,
        array $localClasses,
        array $optionProperties,
        array &$seenTraits,
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

            $reason = $this->traitPropertyConflict($trait, $traitName, $sameClassScope, $class, $optionProperties);

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
