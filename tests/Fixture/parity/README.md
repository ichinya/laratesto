# Parity fixture corpus (ticket 07)

Source corpus for the final integration/parity gate. These files are PHPUnit-style INPUTS for the migration, not
runnable tests of this repository — they live outside every Testo suite location and are picked up only by the scenario
runners.

## Layout

- `supported/` — must migrate fully and run green under Testo (no PHPUnit runner):
    - `TestCase.php` — project base with a safe custom bootstrap;
    - `LifecycleCountersTest.php` — exactly-once setup/test/teardown probe;
    - `DatabaseStrategiesTest.php` — RefreshDatabase plain/clean, DatabaseTransactions;
    - `TruncationSelectionTest.php` — two connections, literal table selection;
    - `HttpAndArtisanTest.php` — common HTTP/response/session/Artisan signatures and a supported `TestResponse`
      typehint.
- `unsupported/` — must stay semantically unchanged with exactly one marker each:
  dynamic DB options + custom hooks, fourth JSON argument, `Uri` object, fakes, unknown response API, interactive
  Artisan chains.

## Scenario plan (ticket 07)

| Scenario       | Corpus            | Steps                                                                                                                                                                                                          |
|----------------|-------------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| A — raw Rector | both              | hashes → dry-run (hashes stable, diff has markers, no report file) → apply → scoped diff → repeat apply (no-op) → fix residuals → `testo run`                                                                  |
| B — Artisan    | both, fresh reset | dry-run (table + deterministic JSON, one residual set, exit 0/2) → `--apply` → repeat `--apply` (sources/markers/report unchanged) → green run; repeat with `--base-class`/`--target-mode`/`--path`/`--report` |
| C — safety     | supported         | dirty processed blocks; dirty outside paths does not; non-Git blocks apply only; `--allow-dirty` warns; scoped `git restore --source=HEAD -- <paths>`                                                          |

Traceability: scenario A is automated end-to-end by
`Laratesto\Rector\Tests\Integration\ParityMigrationE2eTest` (real Rector apply over
both corpora → byte-identical second apply → real `testo run` of the migrated
supported corpus); scenario C's Git/path guards are covered by
`Laratesto\Tests\Integration\MigrateRectorCommandGuardsTest` and the command
e2e in `Laratesto\Tests\Integration\MigrateRectorCommandTest`.
