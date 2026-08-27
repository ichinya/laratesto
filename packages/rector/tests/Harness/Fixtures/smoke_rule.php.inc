<?php

declare(strict_types=1);

final class HarnessSmokeProbe
{
    public function smoke(): void
    {
    }
}
-----
<?php

declare(strict_types=1);

final class HarnessSmokeProbe
{
    public function smoked(): void
    {
    }
}
