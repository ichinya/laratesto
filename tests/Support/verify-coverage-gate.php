<?php

declare(strict_types=1);

$report = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$totals = $report['totals'] ?? [];
if (($report['status'] ?? null) !== 'passed' || $totals !== ['total' => 2, 'assertions' => 16, 'passed' => 2]) {
    throw new RuntimeException('Required coverage checks must execute and pass without skips: '.json_encode($totals));
}
$junit = simplexml_load_file($argv[2]);
$names = array_map(static fn(SimpleXMLElement $case): string => (string) $case['name'], $junit->xpath('//testcase'));
sort($names);
if ($names !== ['driverBackedSelectionInheritanceSoloAndConflict', 'unwritableReportIsUnsuccessfulAndDoesNotCostTheValidReport']
    || (int) $junit['assertions'] !== 16 || count($junit->xpath('//testcase/skipped | //testcase/error | //testcase/failure')) !== 0) {
    throw new RuntimeException('Required coverage JUnit identities or outcomes are missing.');
}
echo "Coverage compatibility: two executed checks, 16 assertions, zero skips.\n";
