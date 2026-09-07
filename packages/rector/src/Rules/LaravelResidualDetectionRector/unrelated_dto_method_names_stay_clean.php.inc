<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;

final class OwnDto
{
    public function assertDownload(): void {}
    public function withoutExceptionHandling(): void {}
}

final class DtoControlTest extends TestCase
{
    public function test_dto(): void
    {
        $dto = new OwnDto;
        $alias = $dto;
        $alias->assertDownload();
        $dto->withoutExceptionHandling();
    }
}
