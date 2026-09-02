<?php

declare(strict_types=1);

namespace Tests\Feature;

final class UnsafeCustomParentTest extends \Tests\TestCase
{
    public function test_unsafe_parent(): void {}
}
-----
<?php

declare(strict_types=1);

namespace Tests\Feature;

/* laratesto-residual(code=CLASS_UNSAFE_HIERARCHY, rule=Laratesto\Rector\Rules\LaravelBaseClassRector, severity=manual): parent Tests\TestCase cannot be resolved - migrate the project base in the same run */
final class UnsafeCustomParentTest extends \Tests\TestCase
{
    public function test_unsafe_parent(): void {}
}
