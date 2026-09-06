<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

abstract class ProjectCase extends TestCase
{
    protected function assertTruth(bool $value): void
    {
    }
}

final class ParentProjectTest extends ProjectCase
{
    public function test_project_parent(): void
    {
        parent::assertTruth(true);
    }
}
