# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versions follow [Semantic Versioning](https://semver.org/).

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

### Requirements

- PHP 8.3+, Laravel 13, Testo `^0.10.42`.

[0.1.0]: https://github.com/ichinya/laratesto/releases/tag/v0.1.0
