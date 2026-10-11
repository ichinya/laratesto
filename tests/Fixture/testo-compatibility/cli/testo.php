<?php

declare(strict_types=1);

return new \Testo\Application\Config\ApplicationConfig(suites: [
    new \Testo\Application\Config\SuiteConfig(name: 'Compatibility', location: [dirname(__DIR__).'/smoke/cases']),
]);
