<?php

declare(strict_types=1);

namespace Laratesto\Pipeline;

use Psr\EventDispatcher\EventDispatcherInterface;
use Testo\Common\Messenger;
use Testo\Core\Context\CaseInfo;
use Testo\Core\Context\CaseResult;
use Testo\Core\Context\TestInfo;
use Testo\Core\Value\Status;
use Testo\Core\Value\Summary;
use Testo\Event\Test\TestPipelineFinished;
use Testo\Event\Test\TestPipelineStarting;
use Testo\Event\TestCase\TestCaseFinished;
use Testo\Event\TestCase\TestCaseStarting;
use Testo\Fiber\RunInFiber;
use Testo\Fiber\Schedule;
use Testo\Pipeline\Attribute\InterceptorOptions;
use Testo\Pipeline\Middleware\TestCaseRunInterceptor;

/** Laravel globals require sequential class scheduling. Reject before hooks or providers. */
#[InterceptorOptions(order: InterceptorOptions::ORDER_DATA_PROVIDER - 2)]
final readonly class LaravelFiberCompatibilityInterceptor implements TestCaseRunInterceptor
{
    public function __construct(private EventDispatcherInterface $events, private Messenger $messenger) {}

    public function runTestCase(CaseInfo $info, callable $next): CaseResult
    {
        $attributes = $info->getAttribute(RunInFiber::class, []);
        // AttributesInterceptor preserves Reflection's order; the native Fiber
        // interceptor uses ConflictPolicy::Last, including parent/trait layers.
        $effective = $attributes === [] ? null : $attributes[array_key_last($attributes)];
        if (!$effective instanceof RunInFiber || $effective->schedule === Schedule::Solo
            || iterator_count($info->definition->tests->getTests(skipped: false)) === 0) {
            return $next($info);
        }

        $this->events->dispatch(new TestCaseStarting($info));
        $results = [];
        foreach ($info->definition->tests->getTests() as $name => $definition) {
            $test = new TestInfo(name: $name, caseInfo: $info, testDefinition: $definition);
            $results[] = $this->messenger->scope(function (Messenger $messenger) use ($test, $definition, $effective) {
                $this->events->dispatch(new TestPipelineStarting($test));
                if ($definition->skipped) {
                    // Use the selected Testo skip implementation to keep its reason/precedence.
                    $result = (new \Testo\Skip\Internal\SkipInterceptor())->runTest($test, static fn() => throw new \LogicException('Skipped work cannot run.'));
                } else {
                    $failure = new \RuntimeException('LaravelPlugin does not support class Fiber schedule '.$effective->schedule->name.' for '.$test->identity->fqn().'; use Schedule::Solo.');
                    Internal\FailureResult::report($failure, 'Fiber compatibility', $messenger);
                    $result = Internal\FailureResult::aborted($test, $failure)
                        ->withSummary(Summary::forTest(Status::Aborted))
                        ->withMessages($messenger->getMessages());
                }
                $this->events->dispatch(new TestPipelineFinished($test, $result));
                return $result;
            }, $test->identity);
        }
        $result = new CaseResult($results, Status::Failed, Summary::combine(array_map(static fn($test) => $test->summary, $results)));
        $this->events->dispatch(new TestCaseFinished($info, $result));
        return $result;
    }
}
