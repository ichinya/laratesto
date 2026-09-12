# Agent Notes

## PHPUnit removal (issue #12)

The repository no longer requires `phpunit/phpunit` in the root `composer.json`.
Runtime compatibility for Laravel framework assertions, `createStub()`, and
deferred Artisan assertions is provided by `src/Shim/PhpUnit/`.

### Test commands

Run the three suites that must pass without `phpunit/phpunit`:

```bash
vendor/bin/testo run --suite=Unit
vendor/bin/testo run --suite=Laravel
vendor/bin/testo run --suite=Rector
```

### Composer

- Do not re-add `phpunit/phpunit` to root `require` or `require-dev`.
- After changing `composer.json` autoload sections, run:
  `composer dump-autoload`
- `composer validate --no-check-publish` must pass.

### Rector source discovery without PHPUnit

Rector's static reflection needs explicit autoload paths when PHPUnit is
absent. The canonical list lives in
`packages/rector/src/Configuration/AutoloadPaths.php` and is used by:

- `packages/rector/config/sets/laravel-phpunit-to-laratesto.php`
- `packages/rector/tests/Support/FrontierPipeline.php`
- `src/Overrides/Testo/Bridge/Rector/Testing/Internal/RectorRunner.php`

The `FrontierPipeline` builder merges these package paths with its own corpus
paths because `RectorConfig::configure()->withAutoloadPaths()` overwrites the
set's autoload configuration.

### Testo Rector override

`src/Overrides/Testo/Bridge/Rector/Testing/Internal/RectorRunner.php` replaces
the Testo Bridge Rector fixture runner. It is registered via
`composer.json` `autoload.classmap` so it takes precedence over the vendor
class.

### JUnit fixtures

`tests/Fixture/issue10/junit/*.xml` are hand-curated compatibility fixtures for
migration integration tests. They record expected test/failure counts for the
migrated issue10 fixtures because those fixtures are now executed with Testo
instead of PHPUnit's JUnit reporter.

### Temporary files

Debug scripts named `test-*.php` in the repository root are not committed; the
Laravel/Rector suites create runtime files under `storage/framework/testing`.
