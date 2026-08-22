# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
