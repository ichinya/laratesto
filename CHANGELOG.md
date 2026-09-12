# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- PHPUnit compatibility shim: added missing `assertIsArray`, `assertArrayHasKey`,
  `assertNotTrue`, `assertInstanceOf` and `assertSameSize` so Laravel testing
  helpers (including `inertiajs/inertia-laravel`) work without
  `phpunit/phpunit` installed (issue #18).

## [0.7.2] - 2026-09-12

### Added

- Bundled PHPUnit compatibility shim (PR #16, issue #12): provides
  `PHPUnit\Framework\TestCase`, `Assert`, `MockObject` stubs and metadata
  classes when `phpunit/phpunit` is not installed, delegating assertions to
  Testo and keeping `createStub()` and deferred Artisan assertions working
  for consumers.
- Canonical Rector autoload path discovery and a Testo Rector runner override
  via classmap.
- CI runs the self-test suite (`composer test`) on a self-hosted runner over
  the locked Laravel 13 dependencies with PHP 8.3 through 8.5; pre-GA PHP 8.6
  legs run as non-gating signal, since the locked dependencies still cap at
  PHP 8.5 (issue #6).

### Changed

- **Breaking**: dropped Laravel 12 support (issue #6). The package now
  requires `laravel/framework ^13.0` and PHP `>=8.3` — Laravel 13's own PHP
  floor — and the lock resolves against Composer's PHP 8.3 platform. The
  standalone rector package keeps Testo's `>=8.2` floor.
- Bumped `testo/testo` requirement from `^0.10.42` to `^0.10.45` and pinned
  the lockfile to the new release (PR #13).

### Fixed

- Normalized line endings in the PHPUnit migrator input so CRLF (and bare
  CR) sources no longer cause duplicate `LaravelTestCase` import errors on
  Windows checkouts (PR #15).
- Kept the `DatabaseTruncation` probe free of PHP 8.5 deprecation noise when
  exercising the pinned null-selector contract (PR #15).

### Removed

- Removed `phpunit/phpunit` from root `require-dev`; the bundled PHPUnit
  compatibility shim replaces the dev dependency for the test suite and
  Rector integration.

## [0.7.1] - 2026-09-08

### Fixed

- `artisan test` no longer takes over Collision's `test` command by default:
  the unified runner is always available as `artisan laratesto:test`, and
  replacing `test` is opt-in through the publishable
  `laratesto.replace_test_command` config flag.
- PHPUnit migration and runtime parity (issue #10, PR #11):
  - project bootstrap and initialization semantics are preserved for migrated
    suites, including state cleanup between tests;
  - assertion semantics match PHPUnit and Laravel, including facade
    assertions, mailable assertions and exception expectations;
  - response helpers keep Laravel behavior, including registered response
    macros;
  - stub policies and console output capture follow PHPUnit behavior, with
    and without return values;
  - pending Artisan commands execute at Laravel's deferred timing, and the
    Rector rules rewrite them consistently;
  - conversions without a faithful mechanical translation stay explicit
    residuals instead of silently changing behavior.
- README documents installing the matching runtime and Rector path packages.

## [0.7.0] - 2026-09-07

### Fixed

- Empty `RefreshDatabase` and `DatabaseTruncation` connection selections preserve
  process-wide migration state, so a later default refresh still performs its
  first migration against an existing schema.
- `composer test` disables Composer's outer process timeout for the test script.
- `LaravelResponse::assertHeader()` compares expected values case-insensitively
  again, matching Laravel while still rejecting differences beyond case.
- Fluent `assertJson()` callbacks support `Conditionable` and `Macroable`, with
  assertion failures preserved.
- Nested database transaction scopes restore each connection's previous
  transaction manager as well as the application binding, including on cleanup
  failure.
- Rector migration safety and report handling:
  - guarded apply refuses untracked or ignored PHP files in the processed scope
    as well as tracked modifications; `--allow-dirty` explicitly bypasses the
    Git guard and requires an independent backup;
  - unreadable source files and generated-config failures produce a friendly
    exit `1` and leave the previous report untouched;
  - dry-run reconstruction handles zero-count insertion anchors and empty
    files, and rejects hunks beyond EOF without PHP warnings;
  - residual scanning recognizes only whole canonical PHP marker comments,
    excluding quoted examples in strings, heredocs and surrounding comment prose;
  - trait-provided test discovery, source API dependencies and lifecycle hooks
    block class conversion with residuals, including Laravel trait-basename
    hooks; processed descendants with unsafe traits preserve their shared base;
  - file/glob, base-rule and required database-rule exclusions cannot leave a
    partially converted hierarchy without active database isolation;
  - inherited database strategies check descendant options, hooks and writes
    against the ancestor attribute and preserve the shared source strategy when
    equivalence cannot be established; unchanged inherited defaults remain safe;
  - unsupported `parent::` calls receive `HTTP_UNSUPPORTED_SIGNATURE`, including
    assertions left by the upstream rules and lifecycle calls outside the exact
    statement shape the base rule rewrites;
  - dynamic method names fail the HTTP preflight before signature lookup,
    including `$this`, `self::`, `static::` and nullsafe calls;
  - `TestResponse` static factories and unknown/nullable or multi-argument
    construction preserve their source type with `RESPONSE_UNSUPPORTED_API`;
    construction with one native-type-proven Symfony `Response` still converts,
    including inferred variables, typed parameters and named `response:` values;
  - retained Pending Artisan commands receive a residual across later statements,
    loop iterations and catch/finally; the check accounts for Laravel's deferred
    execution even after an assertion and preserves immediate chains and terminal
    assignments with literal expectations when commands do not escape through
    references or static/global storage;
  - Laravel-like method names on proven unrelated native DTO receivers no longer
    produce false residuals; unknown, broad-object and response-capable union
    receivers retain conservative markers;
  - direct application-property writes, references, `isset`/`unset`,
    destructuring/foreach targets and arguments passed to unresolved or
    by-reference signatures preserve the source class with a residual, while
    ordinary reads and proven by-value calls remain convertible;
  - framework database strategies hidden in project-trait composition stay
    visible and residual-marked, including across files and the lazy strategy;
  - own/inherited Laravel `Seed`/`Seeder` attributes lift only with a positively
    identified installed Laravel 13 framework, preserving precedence and literal
    class names across files; Laravel 12/unknown contexts retain source metadata
    with a database residual, as do unsupported metadata shapes;
  - live constructor-promoted database options on the class, project ancestors and
    used traits require manual migration, including when an inherited constructor
    can overwrite a child's literal redeclaration;
  - database option reads through method-local `$this` aliases now participate in
    the class/ancestor/composed-trait reader checks, including chained/reference
    assignments, closures and conditional/coalescing expressions; unrelated DTO
    and unshadowed private-slot controls remain supported;
  - the truncation contract's inert connection double accepts Laravel 13's
    optional `fetchUsing` argument on both `select()` and `cursor()`, while
    remaining compatible with Laravel 12.
- The ancestor-static database option probe is now discovered and checks the
  actual PHP read result instead of remaining an unexecuted contract.
- Migration documentation scopes database hook blockers to the selected
  strategy and explains report preservation, partial apply, rollback limits and
  per-connection migration/seeding for hand-written truncation attributes.
- Rector migration of Laravel PHPUnit test suites (PR #8 fix plan):
  - the project base `Tests\TestCase` is converted exactly once — it keeps its
    custom helpers and setup, and every descendant keeps extending it while
    gaining only lifecycle, HTTP and database conversions;
  - descendant safety is proven across the whole extends chain (resolvable,
    inside the processed paths, free of blockers, terminating at the framework
    base or an already-migrated base); anything unprovable fails closed with a
    `CLASS_UNSAFE_HIERARCHY` residual instead of a half-migrated hierarchy;
  - rule configuration is deterministic: re-configuring the shared rule
    instance can no longer leak a previous `base_classes`/`target_mode`;
  - one configured hierarchy state: a `base_classes` override governs every
    Laravel rule at once (base class, database traits, HTTP calls, residual
    detection) through the shared `ConfiguredHierarchy` service, so a base
    dropped by the override can no longer be partially mutated by a rule that
    still recognized a hard-coded default, and unknown configuration keys are
    rejected as documented;
  - multi-property database options split losslessly — sibling properties on
    the same declaration survive;
  - database option properties supplied by non-Laravel project traits stay
    visible: traits flatten their composition tree into every consuming class,
    so a directly or ancestor-used trait carrying `$seed`, `$tablesToTruncate`
    and friends is live for the trait machinery even though a class-level scan
    never sees it — the preflight now resolves used traits (same-file first,
    then reflection) with their full composition trees for the class and the
    applicable ancestor chain and fails the conversion closed with
    `DATABASE_UNSUPPORTED_CONFIGURATION`; an unresolvable used trait blocks as
    well, while static members and ancestor-private members (invisible to the
    descendant) and trait uses of nested class-likes are correctly inert;
  - a database trait re-declared on a descendant of a class that already uses
    the same trait no longer produces a second class attribute (Laravel's
    `class_uses_recursive` had collapsed the duplication to one behavior, while
    Testo's hierarchy-wide reflection then ran the interceptor twice): the
    duplicate merges into the ancestor's single attribute when the effective
    option configuration is provably identical — same names and literal values,
    or nothing on both sides — including across files, through intermediate
    project bases and against an ancestor that already carries the migrated
    attribute; a diverging configuration fails closed with an actionable
    `DATABASE_UNSUPPORTED_CONFIGURATION` residual, and different database
    traits keep stacking;
  - ancestor option properties shadowed by the converted class no longer block
    the database trait conversion: the Laravel traits read their options through
    `property_exists($this, ...)` plus `$this->option`, and PHP resolves both to
    the most-derived declaration, so when the class itself declares the option
    with a supported literal only that value was ever live — the conversion now
    lifts it into the attribute and ignores same-named ancestor declarations at
    every chain depth, while an ancestor option the class does not redeclare
    still fails the conversion closed;
  - a project reader above the converting class bounds that relaxation: while
    the class's own shadowing declaration is the value an ancestor method, an
    ancestor trait method or one of the class's own project trait methods
    observes, removing it silently repoints those reads at whatever the
    hierarchy resolves next. The lift now fails closed with an actionable
    `DATABASE_UNSUPPORTED_CONFIGURATION` residual for any live reader, except
    the proven inert shape where the reader's own scope declares the option
    privately (a more-derived redeclare never shadows that private slot, so it
    answers identically before and after the lift); statically named instance
    reads count, dynamic property names fail closed, and a same-value inherited
    literal still receives a stable residual instead of a value-proof engine;
  - residual markers reconcile: changed constructs refresh their reason and
    unchanged runs stay byte-identical; remove resolved markers together with
    the manual fix, because markers left in source remain reportable;
  - a file-level response-gate block no longer strands an otherwise convertible
    class: when an unsafe sibling blocks the file-wide `TestResponse` swap, the
    safe class is marked with a `RESPONSE_UNSUPPORTED_API` residual naming the
    blocker instead of silently keeping `TestResponse` declarations that would
    TypeError against the runtime `Laratesto\Testing\LaravelResponse`;
  - the file-level response gate classifies siblings with the same local-class
    snapshot as the main analysis: a same-file `TestResponse` subclass receiver
    (unprovable through static reflection, which cannot see the file being
    processed) no longer reads as safe during the preflight, so the file-wide
    swap stays blocked with the actionable blocker residual instead of
    stranding the un-migrated receiver class;
  - markers emitted by several rules for the same code merge deterministically:
    one marker comment per code, one contribution per rule sorted by rule,
    byte-identical on re-runs — no rule overwrites or loses another rule's reason;
  - the eight residual codes live in one `ResidualCode` catalog and the README
    documents exactly that catalog (enforced by test);
  - dry-run residual reports scan the reconstructed new-side content, so
    markers already on disk are reported and soon-to-disappear markers are not;
    report lines sort numerically and deduplicate;
  - `laratesto:migrate-rector` is decomposed into injectable services and fails
    closed: realpath path containment, Git porcelain `-z` status with checked
    exit code and a re-check before the Rector process, machine-JSON schema
    validation, apply-only exit-0 matrix and no scratch files.
  - external processes are bounded and fail friendly: a hung Git guard call or
    Rector run is killed after 10 minutes and reported as a failure; a process
    that cannot start (a missing Git binary, a missing working directory) or a
    timed-out child becomes a failed outcome with the reason in stderr, so the
    command answers with a friendly error and exit 1 instead of a raw Symfony
    Process exception; the explicit missing-Rector-binary check and the stderr
    diagnostics stay in place.
  - the database override preflight scopes every hook to the one active
    trait's own machinery, including the hooks inherited from the shared
    `CanConfigureMigrationCommands` concern: dead hooks of a different
    strategy (for example `beforeRefreshingDatabase()` next to
    `DatabaseTruncation` or `connectionsToTransact()` next to
    `DatabaseMigrations`) no longer emit false
    `DATABASE_UNSUPPORTED_CONFIGURATION` residuals, while overrides of live
    machinery the flat list never covered (`migrateDatabases()`, the
    in-memory refresh helpers, `tableExistsIn()`) and case-variant method
    declarations still fail the conversion closed, as does a used project
    trait (directly or through its composition tree) supplying a live hook —
    an `insteadof` adaptation on the project trait's own use statement can
    select the project hook body while the source trait's use statement
    stays adaptation-free, so adaptation shapes fail closed as a whole;
  - `laratesto:migrate-rector --base-class` and rule `base_classes` values are
    canonicalized safely: the documented forward-slash spelling
    (`Tests/ApiTestCase`), a single leading separator and surrounding whitespace
    normalize to the canonical `Tests\ApiTestCase`, canonical duplicates are
    removed, and empty or malformed names (an empty segment, a trailing
    separator, an invalid label character) fail the run with a friendly error
    and exit 1 before anything executes; the README examples are unambiguous
    across POSIX and Windows shells.
  - RefreshDatabase's `$connectionsToTransact` no longer lifts into the
    attribute's `connections` argument: the source trait always runs its single
    `migrate:fresh` against the default connection (the selection only picks
    the per-test transaction scope), while the attribute repoints
    `migrate:fresh` at every selected connection, so a named, multiple or
    empty selection would silently wipe a different schema and skip the
    default migration — the lift now fails closed with an actionable
    `DATABASE_UNSUPPORTED_CONFIGURATION` residual, and only a provably
    default-only selection (a single `null` entry) converts, into the
    attribute's bare form;
  - DatabaseTruncation's `$connectionsToTruncate` no longer lifts into the
    attribute's `connections` argument: the source trait scopes only the table
    truncation with the selection, while its first `migrate:fresh` and every
    later `db:seed` run against the default connection — the attribute would
    migrate and seed every selected connection instead, so a named, multiple,
    duplicate or empty selection stays an unconverted
    `DATABASE_UNSUPPORTED_CONFIGURATION` residual, and only a provably
    default-only selection (a single `null` entry) converts, into the
    attribute's bare form;
  - DatabaseTruncation's `tablesToTruncate`/`exceptTables` maps keyed by
    connection name no longer lift into the attribute's `tables`/`exceptTables`
    arguments: the trait looks them up with the null default selector, misses
    every literal name and falls back to the whole map (whose array values
    match no table), so the trait truncates nothing — or excludes nothing
    beyond the migrations table — while the attribute resolves the selector to
    the connection name and applies the listed tables; keyed maps stay an
    unconverted `DATABASE_UNSUPPORTED_CONFIGURATION` residual, flat table
    lists and the empty list keep converting;
- `DatabaseTransactions` restores and re-caches in-memory connections around
  the transaction, so a `:memory:` schema no longer vanishes between tests.

### Added

- End-to-end migration gate: the parity fixture corpus is migrated by the real
  Rector binary, asserted marker-by-marker, verified byte-identical on a second
  apply and executed green under a real `testo run`.
- CI workflow: locked Laravel 12 on Linux + Windows with PHP 8.2/8.3/8.4,
  plus separately resolved Laravel 13 on both systems with PHP 8.3/8.4;
  Composer validation, an installed-framework/platform assertion, whitespace
  check and the full test suite including the migration gate. Laravel 13 jobs
  retain their installed vendor after restoring the committed Composer manifests
  for source-contract checks; the root PHP 8.2 compatibility lock stays unchanged.

### Changed

- The minimum PHP version now follows Testo (`>=8.2`); Laravel 12 provides the
  PHP 8.2 compatibility branch while Laravel 13 remains supported on PHP 8.3+.
  The Rector package no longer uses PHP 8.3-only typed class constants.
- The rector package is consumed as a symlinked path repository; the duplicate
  `Laratesto\Rector\` root autoload mapping is gone, and a packaging test pins
  the standalone package's own autoload contract.

## [0.6.9] - 2026-08-22

### Added

- `php artisan test` now runs Testo first and then one detected legacy runner:
  Pest is preferred over PHPUnit to avoid executing Pest's PHPUnit-backed suite
  twice. Shared selectors, runner-specific arguments, aggregate exit status and
  optional stage-level fail-fast behavior are supported.
- `php artisan laratesto:migrate-phpunit` safely converts common PHPUnit unit
  and Laravel feature tests to the `tests/Testo` layout. It supports dry-run,
  explicit target directories, conflict protection and opt-in source removal.
- `setUpLaravel()` / `tearDownLaravel()` lifecycle hooks around every Laravel
  test, including teardown execution when the test pipeline fails.
- PHPUnit's `DatabaseMigrations` trait migrates to the native
  `#[DatabaseMigrations]` attribute.
- Laravel-compatible request, response, session, cookie, redirect, JSON, view,
  time-travel and Artisan assertion helpers needed by migrated suites.

### Fixed

- Database attributes now prepare state before `setUpLaravel()` and roll it back
  after `tearDownLaravel()`, matching Laravel's PHPUnit lifecycle.
- `SkipTest` and `CancelTest` thrown from Laravel lifecycle hooks retain their
  Testo statuses instead of being reported as aborted pipeline failures.

### Safety

- Migration refuses data providers, dependencies, coverage/group attributes,
  PHPUnit mocks and constraints, regex exception expectations and other
  constructs without a faithful mechanical Testo conversion.

## [0.4.0] - 2026-08-19

### Added

- `withoutMiddleware()` on `InteractsWithLaravel` / `LaravelTestCase` — disables
  all middleware or specific classes, matching Laravel's helper.
- Mockery integration: `testo/bridge-mockery` is now exercised by the test suite
  (facade mocking + container reset between tests) and documented in the README.
- README documents the honest picture of facade fakes: setup works without
  PHPUnit, but their `assert*` methods require `phpunit/phpunit` as a library,
  plus workarounds for `withoutExceptionHandling`, `withoutVite`/`withoutMix`
  and `$this->seed()`.

## [0.3.0] - 2026-08-19

### Added

- `LaravelResponse` assertion parity with Laravel's `TestResponse` (all
  PHPUnit-free, on top of Testo assertions):
  - `assertJsonPath(string $path, mixed $value)` — dot-path traversal,
    strict same, closure expectations
  - `assertJsonStructure(array $structure)` — recursive key-structure check
    with `'*'` wildcard
  - `assertRedirect(?string $uri = null)` — redirect status check
    (201/301/302/303/307/308) + optional `Location` comparison
  - `assertDontSee(string|array)` — inverse of `assertSee`
  - `assertHeaderMissing(string)` — inverse of `assertHeader`
  - status shortcuts: `assertCreated`, `assertBadRequest`, `assertUnauthorized`,
    `assertForbidden`, `assertNotFound`, `assertUnprocessable`
- Failure messages include the JSON path and expected/actual values.

## [0.2.0] - 2026-08-19

### Added

- Authentication helpers on `InteractsWithLaravel` / `LaravelTestCase`:
  `actingAs`, `actingAsGuest`, `assertAuthenticated`, `assertGuest`,
  `assertAuthenticatedAs`.
- Database assertions: `assertDatabaseHas`, `assertDatabaseMissing`,
  `assertDatabaseCount` (optionally per-connection).
- Session assertions: `assertSessionHas`, `assertSessionMissing`,
  `assertSessionHasErrors`, plus a `session()` accessor.
- `assertExitCode` for Artisan commands.
- Automatic cookie bridging between requests within one test: `Set-Cookie`
  headers from each response are carried into the next request, so session-based
  flows (login → redirect → follow-up) work out of the box.
- Fixture application now ships session/auth configuration, web routes and an
  in-memory `UserProvider`, exercised by the new integration tests.

## [0.1.2] - 2026-08-19

### Fixed

- README quick-start used `basePath: dirname(__DIR__)`, which resolves outside
  the project for the canonical root-level `testo.php`; the example is now
  `basePath: __DIR__` (#2).

### Changed

- Bridge failures (application boot, instance injection, `RefreshDatabase`,
  `DatabaseTransactions`, state cleanup) now return aborted test results that
  carry the original exception instead of throwing: Testo wraps thrown
  interceptor exceptions into an opaque `PipelineFailure` and hides the root
  cause in the failure output (#2).

## [0.1.1] - 2026-08-19

### Changed

- Dependency constraints narrowed to the versions the bridge is actually
  tested against: `php ^8.3`, `laravel/framework ^13.0`,
  `testo/testo ^0.10.42` (1.x has no stable release yet).

## [0.1.0] - 2026-08-19

First public release.

### Added

- `LaravelPlugin` for Testo: boots the framework around every test via a
  `TestRunInterceptor`, with guaranteed static-state cleanup afterwards.
- Fresh Laravel application per test; `.env.testing` support through the
  standard `APP_ENV` mechanism; runtime config overrides.
- `LaravelTestCase` base class and `InteractsWithLaravel` trait:
  container access, HTTP requests through the HTTP kernel
  (`get`, `post`, `postJson`, `sendRequest`), Artisan command execution.
- PHPUnit-free `LaravelResponse` wrapper with Testo assertions
  (`assertOk`, `assertStatus`, `assertHeader`, `assertJson`, `assertSee`).
- `#[RefreshDatabase]` (migrate:fresh, optional seeding) and
  `#[DatabaseTransactions]` (transaction wrap with rollback) attributes.
- Self-hosted test suite on a fixture Laravel application.

[0.7.1]: https://github.com/ichinya/laratesto/releases/tag/v0.7.1
[0.7.0]: https://github.com/ichinya/laratesto/releases/tag/v0.7.0

[0.6.9]: https://github.com/ichinya/laratesto/releases/tag/v0.6.9
[0.4.0]: https://github.com/ichinya/laratesto/releases/tag/v0.4.0
[0.3.0]: https://github.com/ichinya/laratesto/releases/tag/v0.3.0
[0.2.0]: https://github.com/ichinya/laratesto/releases/tag/v0.2.0
[0.1.2]: https://github.com/ichinya/laratesto/releases/tag/v0.1.2
[0.1.1]: https://github.com/ichinya/laratesto/releases/tag/v0.1.1
[0.1.0]: https://github.com/ichinya/laratesto/releases/tag/v0.1.0
