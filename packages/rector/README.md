# laratesto-rector

[Rector](https://getrector.com/) rules to migrate existing Laravel PHPUnit test suites
to [Laratesto](https://github.com/ichinya/laratesto) (Testo-based).

## Install

```bash
composer require --dev ichinya/laratesto-rector --with-all-dependencies
```

## Usage

Create `rector.php` in your project root:

```php
use Laratesto\Rector\Set\LaratestoRectorSetList;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->import(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO);
};
```

Then review first, apply second — rollback is git:

```bash
vendor/bin/rector process tests --dry-run   # see the plan + residuals
git diff                                    # review
vendor/bin/rector process tests             # apply in-place
vendor/bin/rector process tests             # second run must be a no-op
```

## What you get

One set, `LARAVEL_PHPUNIT_TO_LARATESTO`, internally composes:

1. Upstream `PHPUNIT_TO_TESTO` set from [`testo/bridge-rector`](https://github.com/php-testo/bridge-rector) — generic PHPUnit constructs.
2. Laravel-specific rules (`Tests\TestCase` conversion, database traits → attributes,
   lifecycle hooks) — added incrementally.

## Autoloading note

Class-hierarchy detection needs your project's test classes autoloadable. The standard
Laravel setup already provides it; if your `Tests\TestCase` lives outside the default
map, extend bootstrap accordingly in `rector.php` (`$parameters`/bootstrap options).

## Status

MVP in progress: skeleton + set composition (this release state); conversion rules landing next.
Unsupported constructions stay untouched — find them by `laratesto-residual` markers once landed.
