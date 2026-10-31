<?php

declare(strict_types=1);

/*
 * Prüft das Coverage-Ziel (Statements) für einzelne Verzeichnisse anhand eines Clover-Reports.
 *
 * Aufruf: php .github/scripts/coverage-check.php build/clover.xml 90 src/Graph src/Auth src/Exceptions
 */

[$script, $report, $minimum] = array_pad(array_slice($argv, 0, 3), 3, null);
$directories = array_slice($argv, 3);

if ($report === null || $minimum === null || $directories === [] || ! is_file($report)) {
    fwrite(STDERR, "Usage: php {$script} <clover.xml> <min-percent> <dir> [<dir> ...]\n");
    exit(2);
}

$xml = simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, "Unable to parse {$report}.\n");
    exit(2);
}

$root = realpath(__DIR__.'/../..').DIRECTORY_SEPARATOR;
$totals = array_fill_keys($directories, ['statements' => 0, 'covered' => 0]);

foreach ($xml->xpath('//file') ?: [] as $file) {
    $path = str_replace($root, '', (string) $file['name']);
    $metrics = $file->metrics;

    foreach ($directories as $directory) {
        if (str_starts_with($path, rtrim($directory, '/').'/')) {
            $totals[$directory]['statements'] += (int) $metrics['statements'];
            $totals[$directory]['covered'] += (int) $metrics['coveredstatements'];
        }
    }
}

$failed = false;

foreach ($totals as $directory => $total) {
    $percent = $total['statements'] === 0 ? 0.0 : $total['covered'] / $total['statements'] * 100;
    $ok = $percent >= (float) $minimum;
    $failed = $failed || ! $ok;

    printf("%s %-20s %6.2f %% (%d/%d)\n", $ok ? '✔' : '✘', $directory, $percent, $total['covered'], $total['statements']);
}

exit($failed ? 1 : 0);
