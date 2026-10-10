<?php

declare(strict_types=1);

return new class(dirname(__DIR__)) extends \Illuminate\Foundation\Application {
    public function make($abstract, array $parameters = [])
    {
        if ($abstract === \Illuminate\Contracts\Console\Kernel::class) {
            \Illuminate\Support\Facades\Facade::setFacadeApplication($this);
            throw new \RuntimeException('partial kernel bootstrap');
        }
        return parent::make($abstract, $parameters);
    }
};
