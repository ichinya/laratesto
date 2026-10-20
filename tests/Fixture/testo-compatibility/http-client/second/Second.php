<?php

declare(strict_types=1);

namespace Laratesto\Tests\Fixture\HttpClient;

#[\Testo\Test]
function secondSuite(): void
{
    State::fresh();
    \Laratesto\Laratesto::http()->getJson('/client-probe')->assertJsonPath('application', 'secondary');
}
