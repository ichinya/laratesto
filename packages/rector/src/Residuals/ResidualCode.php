<?php

declare(strict_types=1);

namespace Laratesto\Rector\Residuals;

/**
 * The single catalog of residual codes a migration can emit.
 *
 * Every marker, every report entry and the README contract must use exactly these
 * codes — {@see \Laratesto\Rector\Tests\Residuals\ResidualCodeContractTest} enforces
 * the docs-to-code equality, so a code documented here is a code that can actually
 * appear, and a code the rules emit is a code documented to the user.
 */
final class ResidualCode
{
    /**
     * The class hierarchy cannot be proven safe: unresolvable parent, project base
     * outside the processed paths or outside the configured base_classes, cyclic or
     * over-deep chain, trait adaptations, unsupported bootstrap hooks.
     */
    public const CLASS_UNSAFE_HIERARCHY = 'CLASS_UNSAFE_HIERARCHY';

    /**
     * A lifecycle override (setUp/tearDown) has a shape the Laratesto hooks cannot
     * express: static, parameterized, private, abstract, or conflicting hooks.
     */
    public const LIFECYCLE_UNSUPPORTED = 'LIFECYCLE_UNSUPPORTED';

    /**
     * A Laravel database trait is present but its configuration cannot be proven
     * lossless: dynamic options, multiple strategy traits, custom hooks, referenced
     * option properties, unsupported literal shapes.
     */
    public const DATABASE_UNSUPPORTED_CONFIGURATION = 'DATABASE_UNSUPPORTED_CONFIGURATION';

    /**
     * An HTTP call signature has no automatic Laratesto equivalent: extra or unpacked
     * arguments, named arguments, server-variable overrides.
     */
    public const HTTP_UNSUPPORTED_SIGNATURE = 'HTTP_UNSUPPORTED_SIGNATURE';

    /**
     * A TestResponse API used by the class has no automatic Laratesto equivalent.
     */
    public const RESPONSE_UNSUPPORTED_API = 'RESPONSE_UNSUPPORTED_API';

    /**
     * Interactive or chained Artisan testing calls cannot be reproduced safely.
     */
    public const ARTISAN_INTERACTION_UNSUPPORTED = 'ARTISAN_INTERACTION_UNSUPPORTED';

    /**
     * Laravel test doubles (Mail::fake(), Queue::fake(), ...) have no automatic
     * Laratesto equivalent and must be migrated by hand.
     */
    public const LARAVEL_FAKE_UNSUPPORTED = 'LARAVEL_FAKE_UNSUPPORTED';

    /**
     * A Laravel testing construct (assertions, fakes, facade calls) was found in a
     * class outside the recognized Laravel test hierarchy.
     */
    public const LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY = 'LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY';

    /**
     * @return list<non-empty-string>
     */
    public static function all(): array
    {
        return [
            self::CLASS_UNSAFE_HIERARCHY,
            self::LIFECYCLE_UNSUPPORTED,
            self::DATABASE_UNSUPPORTED_CONFIGURATION,
            self::HTTP_UNSUPPORTED_SIGNATURE,
            self::RESPONSE_UNSUPPORTED_API,
            self::ARTISAN_INTERACTION_UNSUPPORTED,
            self::LARAVEL_FAKE_UNSUPPORTED,
            self::LARAVEL_CONSTRUCT_OUTSIDE_HIERARCHY,
        ];
    }
}
