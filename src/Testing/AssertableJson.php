<?php

declare(strict_types=1);

namespace Laratesto\Testing;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Tappable;
use Testo\Assert;

use function Illuminate\Support\enum_value;

/**
 * Fluent, assertable view over a decoded JSON payload.
 *
 * Testo-native port of `Illuminate\Testing\Fluent\AssertableJson` with the same
 * interaction semantics (`where`/`has`/`missing`/`count`/scopes, `etc()`,
 * `interacted()`); every assertion uses `Testo\Assert`, so no PHPUnit is
 * involved.
 *
 * @api
 */
class AssertableJson implements Arrayable
{
    use Tappable;

    /**
     * The list of interacted properties.
     *
     * @var list<string>
     */
    protected array $interacted = [];

    /**
     * The properties in the current scope.
     *
     * @var array<array-key, mixed>
     */
    private array $props;

    /**
     * The "dot" path to the current scope.
     */
    private ?string $path;

    /**
     * Create a new fluent, assertable JSON data instance.
     *
     * @param array<array-key, mixed> $props
     * @param non-empty-string|null $path
     */
    protected function __construct(array $props, ?string $path = null)
    {
        $this->path = $path;
        $this->props = $props;
    }

    /**
     * Create a new instance from an array.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }

    /**
     * Assert that the prop is of the expected size.
     *
     * @param int|non-empty-string $key
     */
    public function count(int|string $key, ?int $length = null): static
    {
        if (\is_null($length)) {
            $path = $this->dotPath();

            Assert::count(
                (array) $this->prop(),
                (int) $key,
                $path
                    ? \sprintf('Property [%s] does not have the expected size.', $path)
                    : 'Root level does not have the expected size.',
            );

            return $this;
        }

        Assert::count(
            (array) $this->prop((string) $key),
            $length,
            \sprintf('Property [%s] does not have the expected size.', $this->dotPath((string) $key)),
        );

        return $this;
    }

    /**
     * Assert that the prop size is between a given minimum and maximum.
     */
    public function countBetween(int|string $min, int|string $max): static
    {
        $path = $this->dotPath();

        $prop = (array) $this->prop();

        Assert::true(
            \count($prop) >= (int) $min,
            $path
                ? \sprintf('Property [%s] size is not greater than or equal to [%s].', $path, $min)
                : \sprintf('Root level size is not greater than or equal to [%s].', $min),
        );

        Assert::true(
            \count($prop) <= (int) $max,
            $path
                ? \sprintf('Property [%s] size is not less than or equal to [%s].', $path, $max)
                : \sprintf('Root level size is not less than or equal to [%s].', $max),
        );

        return $this;
    }

    /**
     * Ensure that the given prop exists.
     *
     * @param int|string $key
     * @param int|Closure|null $length
     */
    public function has(string|int $key, int|Closure|null $length = null, ?Closure $callback = null): static
    {
        if (\is_int($key) && \is_null($length)) {
            return $this->count($key);
        }

        Assert::true(
            Arr::has($this->prop(), (string) $key),
            \sprintf('Property [%s] does not exist.', $this->dotPath((string) $key)),
        );

        $this->interactsWith((string) $key);

        if (! \is_null($callback)) {
            return $this->has((string) $key, static function (self $scope) use ($length, $callback) {
                return $scope
                    ->tap(static function (self $scope) use ($length): void {
                        if (! \is_null($length) && ! \is_callable($length)) {
                            $scope->count((string) $length);
                        }
                    })
                    ->first($callback)
                    ->etc();
            });
        }

        if (\is_callable($length)) {
            return $this->scope((string) $key, $length);
        }

        if (! \is_null($length)) {
            return $this->count((string) $key, $length);
        }

        return $this;
    }

    /**
     * Assert that all of the given props exist.
     *
     * @param array<string|int, mixed>|string $key
     */
    public function hasAll(array|string $key): static
    {
        $keys = \is_array($key) ? $key : \func_get_args();

        foreach ($keys as $prop => $count) {
            if (\is_int($prop)) {
                $this->has((string) $count);
            } else {
                $this->has($prop, $count);
            }
        }

        return $this;
    }

