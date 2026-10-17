<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Laratesto\Testing\LaravelTestCase;
use Testo\Core\Definition\CaseDefinitions;
use Testo\Core\Value\TestType;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\CaseLocatorInterceptor;
use Testo\Tokenizer\Reflection\FileDefinitions;

/** Excludes bridge helpers from class-level discovery, including trait aliases. */
#[InterceptorOptions(order: InterceptorOptions::ORDER_FILTER, testType: TestType::Test)]
final readonly class LaravelDiscoveryInterceptor implements CaseLocatorInterceptor
{
    /** @var array<string, true> */
    private array $helpers;

    public function __construct()
    {
        $helpers = [];
        foreach ((new \ReflectionClass(LaravelTestCase::class))->getMethods() as $method) {
            $helpers[self::implementation($method)] = true;
        }
        $this->helpers = $helpers;
    }

    #[\Override]
    public function locateTestCases(FileDefinitions $file, callable $next): CaseDefinitions
    {
        $result = $next($file);
        foreach ($result->getCases() as $case) {
            if ($case->type !== TestType::Test->value) {
                continue;
            }
            foreach ($case->tests->getTests(active: null) as $test) {
                $method = $test->reflection;
                if ($method instanceof \ReflectionMethod && isset($this->helpers[self::implementation($method)])) {
                    $test->isTest = false;
                }
            }
        }
        return $result;
    }

    private static function implementation(\ReflectionMethod $method): string
    {
        // Trait methods report the consuming class as their owner. Source identity also
        // recognises aliases while retaining user overrides and unrelated namesakes.
        return $method->getFileName().':'.$method->getStartLine().':'.$method->getEndLine();
    }
}
