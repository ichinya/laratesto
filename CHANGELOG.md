# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

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
  - residual markers reconcile: changed constructs refresh their reason,
    resolved constructs lose the marker, unchanged runs stay byte-identical;
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
  - `laratesto:migrate-rector --base-class` and rule `base_classes` values are
    canonicalized safely: the documented forward-slash spelling
    (`Tests/ApiTestCase`), a single leading separator and surrounding whitespace
    normalize to the canonical `Tests\ApiTestCase`, canonical duplicates are
    removed, and empty or malformed names (an empty segment, a trailing
    separator, an invalid label character) fail the run with a friendly error
    and exit 1 before anything executes; the README examples are unambiguous
    across POSIX and Windows shells.
- `DatabaseTransactions` restores and re-caches in-memory connections around
  the transaction, so a `:memory:` schema no longer vanishes between tests.

### Added

- End-to-end migration gate: the parity fixture corpus is migrated by the real
  Rector binary, asserted marker-by-marker, verified byte-identical on a second
  apply and executed green under a real `testo run`.
- CI workflow: Linux + Windows, PHP 8.2 + 8.3 + 8.4, composer validation, whitespace
  check and the full test suite including the migration gate.

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

[0.6.9]: https://github.com/ichinya/laratesto/releases/tag/v0.6.9
[0.4.0]: https://github.com/ichinya/laratesto/releases/tag/v0.4.0
[0.3.0]: https://github.com/ichinya/laratesto/releases/tag/v0.3.0
[0.2.0]: https://github.com/ichinya/laratesto/releases/tag/v0.2.0
[0.1.2]: https://github.com/ichinya/laratesto/releases/tag/v0.1.2
[0.1.1]: https://github.com/ichinya/laratesto/releases/tag/v0.1.1
[0.1.0]: https://github.com/ichinya/laratesto/releases/tag/v0.1.0
