<?php

declare(strict_types=1);

namespace Laratesto\Tests\Unit;

use Laratesto\Tests\Support\TestoCompatibilityRunner;
use Testo\Assert;
use Testo\Test;

final class TestoCoverageCompatibilityTest
{
    #[Test]
    public function driverBackedSelectionInheritanceSoloAndConflict(): void
    {
        self::requireDriver();
        $run = TestoCompatibilityRunner::run('coverage', ['LARATESTO_COVERAGE_BAD_WRITE' => '0'], timeout: 180);
        Assert::same(1, $run['exit']);
        Assert::same(['total' => 4, 'assertions' => 4, 'passed' => 3, 'aborted' => 1], $run['json']['totals']);
        Assert::instanceOf($run['coverage'], \SimpleXMLElement::class);
        $covered = $run['coverage']->xpath('//file[contains(@name,"Covered.php")]/line[@count > 0]');
        $excluded = $run['coverage']->xpath('//file[contains(@name,"Excluded.php")]/line[@count > 0]');
        Assert::true(count($covered) > 0, 'A real source line must have driver-recorded hits.');
        Assert::same([], $excluded);
        $uncovered = $run['coverage']->xpath('//file[contains(@name,"Excluded.php")]/line[@count = 0]');
        Assert::true(count($uncovered) > 0, 'Executed code outside Covers targets must remain uncovered in the report.');
        $unloaded = $run['coverage']->xpath('//file[contains(@name,"NeverLoaded.php")]/line[@count = 0]');
        Assert::true(count($unloaded) > 0, 'Source files that no test loads must remain in the coverage denominator.');
        $ignored = $run['coverage']->xpath('//file[contains(@name,"Ignored.php")]/line');
        Assert::same([], $ignored, 'codeCoverageIgnore methods must not contribute executable lines.');
        $ignoredEvents = array_values(array_filter($run['events'], static fn(array $event): bool => $event['event'] === 'ignored'));
        Assert::same(-1, $ignoredEvents[0]['value'], 'The ignored method must actually execute.');
        $counts = array_count_values(array_column($run['events'], 'event'));
        Assert::same(3, $counts['body']);
        Assert::false(isset($counts['unexpected body']));
        Assert::string(json_encode($run['json']['failures']))->contains('both #[Covers] and #[CoversNothing]');
    }

    #[Test]
    public function unwritableReportIsUnsuccessfulAndDoesNotCostTheValidReport(): void
    {
        self::requireDriver();
        $run = TestoCompatibilityRunner::run('coverage', ['LARATESTO_COVERAGE_BAD_WRITE' => '1'], arguments: ['--filter=inheritedCoverage'], timeout: 180);
        Assert::true($run['exit'] !== 0);
        Assert::same(1, $run['json']['totals']['passed']);
        Assert::instanceOf($run['coverage'], \SimpleXMLElement::class);
        Assert::string($run['stderr'])->contains('coverage report');
    }

    private static function requireDriver(): void
    {
        $working = extension_loaded('pcov') || (extension_loaded('xdebug') && in_array('coverage', xdebug_info('mode'), true));
        if ($working) { return; }
        if (getenv('LARATESTO_REQUIRE_COVERAGE') === '1') {
            throw new \RuntimeException('The dedicated coverage gate requires an active Xdebug/PCOV driver.');
        }
        throw new \Testo\Core\Exception\SkipTest('Coverage compatibility requires an active Xdebug/PCOV driver.');
    }
}
