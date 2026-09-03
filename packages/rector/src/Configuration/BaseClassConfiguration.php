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
 * defaults instead of leaking whatever a previous configuration set. Configured
 * base classes are stored in canonical `Tests\ApiTestCase` form — see
 * {@see self::canonicalBaseClass()} for the accepted spellings.
 */
final class BaseClassConfiguration
{
    /** @var list<non-empty-string> */
    public const DEFAULT_BASE_CLASSES = [
        'Tests\TestCase',
        'Illuminate\Foundation\Testing\TestCase',
    ];

    /**
     * One PHP label: a namespace segment such as `Tests` or `ApiTestCase`.
     */
    private const LABEL_PATTERN = '[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*';

    /**
     * A canonical name: labels separated by single backslashes, no leading or
     * trailing separator.
     */
    private const CLASS_NAME_PATTERN = '/^(?:' . self::LABEL_PATTERN . ')(?:\\\\' . self::LABEL_PATTERN . ')*$/';

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
     * The canonical `Tests\ApiTestCase` form of one user-supplied base class name.
     *
     * Surrounding whitespace is trimmed, forward slashes are canonicalized to
     * backslashes, and a leading separator is accepted zero or exactly one
     * time: the documented spellings `Tests/ApiTestCase`, `\Tests\ApiTestCase`,
     * `/Tests/ApiTestCase` and `' Tests\ApiTestCase '` all canonicalize to
     * `Tests\ApiTestCase`. Anything that cannot be a PHP class name is rejected:
     * repeated or mixed leading separators (`//Tests`, two leading backslashes
     * such as in `\\Tests`, or a leading slash followed by a leading
     * backslash), repeated internal separators, a trailing separator, dots,
     * colons, embedded whitespace, other invalid label characters, empty input.
     *
     * @return non-empty-string
     * @throws \InvalidArgumentException When the name is empty or malformed.
     */
    public static function canonicalBaseClass(string $base): string
    {
        $canonical = \str_replace('/', '\\', \trim($base));

        // Zero or exactly one leading separator; a leading slash spells the same
        // fully-qualified form. Repeated or mixed leading separators would leave
        // an empty namespace segment and are rejected below.
        if (\str_starts_with($canonical, '\\')) {
            $canonical = \substr($canonical, 1);
        }

        if ($canonical === '') {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid base class %s: expected a non-empty class name like "Tests\ApiTestCase".',
                \var_export($base, true),
            ));
        }

        if (\preg_match(self::CLASS_NAME_PATTERN, $canonical) !== 1) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid base class %s: malformed class name, expected namespace segments like "Tests\ApiTestCase".',
                \var_export($base, true),
            ));
        }

        return $canonical;
    }

    /**
     * Canonical form of every entry, deduplicated. The order of first
     * occurrence is preserved so a generated configuration stays deterministic.
     *
     * @param array<array-key, mixed> $bases
     * @return list<non-empty-string>
     * @throws \InvalidArgumentException On a non-string, empty or malformed entry.
     */
    public static function canonicalBaseClasses(array $bases): array
    {
        $canonical = [];

        foreach ($bases as $base) {
            if (! \is_string($base)) {
                throw new \InvalidArgumentException('base_classes must contain only non-empty class names.');
            }

            $canonical[] = self::canonicalBaseClass($base);
        }

        return \array_values(\array_unique($canonical));
    }

    /**
     * @param array<array-key, mixed> $configuration
     * @throws \InvalidArgumentException On any unsupported key, list entry or mode.
     */
    public static function fromArray(array $configuration): self
    {
        $laravelBases = self::DEFAULT_BASE_CLASSES;
        $targetMode = self::DEFAULT_TARGET_MODE;

        if ($unknownKeys = array_diff(array_keys($configuration), ['base_classes', 'target_mode'])) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported configuration key(s): %s. Only "base_classes" and "target_mode" are supported.',
                implode(', ', array_map('strval', $unknownKeys)),
            ));
        }

        if (array_key_exists('base_classes', $configuration)) {
            $bases = $configuration['base_classes'];

            if (! is_array($bases) || $bases === []) {
                throw new \InvalidArgumentException('base_classes must be a non-empty list of class names.');
            }

            $laravelBases = self::canonicalBaseClasses($bases);
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
