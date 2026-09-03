<?php

declare(strict_types=1);

namespace Laratesto\Rector\Configuration;

/**
 * The one authoritative recognition state of the configured Laravel test hierarchy.
 *
 * Every Laravel-specific rule ({@see \Laratesto\Rector\Rules\LaravelBaseClassRector},
 * LaravelDatabaseTraitsRector, LaravelSourceCompatibleCallsRector,
 * LaravelResidualDetectionRector) asks this service which extends names put a class
 * inside the hierarchy, instead of keeping its own literal list. A custom
 * `base_classes` override therefore applies to the whole pipeline at once: a class
 * outside the configured bases is never partially mutated by a rule that still
 * recognizes an old hard-coded default.
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

    private BaseClassConfiguration $configuration;

    public function __construct()
    {
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
}
