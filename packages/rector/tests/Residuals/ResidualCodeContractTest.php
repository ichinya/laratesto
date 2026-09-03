<?php

declare(strict_types=1);

namespace Laratesto\Rector\Tests\Residuals;

use Laratesto\Rector\Residuals\ResidualCode;
use Testo\Assert;
use Testo\Test;

/**
 * Docs-to-code contract (PR #8 fix plan, stage 5): the README must list exactly the
 * codes the rules can emit — no documented-but-impossible codes, no emitted-but-
 * undocumented codes.
 */
final class ResidualCodeContractTest
{
    #[Test]
    public function catalogEntriesAreUniqueAndDescribed(): void
    {
        $all = ResidualCode::all();

        Assert::same($all, array_values(array_unique($all)), 'The catalog must not contain duplicates.');
        Assert::true($all !== [], 'The catalog must not be empty.');

        foreach ($all as $code) {
            Assert::true(\preg_match('/^[A-Z][A-Z0-9_]+$/', $code) === 1, "Code $code must be a stable [A-Z0-9_]+ token.");
        }
    }

    #[Test]
    public function readmeListsExactlyTheCatalog(): void
    {
        $readme = (string) \file_get_contents(dirname(__DIR__, 4) . '/README.md');

        \preg_match_all('/^\s*-\s*`([A-Z][A-Z0-9_]+)`\s*$/m', $readme, $matches);

        $documented = array_values(array_unique($matches[1]));

        Assert::same($documented, ResidualCode::all(), 'The README residual catalog must match ResidualCode::all() exactly.');
    }

    #[Test]
    public function migratingTableDocumentsExactlyTheCatalog(): void
    {
        $migrating = (string) \file_get_contents(dirname(__DIR__, 2) . '/MIGRATING.md');

        $documented = [];
        foreach (\explode("\n", $migrating) as $line) {
            if (!\str_starts_with($line, '| `')) {
                continue;
            }

            \preg_match_all('/`([A-Z][A-Z0-9_]+)`/', $line, $matches);
            foreach ($matches[1] as $code) {
                $documented[] = $code;
            }
        }

        Assert::same(
            \count($documented),
            \count(\array_unique($documented)),
            'The MIGRATING residual table must document each code exactly once.'
        );

        $sorted = $documented;
        \sort($sorted);
        $catalog = ResidualCode::all();
        \sort($catalog);

        Assert::same(
            $sorted,
            $catalog,
            'The MIGRATING residual table must document every catalog code — no more, no fewer.'
        );
    }
}
