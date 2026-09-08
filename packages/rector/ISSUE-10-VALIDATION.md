# Issue #10 migration validation

Validated on 2026-09-08 against the final implementation. This report summarizes local executable checks; hosted CI results belong to the pull request.

## Library verification

The complete root suite passed on PHP 8.4.1 with Laravel 12, PHPUnit 11.5.56 and Testo 0.10.42:

- 626 scenarios: 623 passed, 3 skipped, no failures or errors; exit 0.
- 4,946 assertions; approximately 22 minutes.
- JSON and console output include all 626 scenarios. The default JUnit exporter reports 456 ordinary tests and omits 170 passing `rector-fixture` scenarios.
- The three skips are an intentional setup-skip fixture and Windows-inapplicable checks for POSIX deny-read permissions and dangling symlinks.
- Syntax checks passed for all 55 changed PHP files. Composer validation and whitespace checks passed; the adapter retains its intentional warning for the exact Rector pin.

Run the repository suite with:

```sh
composer install
composer test
```

Additional regressions cover import collisions, commented database traits, inherited application initialization, assertion and exception-message semantics, facade/mailable assertions, named stub arguments and return-generation policy, output-buffer cleanup, deferred Artisan timing, and safe retention of unsupported hierarchies.

## Consumer verification

An isolated copy of an existing application used PHP 8.5.10, Laravel 13.25.0, PHPUnit 13.3.1, Testo 0.10.45 and Rector 2.6.2. Both local Laratesto packages were installed from the same source snapshot through Composer path repositories, as documented in [README.md](README.md).

Independent application and process-fixture defects were corrected in the consumer before the final comparison. These corrections are not part of the Laratesto implementation or attributed to its migration rules. Generated tests were produced automatically from the corrected source.

| Check | Result |
| --- | --- |
| Full source PHPUnit run | 1,272 scenarios: 1,264 passed, 8 target-database skips; no failures/errors |
| Public `laratesto:migrate-rector --path=tests --apply --allow-dirty` | Exit 0; no manual residuals |
| Full migrated Testo run | Same 142 test files and 1,272 scenarios: 1,264 passed and the same 8 skips |
| Exact discovery and outcome comparison | No excluded, missing, extra or differing scenarios |
| Separate target-database runs | All eight skipped scenarios passed in both runners on disposable MySQL 8.4.4 |
| Combined coverage of the two environments | All 1,272 unique scenarios passed in each runner |
| Second actual Rector apply with a new private cache and `--clear-cache` | Exit 0; all 209 files remained byte-identical |
| Migrated PHP syntax | All 195 PHP files passed |
| Installed runtime and adapter | All 68 production PHP files matched the verified source snapshot |

The ordinary suite used SQLite `:memory:`. The target suite used a separate MySQL data directory and database with InnoDB and `REPEATABLE-READ`; server identity was verified before setup and the disposable server was shut down after testing.

The eight MySQL checks are separate executions, not passes within the SQLite run. Local verification does not claim hosted Linux/Windows matrix results, coverage-threshold qualification, or a published adapter release. The old generated consumer suite was preserved separately and was not counted as the new migration result.
