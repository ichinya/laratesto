<?php

declare(strict_types=1);

// Complements Testo's per-method run-count comparator. No vendor autoload needed.
// Exit 0: matching nonempty histograms; 1: differences; 2: invalid input/usage.
const TESTO_JUNIT_NAMESPACE = 'https://php-testo.github.io/schema/junit/1';

/** @return array<string, array<string, int>> */
function outcomes(string $file): array
{
    $contents = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
    if ($contents === false || stripos($contents, '<!DOCTYPE') !== false) {
        throw new RuntimeException('Unreadable or unsupported XML: ' . $file);
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($contents, options: LIBXML_NONET);
        if ($xml === false || !in_array($xml->getName(), ['testsuites', 'testsuite'], true)) {
            throw new RuntimeException('Invalid JUnit: ' . $file);
        }
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    $result = [];
    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $native = $case->attributes(TESTO_JUNIT_NAMESPACE);
        $class = (string) ($case['class'] ?? '');
        if ($class === '') {
            $class = (string) ($case['classname'] ?? '');
            if (!isset($native['status'])) {
                $class = str_replace('.', '\\', $class);
            }
        }
        $name = (string) ($case['name'] ?? '');
        if ($class === '' || $name === '') {
            throw new RuntimeException('A testcase lacks class/name identity: ' . $file);
        }
        if (isset($native['data-set'])) {
            $name = explode(' [', $name, 2)[0];
        } else {
            $name = (string) preg_replace('/ with data set (?:#\d+|".*")$/s', '', $name);
        }
        $status = isset($case->error) ? 'error' : (isset($case->failure) ? 'failed' : (isset($case->skipped) ? 'skipped' : 'passed'));
        if (isset($native['status'])) {
            $status = (string) $native['status'];
            if (!in_array($status, ['passed', 'failed', 'error', 'skipped', 'risky', 'flaky', 'cancelled', 'aborted'], true)) {
                throw new RuntimeException('Unknown native status: ' . $status);
            }
        }
        $key = ltrim($class, '\\') . '::' . $name;
        $result[$key][$status] = ($result[$key][$status] ?? 0) + 1;
    }
    if ($result === []) {
        throw new RuntimeException('JUnit contains no testcases: ' . $file);
    }
    ksort($result);
    foreach ($result as &$counts) {
        ksort($counts);
    }
    unset($counts);
    return $result;
}

try {
    if ($argc !== 3 || !extension_loaded('SimpleXML')) {
        throw new RuntimeException('Usage (requires SimpleXML): php compare-outcomes.php <before.xml> <after.xml>');
    }
    $before = outcomes($argv[1]);
    $after = outcomes($argv[2]);
    $differences = [];
    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $method) {
        if (($before[$method] ?? []) !== ($after[$method] ?? [])) {
            $differences[$method] = ['before' => $before[$method] ?? [], 'after' => $after[$method] ?? []];
        }
    }
    echo json_encode(['matching' => $differences === [], 'differences' => $differences], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit($differences === [] ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
}
