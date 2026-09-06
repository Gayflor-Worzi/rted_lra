<?php
// phase10_integrity_test.php - dashboard data integrity & accuracy audit.
require __DIR__.'/api_test_helpers.php';

echo "Phase 10 — Dashboard Data Integrity & Accuracy Audit\n";
echo "==================================================\n";

// ---------- 1. Authenticate ----------
$admin   = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
$officer = login('enf.officer@test.lra', 'officer123');
$acctmgr = login('account.manager@test.lra', 'manager123');

check($admin !== null,   'admin login succeeds');
check($officer !== null, 'enforcement officer login succeeds');
check($acctmgr !== null, 'account manager login succeeds');

// ---------- 2. GET /dashboard/integrity – happy path ----------
[$code, $r] = api('GET', '/dashboard/integrity', null, $admin);
checkCode($code, 200, 'admin integrity endpoint → 200');

check(isset($r['data']['status']), 'response contains status field');
check(in_array($r['data']['status'], ['healthy', 'degraded']), "status is 'healthy' or 'degraded' (got: {$r['data']['status']})");
check(is_array($r['data']['checks']), 'response contains checks array');
check(count($r['data']['checks']) === 11, 'exactly 11 checks are defined');
check(isset($r['data']['at']), 'response includes run timestamp');

// Verify structure of each check
$expectedKeys = [
    'unpaid_with_zero_balance',
    'paid_with_balance',
    'outstanding_mismatch',
    'incomplete_completed_task',
    'delivered_without_visit',
    'verified_without_evidence',
    'approved_valuation_without_approval',
    'completed_discovery_without_processing',
    'bill_total_mismatch',
    'outstanding_exceeds_total',
    'staff_target_drift',
];

foreach ($r['data']['checks'] as $check) {
    check(
        isset($check['key']) && isset($check['label']) && isset($check['count']) && isset($check['findings']),
        "check '{$check['key']}' has key/label/count/findings fields"
    );
    check(is_array($check['findings']), "check '{$check['key']}' findings is array");
    check(
        in_array($check['key'], $expectedKeys),
        "check key '{$check['key']}' is one of the 11 expected keys"
    );
}

// Ensure all 11 keys are present (order-independent)
$foundKeys = array_column($r['data']['checks'], 'key');
$missing   = array_diff($expectedKeys, $foundKeys);
check(count($missing) === 0, 'all 11 expected check keys present (missing: '.implode(', ', $missing).')');

// ---------- 3. Cross-check: integrity matches lra:integrity-check CLI ----------
// Re-login via tinker-free approach — the API response should agree with
// the command output we verified manually. At minimum the status + check count
// must agree.
$cliStatus    = null;
$cliChecks    = null;
$cliHandle    = popen(
    '"'.PHP_BINARY.'" artisan lra:integrity-check --format=json 2>&1',
    'r'
);
if ($cliHandle) {
    while (!feof($cliHandle)) {
        $line = fgets($cliHandle);
        if ($line !== false) {
            $json = json_decode(trim($line), true);
            if ($json !== null) {
                $cliStatus = $json['status'] ?? null;
                $cliChecks = $json['checks'] ?? null;
            }
        }
    }
    pclose($cliHandle);
}

if ($cliStatus !== null) {
    check(
        $r['data']['status'] === $cliStatus,
        "API status ({$r['data']['status']}) matches CLI status ({$cliStatus})"
    );
    check(
        count($r['data']['checks']) === count($cliChecks),
        "API check count matches CLI check count (".count($cliChecks).')'
    );

    // Per-check key-level agreement
    $cliMap = [];
    foreach ($cliChecks as $cc) {
        $cliMap[$cc['key']] = $cc['count'];
    }
    foreach ($r['data']['checks'] as $ac) {
        $expected = $cliMap[$ac['key']] ?? null;
        if ($expected !== null) {
            check(
                $ac['count'] === $expected,
                "API check '{$ac['key']}' finding count ({$ac['count']}) matches CLI ({$expected})"
            );
        }
    }
} else {
    echo "  NOTE  CLI JSON output unavailable (skipping CLI cross-check)\n";
}

// ---------- 4. Format contract: every finding has required fields ----------
foreach ($r['data']['checks'] as $check) {
    foreach ($check['findings'] as $f) {
        check(
            isset($f['id']) && isset($f['reference']) && isset($f['issue']),
            "check '{$check['key']}' finding #{$f['id']} has id/reference/issue fields"
        );
    }
}

// ---------- 5. Permission guards ----------
[$code] = api('GET', '/dashboard/integrity', null, $officer);
checkCode($code, 403, 'enforcement officer → 403 (no manage:analytics / view_all / manage:data_quality)');

// ---------- 6. Summarise KPI coverage ----------
// Confirm the endpoint returns a meaningful degraded set — the seeded data
// is known to have some drift (Unpaid+zero balance, Delivered without visit,
// etc.), so at least one check should have findings > 0.
$hasFindings = false;
foreach ($r['data']['checks'] as $ck) {
    if ($ck['count'] > 0) {
        $hasFindings = true;
        break;
    }
}
check($hasFindings, 'integrity endpoint detects at least one drift finding in seeded data');

// Verify all 11 checks ran (count = 0 is valid, just means no drift for that check)
$allRan = count($r['data']['checks']) === 11;
check($allRan, 'all 11 integrity checks executed');

summary('Phase 10 — Dashboard Data Integrity & Accuracy Audit');
