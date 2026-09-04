<?php
// run_all_tests.php - runs the RETD API suites in sequence.
$base = dirname(__FILE__);
$suites = [
    'smoke_test.php',
    'auth_lifecycle_test.php',
    'rbac_test.php',
    'bill_register_test.php',
    'task_engine_test.php',
    'phase3_6_test.php',
    'phase7_intelligence_test.php',
    'phase8_reports_export_test.php',
    'phase9_analytics_test.php',
    'phase10_integrity_test.php',
    'acceptance_scoped_test.php',
];

$failures = 0;
foreach ($suites as $suite) {
    echo "\n==================================================\n";
    echo "  RUNNING: $suite\n";
    echo "==================================================\n";
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($base.'/'.$suite);
    passthru($cmd, $code);
    if ($code !== 0) {
        $failures++;
    }
}

echo "\n==================================================\n";
echo 'SUITES PASSED: '.(count($suites) - $failures).'/'.count($suites)."\n";
if ($failures) {
    echo "FAILING SUITES: $failures\n";
    exit(1);
}
echo "ALL TESTS PASSED.\n";