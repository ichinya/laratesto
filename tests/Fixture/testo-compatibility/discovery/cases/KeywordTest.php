<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\Compatibility\Discovery;

use Testo\Assert;
use Testo\Test;
use function Laratesto\Tests\Fixture\Compatibility\event;

function keywordArgument(string $function): string
{
    return $function;
}

final class KeywordTest
{
    #[Test]
    public function keywordArgumentsAndMembersAreNotDeclarations(): void
    {
        $object = (object) ['function' => 'kept'];
        event('included');
        Assert::same('kept', keywordArgument(function: $object->function));
    }
}
