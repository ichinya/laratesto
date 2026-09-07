<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Set;

use Laratesto\Rector\Set\LaratestoRectorSetList;
use Testo\Assert;
use Testo\Test;

/**
 * Docs-to-code contract: the README must name the real public set entry point,
 * the same one MIGRATING.md imports, and it must resolve to an existing set
 * config file — not to an invented class-like name a user cannot import.
 */
final class LaratestoRectorSetListContractTest
{
    private const DOCUMENTED_SET = 'Laratesto\Rector\Set\LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO';

    #[Test]
    public function readmeNamesTheRealSetEntryPoint(): void
    {
        $readme = (string) \file_get_contents(dirname(__DIR__, 4) . '/README.md');

        Assert::true(
            \str_contains($readme, self::DOCUMENTED_SET),
            'README must reference the real set constant a user can import.'
        );
        Assert::false(
            \str_contains($readme, 'Laravel\\PhpunitToLaratesto'),
            'README must not name a set class that does not exist.'
        );
    }

    #[Test]
    public function documentedSetResolvesToAnExistingConfigFile(): void
    {
        $setFile = \constant(self::DOCUMENTED_SET);

        Assert::same(LaratestoRectorSetList::LARAVEL_PHPUNIT_TO_LARATESTO, $setFile);
        Assert::true(\is_string($setFile) && \is_file($setFile), 'The documented set must point to an existing config file.');
    }
}
