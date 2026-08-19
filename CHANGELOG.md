# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

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

[0.1.2]: https://github.com/ichinya/laratesto/releases/tag/v0.1.2
[0.1.1]: https://github.com/ichinya/laratesto/releases/tag/v0.1.1
[0.1.0]: https://github.com/ichinya/laratesto/releases/tag/v0.1.0
