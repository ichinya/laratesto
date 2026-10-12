<?php

declare(strict_types=1);

namespace Testo\Bridge\Rector\Testing\Internal;

use Internal\Path;
use Testo\Common\Messenger;

/** The optional migration package owns the Rector-version-specific harness. */
final readonly class RectorRunner
{
    private \Laratesto\Rector\Testing\RectorRunner $runner;

    public function __construct(Messenger $messenger, array $rules, array $sets = [])
    {
        $this->runner = new \Laratesto\Rector\Testing\RectorRunner($messenger, $rules, $sets);
    }

    public function assertConverts(Path $fixturePath): void
    {
        $this->runner->assertConverts($fixturePath);
    }
}
