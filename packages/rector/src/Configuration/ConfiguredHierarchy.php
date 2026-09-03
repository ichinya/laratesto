<?php

declare(strict_types=1);

namespace Laratesto\Rector\Configuration;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use Rector\NodeNameResolver\NodeNameResolver;

/**
 * The one authoritative recognition state of the configured Laravel test hierarchy.
 *
 * Every Laravel-specific rule ({@see \Laratesto\Rector\Rules\LaravelBaseClassRector},
 * LaravelDatabaseTraitsRector, LaravelSourceCompatibleCallsRector,
 * LaravelResidualDetectionRector) asks this service whether a class is inside the
 * hierarchy ({@see recognizesTestClass()}), instead of keeping its own recognition
 * logic. One contract covers both target forms: the configured/target extends names
 * and the `target_mode=trait` form, where LaravelBaseClassRector removes the direct
 * framework parent and adds {@see TARGET_TRAIT} before the later rules run. A custom
 * `base_classes` override therefore applies to the whole pipeline at once: a class
 * outside the configured bases is never partially mutated by a rule that still
 * recognizes an old hard-coded default, and Laravel source traits never count as
 * membership, so an unrelated helper carrying one stays outside the hierarchy.
 *
 * Rector registers a rule class once as a container singleton, so the set registers
 * this service once too and every rule shares the same instance;
 * `LaravelBaseClassRector::configure()` is the only writer ({@see adopt()}). Between
 * adoptions the state is the {@see BaseClassConfiguration::defaults()} value, which is
 * exactly what a rule would see when it runs without the base-class rule configured.
 */
final class ConfiguredHierarchy
{
    /**
     * The base every converted class ends up on (directly, or through its converted
     * project base).
     */
    public const TARGET_BASE = 'Laratesto\Testing\LaravelTestCase';

    /**
     * Literal short form of {@see TARGET_BASE}: within one run an extends name may be
     * a short name whose scope snapshot cannot resolve it — the literal match keeps
     * the recognition order-independent.
     */
    public const TARGET_BASE_SHORT = 'LaravelTestCase';

    /**
     * The trait a class converted in `target_mode=trait` carries instead of an
     * extends: LaravelBaseClassRector adds it when it removes the direct framework
     * parent, so the later rules must recognize it as the same membership.
     */
    public const TARGET_TRAIT = 'Laratesto\Testing\InteractsWithLaravel';

    /**
     * Literal short form of {@see TARGET_TRAIT}: within one run a trait-use name may
     * be a short name whose scope snapshot cannot resolve it — the literal match keeps
     * the recognition order-independent.
     */
    public const TARGET_TRAIT_SHORT = 'InteractsWithLaravel';

    private BaseClassConfiguration $configuration;

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
    ) {
        $this->configuration = BaseClassConfiguration::defaults();
    }

    /**
     * Replaces the whole state with the given fresh configuration; keys absent from
     * it fall back to the defaults, so a previous configuration can never leak.
     */
    public function adopt(BaseClassConfiguration $configuration): void
    {
        $this->configuration = $configuration;
    }

    /**
     * Source base classes eligible for conversion, exactly as configured.
     *
     * @return list<non-empty-string>
     */
    public function sourceBases(): array
    {
        return $this->configuration->laravelBases;
    }

    /**
     * Extends names that put a class inside the recognized hierarchy: the configured
     * source bases plus every already-migrated target form. Matched through Rector's
     * name resolution (`isNames()`), so imports resolve exactly like the previous
     * per-rule literal lists.
     *
     * @return list<non-empty-string>
     */
    public function hierarchyBaseNames(): array
    {
        return [
            ...$this->configuration->laravelBases,
            self::TARGET_BASE,
            self::TARGET_BASE_SHORT,
        ];
    }

    /**
     * Trait names that put a class inside the recognized hierarchy: the target trait
     * in both spellings. Laravel source traits (RefreshDatabase and friends) are
     * deliberately absent — carrying one is a construct outside the hierarchy, not a
     * membership, so an unrelated helper class stays fail-closed.
     *
     * @return list<non-empty-string>
     */
    private function hierarchyTraitNames(): array
    {
        return [self::TARGET_TRAIT, self::TARGET_TRAIT_SHORT];
    }

    /**
     * Whether the class directly uses the target trait of `target_mode=trait`.
     */
    public function usesTargetTrait(Class_ $class): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof TraitUse) {
                continue;
            }

            foreach ($statement->traits as $trait) {
                if ($this->nodeNameResolver->isNames($trait, $this->hierarchyTraitNames())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The one recognition predicate of the whole pipeline: a class is inside the
     * convertible hierarchy when its direct extends resolves to a configured source
     * base or an already-migrated target base ({@see hierarchyBaseNames()}), or when
     * it directly uses the target trait — the form LaravelBaseClassRector leaves
     * behind in `target_mode=trait` before the later rules process the class.
     */
    public function recognizesTestClass(Class_ $class): bool
    {
        if ($class->extends !== null
            && $this->nodeNameResolver->isNames($class->extends, $this->hierarchyBaseNames())) {
            return true;
        }

        return $this->usesTargetTrait($class);
    }
}
