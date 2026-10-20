<?php

declare(strict_types=1);

final class RunnerSmokeTest extends \PHPUnit\Framework\TestCase
{
    public function testTheRealPhpunitRunnerAndItsAssertionsRemainAvailable(): void
    {
        self::assertTrue(\Composer\InstalledVersions::isInstalled('phpunit/phpunit'));
        self::assertStringNotContainsString('/Shim/', str_replace('\\', '/', (new \ReflectionClass(\PHPUnit\Framework\TestCase::class))->getFileName()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('active LaravelPlugin');
        \Laratesto\Laratesto::http();
    }
}
