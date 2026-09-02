<?php

declare(strict_types=1);

namespace Laratesto\Rector\Configuration;

/**
 * Deterministic per-call configuration of {@see \Laratesto\Rector\Rules\LaravelBaseClassRector}.
 *
 * Rector registers a rule class once and re-applies `configure()` on top of the same
 * singleton instance — for every configured rule on every run. Deriving the whole
 * state from a fresh value object per call (instead of mutating fields in place) makes
 * a re-configuration reproducible: keys absent from the array fall back to the
 * defaults instead of leaking whatever a previous configuration set.
 */
final class BaseClassConfiguration
{
    /** @var list<non-empty-string> */
    public const DEFAULT_BASE_CLASSES = [
        'Tests\TestCase',
        'Illuminate\Foundation\Testing\TestCase',
    ];

    public const DEFAULT_TARGET_MODE = 'base_class';

    /**
     * @param list<non-empty-string> $laravelBases Source base classes eligible for conversion.
     * @param 'base_class'|'trait' $targetMode
     */
    public function __construct(
        public readonly array $laravelBases,
        public readonly string $targetMode,
    ) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_BASE_CLASSES, self::DEFAULT_TARGET_MODE);
    }

    /**
     * @param array<array-key, mixed> $configuration
     * @throws \InvalidArgumentException On any unsupported key, list entry or mode.
     */
    public static function fromArray(array $configuration): self
    {
        $laravelBases = self::DEFAULT_BASE_CLASSES;
        $targetMode = self::DEFAULT_TARGET_MODE;

        if (array_key_exists('base_classes', $configuration)) {
            $bases = $configuration['base_classes'];

            if (! is_array($bases) || $bases === []) {
                throw new \InvalidArgumentException('base_classes must be a non-empty list of class names.');
            }

            $normalized = [];
            foreach ($bases as $base) {
                if (! is_string($base) || trim($base, " \\t\\n\\r\\0\\x0B\\") === '') {
                    throw new \InvalidArgumentException('base_classes must contain only non-empty class names.');
                }

                $normalized[] = trim($base, " \\t\\n\\r\\0\\x0B\\");
            }

            $laravelBases = array_values(array_unique($normalized));
        }

        if (array_key_exists('target_mode', $configuration)) {
            $mode = $configuration['target_mode'];

            if (! is_string($mode) || ! in_array($mode, ['base_class', 'trait'], true)) {
                throw new \InvalidArgumentException('target_mode must be "base_class" or "trait".');
            }

            $targetMode = $mode;
        }

        return new self($laravelBases, $targetMode);
    }
}
