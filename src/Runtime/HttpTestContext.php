<?php

declare(strict_types=1);

namespace Laratesto\Runtime;

use Illuminate\Contracts\Foundation\Application;
use Laratesto\Testing\HttpClient;

/** @internal A revocable lease for one sequential Laravel test execution. */
final class HttpTestContext
{
    private static ?self $current = null;
    private ?Application $application = null;
    private ?HttpClient $client = null;

    private function __construct() {}

    public static function enter(): self
    {
        if (self::$current !== null) {
            throw new \LogicException('A Laravel test execution is already active; nested executions are not supported.');
        }
        return self::$current = new self();
    }

    public static function current(): self
    {
        return self::$current ?? throw new \LogicException(
            'Laratesto::http() requires an active LaravelPlugin test execution.',
        );
    }

    public function activate(Application $application): void
    {
        if (self::$current !== $this || $this->application !== null) {
            throw new \LogicException('The HTTP test context cannot be activated again.');
        }
        $this->application = $application;
        $application->instance(HttpClient::class, $this->client());
    }

    public function client(): HttpClient
    {
        $this->application();
        return $this->client ??= new HttpClient($this);
    }

    public function application(): Application
    {
        if (self::$current !== $this) {
            throw new \LogicException('The HTTP client belongs to a test execution that is no longer active.');
        }
        $application = $this->application ?? throw new \LogicException(
            'The HTTP client is not available before application bootstrap has finished.',
        );
        if (\Illuminate\Container\Container::getInstance() !== $application) {
            throw new \LogicException('The Laravel application has changed outside the active test execution.');
        }
        return $application;
    }

    public function close(): void
    {
        if (self::$current === $this) {
            self::$current = null;
        }
        $this->application = null;
        $this->client = null;
    }
}
