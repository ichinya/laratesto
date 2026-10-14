---
name: laratesto-migrate-from-phpunit
description: Migrate a Laravel PHPUnit suite to Laratesto using the optional laratesto-rector package. Use for dry-run/apply conversion, Laravel base or trait modes, manual residual review and before/after test-preservation checks.
---

# Migrate a Laravel PHPUnit suite

Use Testo's `testo-migrate-from-phpunit` skill for general assertion, provider and
attribute conversion when available, or read it in the installed Testo package's
`skills/` directory. This skill adds the Laravel hierarchy/lifecycle conversion
and validation. Keep the user's scope and existing authorization. Preserve test
methods, datasets, assertions and custom helpers; do not remove difficult tests,
mark them skipped or suppress diagnostics to get a passing run.

## Establish the baseline

Inspect installed versions and the project's instructions. The runtime is
`ichinya/laratesto`; the Rector command/rules are a separate optional dependency,
`ichinya/laratesto-rector` (`^0.2.0`, with its supported Rector pin). Installing
only the runtime does not register `laratesto:migrate-rector`. Do not recreate a
local Rector source copy or add PHPUnit to Laratesto's own root dependencies.

Before replacing the consumer's existing runner/dependencies, execute its
PHPUnit/Pest baseline over the exact requested scope and save real JUnit,
stdout/stderr and exit status outside the source paths. For example, for PHPUnit:

```bash
php vendor/bin/phpunit tests/Feature --log-junit=storage/phpunit-before.xml
```

Record discovered methods, per-method dataset counts, outcomes and any existing
failures/skips. A missing or unsuccessful baseline must remain explicit; a green
target alone cannot establish migration parity. Keep a recoverable Git baseline
for the scoped sources and a separate backup for any uncommitted work. Check
custom bases, shared traits and skipped Rector rules before choosing a mode.

## Preview the Laravel conversion

With package discovery enabled, install the optional migration package if
missing and within the requested migration scope:

```bash
composer require --dev ichinya/laratesto-rector:^0.2.0
php artisan laratesto:migrate-rector --path=tests/Feature --report=storage/laratesto-preview.json
```

Default execution is a source dry-run **but writes the report**. Review its diff
and residual table. Exit `0` means no manual residuals, `1` means execution,
configuration, guard or report failure, and `2` means manual residuals. Check the
exit status and that the report came from this run: failures preserve an older
report, and a report's existence does not prove success.

Default `--target-mode=base_class` converts the project base to
`Laratesto\Testing\LaravelTestCase`; descendants retain their parent and custom
helpers. `--target-mode=trait` replaces the foundation parent with
`Laratesto\Testing\InteractsWithLaravel`. Additional local bases can be declared
with `--base-class=Tests/ApiTestCase`; forward slashes avoid shell escaping errors.
Keep base and descendants within the intended conversion scope. Shared PHPUnit
traits and trait setup/teardown may require manual migration; do not rewrite a
shared base in a way that breaks unconverted consumers.

## Apply and resolve residuals

Once the preview and recoverable scope are ready, apply the authorized conversion:

```bash
php artisan laratesto:migrate-rector --path=tests/Feature --apply --report=storage/laratesto-applied.json
```

The command requires clean committed processed sources and rejects staged,
unstaged, untracked or ignored PHP files there. Do not use `--allow-dirty` as a
routine workaround. An apply can leave partial edits even when it fails; inspect
the diff and use the scoped baseline/backup when recovery is needed. Do not
restore unrelated edits.

Laravel setup/teardown become `setUpLaravel()`/`tearDownLaravel()`; database traits
become native Laratesto attributes. Review manual markers/residuals and preserve
unsupported behavior for explicit resolution. A passing dry-run or zero
residuals does not establish runtime correctness. Register LaravelPlugin and
the discovery mode for the migrated paths before running Testo.

## Verify preservation

Run the same scope and save native reports; replace `Laravel` with its actual
suite name:

```bash
php vendor/bin/testo run --suite=Laravel --path=tests/Feature --log-json=storage/testo-after.json --log-junit=storage/testo-after.xml
```

Check the process exit and native JSON `status`, nonzero discovery, outcomes,
assertions, skips and failures. Run the installed upstream
`skills/testo-migrate-from-phpunit/scripts/compare-junit.php` against the baseline
and target JUnit to detect missing methods or changed per-method run counts.
Its exit `0` checks counts, not outcome preservation. Also run this skill's
bundled outcome check (resolve `<skill-dir>` to the directory containing this
SKILL.md, including after copying):

```bash
php <skill-dir>/scripts/compare-outcomes.php storage/phpunit-before.xml storage/testo-after.xml
```

This adds per-method outcome histograms and distinguishes native risky/flaky
statuses. Exit `0` means matching nonempty method/outcome counts, `1` a mismatch,
`2` invalid inputs. It preserves class/method names and aggregates datasets per
method; intentional renames and dataset-label changes require explicit review.
Matching histograms cannot detect assertions removed from a method or outcomes
swapped between its datasets: inspect the source diff and dataset inventory too.
Review every new skip, missing method/dataset, changed outcome and reduced
assertion count. Never accept matching totals or empty failure arrays alone.

Re-run the migrator in dry-run after manual resolution and require a fresh
zero-residual report, then run all affected suites and the project's required
checks. Report baseline/target versions, scope, result differences and unresolved
work. Remove the consumer's legacy runner only after its remaining suites have
been migrated and verified.

See the [independent migration package](https://github.com/ichinya/laratesto-rector)
for its rule ownership and support boundaries; installed source remains the
version-specific authority.