    /**
     * Assert that at least one of the given props exists.
     *
     * @param array<string|int, mixed>|string $key
     */
    public function hasAny(array|string $key): static
    {
        $keys = \is_array($key) ? $key : \func_get_args();

        Assert::true(
            Arr::hasAny($this->prop(), $keys),
            \sprintf('None of properties [%s] exist.', \implode(', ', array_map(strval(...), $keys))),
        );

        foreach ($keys as $key) {
            $this->interactsWith((string) $key);
        }

        return $this;
    }

    /**
     * Assert that none of the given props exist.
     *
     * @param array<string|int, mixed>|string $key
     */
    public function missingAll(array|string $key): static
    {
        $keys = \is_array($key) ? $key : \func_get_args();

        foreach ($keys as $prop) {
            $this->missing((string) $prop);
        }

        return $this;
    }

    /**
     * Assert that the given prop does not exist.
     */
    public function missing(string $key): static
    {
        Assert::false(
            Arr::has($this->prop(), $key),
            \sprintf('Property [%s] was found while it was expected to be missing.', $this->dotPath($key)),
        );

        return $this;
    }

    /**
     * Asserts that the property matches the expected value.
     *
     * @param string $key
     * @param mixed|Closure $expected
     */
    public function where(string $key, mixed $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if ($expected instanceof Closure) {
            Assert::true(
                $expected(\is_array($actual) ? new Collection($actual) : $actual),
                \sprintf('Property [%s] was marked as invalid using a closure.', $this->dotPath($key)),
            );

            return $this;
        }

        $expected = $expected instanceof Arrayable
            ? $expected->toArray()
            : enum_value($expected);

        $this->ensureSorted($expected);
        $this->ensureSorted($actual);

        Assert::same(
            $actual,
            $expected,
            \sprintf('Property [%s] does not match the expected value.', $this->dotPath($key)),
        );

        return $this;
    }

    /**
     * Asserts that the property does not match the expected value.
     *
     * @param string $key
     * @param mixed|Closure $expected
     */
    public function whereNot(string $key, mixed $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if ($expected instanceof Closure) {
            Assert::false(
                $expected(\is_array($actual) ? new Collection($actual) : $actual),
                \sprintf('Property [%s] was marked as invalid using a closure.', $this->dotPath($key)),
            );

            return $this;
        }

        $expected = $expected instanceof Arrayable
            ? $expected->toArray()
            : enum_value($expected);

        $this->ensureSorted($expected);
        $this->ensureSorted($actual);

        Assert::notSame(
            $actual,
            $expected,
            \sprintf(
                'Property [%s] contains a value that should be missing: [%s, %s]',
                $this->dotPath($key),
                $key,
                self::stringify($expected),
            ),
        );

        return $this;
    }

    /**
     * Asserts that the property is null.
     */
    public function whereNull(string $key): static
    {
        $this->has($key);

        Assert::null(
            $this->prop($key),
            \sprintf(
                'Property [%s] should be null.',
                $this->dotPath($key),
            ),
        );

        return $this;
    }

    /**
     * Asserts that the property is not null.
     */
    public function whereNotNull(string $key): static
    {
        $this->has($key);

        Assert::notNull(
            $this->prop($key),
            \sprintf(
                'Property [%s] should not be null.',
                $this->dotPath($key),
            ),
        );

        return $this;
    }

    /**
     * Asserts that all properties match their expected values.
     *
     * @param array<string, mixed> $bindings
     */
    public function whereAll(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            $this->where($key, $value);
        }

