# Optional property testing consumer

This installation is separate from the package's core acceptance suites. It pins
Testo 0.10.56 and `rasuvaeff/property-testing-testo` 1.0.0. Its committed consumer
lock selects property-testing-core 1.2.0 and Laravel 13.35.0; the root lock remains
Laravel 13.31.0. `ichinya/laratesto` is a runtime path dependency pointing to the
current working tree. The consumer explicitly autoloads its fixture application
classes because the dependency's `autoload-dev` is not exported.

From this directory:

```sh
composer install
composer check-platform-reqs
php verify.php
```

For the additional Line coverage check, use PHP with active Xdebug coverage:

```sh
XDEBUG_MODE=coverage LARATESTO_PBT_REQUIRE_COVERAGE=1 php verify.php
```

In PowerShell:

```powershell
$env:XDEBUG_MODE = 'coverage'
$env:LARATESTO_PBT_REQUIRE_COVERAGE = '1'
php verify.php
```

`verify.php` launches real Testo children with a 90-second process timeout. Each
property limits runs, discards, accepted shrink steps and run/phase duration.
Each child has a separate temporary regression corpus containing synthetic inputs;
the verifier removes only its own directory. No plugin registration is added:
`#[Property]` self-registers. The positive property combines an explicit float
generator with `auto: true` and `@param int<1, 40>`.

The fixture deliberately falsifies one property and skips every trial of another.
Acceptance requires five aggregate tests (three passed, one failed, one skipped),
exit 1, native JSON/JUnit agreement, and assertion accounting derived from every
generated/shrink body. It reproduces the shrunk counterexample using the emitted
seed and path in a second child. Successful verification therefore exits 0 despite
the expected child's failure. Fixed seed `7382910` currently produces 18 trials,
75 assertions, shrink path `value:0`, and minimal failing value `5`.

One application and one Laravel setup/teardown pair surround each **property**.
Generated, example, shrink and replay inputs share its container, database, auth,
cache and clock. `sharedStateIsObserved` demonstrates that reuse explicitly.
The other properties reset their trial mutations in the body; do this for every
trial or use stateless properties. `setUpLaravel()` alone does not provide trial
isolation. The following ordinary test verifies a fresh clean application.

The driver check executes both example branches of `ProbeMath`, then random
inputs, and inspects real merged Clover Line coverage. It requires the driver
when requested and does not turn missing coverage into a passing empty check.
This consumer is additional evidence and is not a mandatory root dependency or
an expansion of the package's CI matrix.
