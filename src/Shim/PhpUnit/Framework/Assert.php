<?php

declare(strict_types=1);

namespace PHPUnit\Framework;

use Testo\Assert as TestoAssert;
use Testo\Assert\State\Assertion\AssertionException;

/**
 * Minimal PHPUnit assertion shim backed by Testo\Assert.
 *
 * This class is only used when phpunit/phpunit is not installed. It provides
 * enough of the PHPUnit assertion API for Laravel's testing fakes, response
 * assertions and PendingCommand to function under Testo.
 */
abstract class Assert
{
    private static int $count = 0;

    /**
     * Returns the number of assertions performed by this shim.
     */
    final public static function getCount(): int
    {
        return self::$count;
    }

    final public static function resetCount(): void
    {
        self::$count = 0;
    }

    final protected static function incrementCount(): void
    {
        ++self::$count;
    }

    final public static function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message);
    }

    final public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true($condition, $message));
    }

    final public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::false($condition, $message));
    }

    final public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::same($actual, $expected, $message));
    }

    final public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::equals($actual, $expected, $message));
    }

    final public static function assertEqualsCanonicalizing(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static function () use ($expected, $actual, $message): void {
            $normalize = static fn (mixed $value): mixed => \is_array($value) ? self::sortRecursive($value) : $value;
            TestoAssert::equals($normalize($actual), $normalize($expected), $message);
        });
    }

    final public static function assertEqualsIgnoringCase(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static function () use ($expected, $actual, $message): void {
            TestoAssert::same(\strtolower((string) $actual), \strtolower((string) $expected), $message);
        });
    }

    final public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::notSame($actual, $expected, $message));
    }

    final public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::notEquals($actual, $expected, $message));
    }

    final public static function assertNull(mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::null($actual, $message));
    }

    final public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::notNull($actual, $message));
    }

    final public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true(empty($actual), $message));
    }

    final public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true(!empty($actual), $message));
    }

    final public static function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::count($haystack, $expectedCount, $message));
    }

    final public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::contains($haystack, $needle, $message));
    }

    final public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::iterable($haystack)->notContains($needle, $message));
    }

    final public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::string($haystack)->contains($needle, $message));
    }

    final public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::string($haystack)->notContains($needle, $message));
    }

    final public static function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true($actual > $expected, $message));
    }

    final public static function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true($actual >= $expected, $message));
    }

    final public static function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true($actual < $expected, $message));
    }

    final public static function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::wrap(static fn () => TestoAssert::true($actual <= $expected, $message));
    }

    final public static function assertThat(mixed $value, Constraint\Constraint $constraint, string $message = ''): void
    {
        self::wrap(static function () use ($value, $constraint, $message): void {
            $constraint->evaluate($value, $message);
        });
    }

    final public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        if (!\is_array($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that ' . self::describe($actual) . ' is of type "array".');
        }

        ++self::$count;
    }

    final public static function assertArrayHasKey(mixed $key, mixed $array, string $message = ''): void
    {
        if (!\is_array($array) && !$array instanceof \ArrayAccess) {
            self::fail($message !== '' ? $message : 'Failed asserting that ' . self::describe($array) . ' is an array or ArrayAccess.');
        }

        $has = $array instanceof \ArrayAccess ? $array->offsetExists($key) : \array_key_exists($key, $array);

        if (!$has) {
            self::fail($message !== '' ? $message : 'Failed asserting that an array has the key ' . self::describe($key) . '.');
        }

        ++self::$count;
    }

    final public static function assertNotTrue(mixed $condition, string $message = ''): void
    {
        if ($condition === true) {
            self::fail($message !== '' ? $message : 'Failed asserting that ' . self::describe($condition) . ' is not true.');
        }

        ++self::$count;
    }

    final public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        if (!\class_exists($expected) && !\interface_exists($expected) && !\enum_exists($expected)) {
            throw new \InvalidArgumentException(\sprintf('Class or interface "%s" does not exist.', $expected));
        }

        if (!$actual instanceof $expected) {
            self::fail($message !== '' ? $message : 'Failed asserting that ' . self::describe($actual) . ' is an instance of class "' . $expected . '".');
        }

        ++self::$count;
    }

    final public static function assertSameSize(mixed $expected, mixed $actual, string $message = ''): void
    {
        $expectedCount = self::countValue($expected);
        $actualCount = self::countValue($actual);

        if ($expectedCount !== $actualCount) {
            self::fail($message !== '' ? $message : \sprintf('Failed asserting that two arrays have the same size (%d vs %d).', $expectedCount, $actualCount));
        }

        ++self::$count;
    }

    private static function describe(mixed $value): string
    {
        if ($value === null || \is_scalar($value)) {
            return \var_export($value, true);
        }

        if (\is_object($value)) {
            return 'object(' . \get_class($value) . ')';
        }

        if (\is_array($value)) {
            return 'array(' . \count($value) . ')';
        }

        if (\is_resource($value)) {
            return 'resource';
        }

        return \gettype($value);
    }

    private static function countValue(mixed $value): int
    {
        if ($value instanceof \Generator) {
            throw new \LogicException('Cannot count a Generator.');
        }

        if (\is_array($value) || $value instanceof \Countable) {
            return \count($value);
        }

        if ($value instanceof \Traversable) {
            return \iterator_count($value);
        }

        throw new \InvalidArgumentException('Value must be an array, Countable, or iterable.');
    }

    /**
     * @param callable(): void $assertion
     */
    private static function wrap(callable $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionException $e) {
            throw new ExpectationFailedException($e->getMessage());
        }

        ++self::$count;
    }

    /**
     * @param array<array-key, mixed> $array
     * @return array<array-key, mixed>
     */
    private static function sortRecursive(array $array): array
    {
        foreach ($array as $key => $value) {
            if (\is_array($value)) {
                $array[$key] = self::sortRecursive($value);
            }
        }

        if (\array_is_list($array)) {
            \sort($array);
        } else {
            \ksort($array);
        }

        return $array;
    }
}
