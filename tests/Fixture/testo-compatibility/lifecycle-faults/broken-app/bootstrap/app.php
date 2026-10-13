<?php

declare(strict_types=1);

$application = new \Illuminate\Foundation\Application();
$application->instance('fault.partial', true);
\Illuminate\Support\Facades\Facade::setFacadeApplication($application);
\Illuminate\Queue\Queue::createPayloadUsing(static fn() => ['fault_hook' => true]);
throw new \RuntimeException('primary bootstrap file fault');
