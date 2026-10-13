<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class LifecycleFaultCompatibilityTest
{
    #[Test]
    public function lifecycleFaultsRetainTheirCauseAndTheFollowingTestIsClean(): void
    {
        foreach (['factory', 'no-application', 'setup', 'teardown', 'body-teardown', 'rollback', 'unrecoverable-rollback', 'disconnect'] as $scenario) {
            $run = TestoCompatibilityRunner::run('lifecycle-faults', ['LARATESTO_LIFECYCLE_FAULT' => $scenario]);
            Assert::same(1, $run['exit'], $scenario);
            Assert::same(2, $run['json']['totals']['total'], $scenario);
            Assert::same(1, $run['json']['totals']['passed'] ?? 0, $scenario);
            Assert::same(1, count($run['json']['failures']), $scenario);
            $failure = $run['json']['failures'][0];
            Assert::string($failure['test'])->contains('::fault');
            $bodyFailure = in_array($scenario, ['body-teardown', 'rollback', 'unrecoverable-rollback', 'disconnect'], true);
            Assert::same($bodyFailure ? 'failed' : 'aborted', $failure['status']);
            $expectedCause = match ($scenario) {
                'factory' => 'primary factory bootstrap fault',
                'no-application' => 'primary no-application bootstrap fault',
                'setup' => 'primary setup fault',
                'teardown' => 'secondary teardown fault',
                default => 'primary body fault',
            };
            Assert::string($failure['message'])->contains($expectedCause);
            Assert::string($run['junit']->asXML())->contains($expectedCause);
            if ($bodyFailure) {
                Assert::string(json_encode($failure, JSON_THROW_ON_ERROR))->contains(
                    match ($scenario) {
                        'body-teardown' => 'secondary teardown fault',
                        'disconnect' => 'secondary disconnect fault',
                        default => 'secondary rollback fault',
                    },
                );
            }
            Assert::same(2, count($run['junit']->xpath('//testcase')));
            Assert::same(1, count($run['junit']->xpath($bodyFailure ? '//testcase/failure' : '//testcase/error')));
            Assert::same(0, count($run['junit']->xpath('//testcase/skipped')));
            $between = array_values(array_filter($run['events'], static fn($entry) => $entry['event'] === 'between tests'));
            Assert::same(1, count($between));
            Assert::true($between[0]['facade_cleared'], $scenario);
            Assert::true($between[0]['container_cleared'], $scenario);
            Assert::false($between[0]['cached_transaction'], $scenario);
            if ($scenario === 'rollback') {
                Assert::true($between[0]['cached_available']);
                Assert::true($between[0]['migrated']);
            } elseif ($scenario === 'unrecoverable-rollback') {
                Assert::false($between[0]['cached_available']);
                Assert::false($between[0]['migrated']);
            }
            $polluted = array_values(array_filter($run['events'], static fn($entry) => $entry['event'] === 'polluted'));
            $clean = array_values(array_filter($run['events'], static fn($entry) => $entry['event'] === 'clean'));
            Assert::same(1, count($clean));
            if ($polluted !== []) {
                Assert::notSame($polluted[0]['owner'], $clean[0]['owner']);
            }
        }
    }
}
