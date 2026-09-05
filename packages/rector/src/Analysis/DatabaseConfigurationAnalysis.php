<?php

declare(strict_types=1);

namespace Laratesto\Rector\Analysis;

use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Stmt\TraitUse;

/**
 * @internal Immutable result of the database trait all-or-nothing preflight.
 */
final readonly class DatabaseConfigurationAnalysis
{
    /**
     * @param array<non-empty-string, Expr> $options
     * @param list<PropertyProperty> $removableProperties The individual property items
     *        that move into the attribute — sibling options on the same multi-property
     *        declaration stay untouched.
     * @param list<Attribute> $removableAttributes
     * @param bool $mergeIntoAncestor The class re-declares the same database trait a
     *        resolved project ancestor already carries, with a provably identical
     *        configuration: the trait use converts into NO attribute here because the
     *        class inherits the ancestor's single one (Laravel's class_uses_recursive
     *        deduplicated the trait to one behavior before the migration).
     */
    public function __construct(
        public ?string $sourceTrait = null,
        public ?string $targetAttribute = null,
        public ?TraitUse $traitUse = null,
        public array $options = [],
        public array $removableProperties = [],
        public array $removableAttributes = [],
        public ?string $unsupportedReason = null,
        public bool $mergeIntoAncestor = false,
    ) {}

    public function hasSourceTrait(): bool
    {
        return $this->sourceTrait !== null;
    }

    public function supported(): bool
    {
        return $this->hasSourceTrait() && $this->unsupportedReason === null;
    }
}