        return $this;
    }

    /**
     * Asserts that the property is of the expected type.
     *
     * @param string|list<string> $expected
     */
    public function whereType(string $key, string|array $expected): static
    {
        $this->has($key);

        $actual = $this->prop($key);

        if (! \is_array($expected)) {
            $expected = \explode('|', $expected);
        }

        Assert::contains(
            $expected,
            \strtolower(\gettype($actual)),
            \sprintf('Property [%s] is not of expected type [%s].', $this->dotPath($key), \implode('|', $expected)),
        );

        return $this;
    }

    /**
     * Asserts that all properties are of their expected types.
     *
     * @param array<string, string|list<string>> $bindings
     */
    public function whereAllType(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            $this->whereType($key, $value);
        }

        return $this;
    }

    /**
     * Asserts that the property contains the expected values.
     *
     * @param string $key
     * @param mixed $expected
     */
    public function whereContains(string $key, mixed $expected): static
    {
        $actual = new Collection(
            $this->prop($key) ?? $this->prop(),
        );

        $missing = (new Collection(Arr::wrap($expected)))
            ->map(static fn ($search): mixed => enum_value($search))
            ->reject(static function ($search) use ($key, $actual): bool {
                if ($actual->containsStrict($key, $search)) {
                    return true;
                }

                return $actual->containsStrict($search);
            });

        if ($missing->whereInstanceOf(Closure::class)->isNotEmpty()) {
            Assert::true(
                $missing->isEmpty(),
                \sprintf(
                    'Property [%s] does not contain a value that passes the truth test within the given closure.',
                    $key,
                ),
            );
        } else {
            Assert::true(
                $missing->isEmpty(),
                \sprintf(
                    'Property [%s] does not contain [%s].',
                    $key,
                    \implode(', ', \array_map(self::stringify(...), $missing->values()->toArray())),
                ),
            );
        }

        return $this;
    }

    /**
     * Instantiate a new "scope" at the path of the given key.
     */
    protected function scope(string $key, Closure $callback): static
    {
        $props = $this->prop($key);
        $path = $this->dotPath($key);

        Assert::true(
            \is_array($props),
            \sprintf('Property [%s] is not scopeable.', $path),
        );

        $scope = new static($props, $path);
        $callback($scope);
        $scope->interacted();

        return $this;
    }

    /**
     * Instantiate a new "scope" on the first child element.
     */
    public function first(Closure $callback): static
    {
        $props = $this->prop();

        $path = $this->dotPath();

        Assert::true(
            \is_array($props) && $props !== [],
            $path === ''
                ? 'Cannot scope directly onto the first element of the root level because it is empty.'
                : \sprintf('Cannot scope directly onto the first element of property [%s] because it is empty.', $path),
        );

        $key = \array_keys($props)[0];

        $this->interactsWith((string) $key);

        return $this->scope((string) $key, $callback);
    }

    /**
     * Instantiate a new "scope" on each child element.
     */
    public function each(Closure $callback): static
    {
        $props = $this->prop();

        $path = $this->dotPath();

        Assert::true(
            \is_array($props) && $props !== [],
            $path === ''
                ? 'Cannot scope directly onto each element of the root level because it is empty.'
                : \sprintf('Cannot scope directly onto each element of property [%s] because it is empty.', $path),
        );

        foreach (\array_keys($props) as $key) {
            $this->interactsWith((string) $key);

            $this->scope((string) $key, $callback);
        }

        return $this;
    }

    /**
     * Compose the absolute "dot" path to the given key.
     */
    protected function dotPath(string $key = ''): string
    {
        if (\is_null($this->path)) {
            return $key;
        }

        return \rtrim(\implode('.', [$this->path, $key]), '.');
    }

    /**
     * Marks the property as interacted.
     */
    protected function interactsWith(string $key): void
    {
        $prop = Str::before($key, '.');

        if (! \in_array($prop, $this->interacted, true)) {
            $this->interacted[] = $prop;
        }
    }

    /**
     * Asserts that all properties have been interacted with.
     */
    public function interacted(): void
    {
        Assert::same(
            \array_diff(\array_keys($this->prop()), $this->interacted),
            [],
            $this->path
                ? \sprintf('Unexpected properties were found in scope [%s].', $this->path)
                : 'Unexpected properties were found on the root level.',
        );
    }

    /**
     * Disables the interaction check.
     */
    public function etc(): static
    {
        $this->interacted = \array_keys($this->prop());

        return $this;
    }

    /**
     * Dumps the props in the current scope (or the given one).
     */
    public function dump(?string $prop = null): static
    {
        \print_r($this->prop($prop));

        return $this;
    }

    /**
     * Dumps the props in the current scope (or the given one) and exits.
     */
    public function dd(?string $prop = null): never
    {
        \print_r($this->prop($prop));

        exit(1);
    }

    /**
     * Retrieve a prop within the current scope using "dot" notation.
     */
    protected function prop(?string $key = null): mixed
    {
        return Arr::get($this->props, $key);
    }

    /**
     * Get the instance as an array.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->props;
    }

    /**
     * Ensures that all properties are sorted the same way, recursively.
     */
    protected function ensureSorted(mixed &$value): void
    {
        if (! \is_array($value)) {
            return;
        }

        foreach ($value as &$arg) {
            $this->ensureSorted($arg);
        }

        \ksort($value);
    }

    private static function stringify(mixed $value): string
    {
        return \is_scalar($value) || $value === null
            ? (string) \var_export($value, true)
            : \gettype($value);
    }
}
