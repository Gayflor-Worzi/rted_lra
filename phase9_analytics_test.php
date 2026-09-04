<?php

require __DIR__.'/api_test_helpers.php';

echo "Phase 9 — Consolidated Dashboard Analytics\n";
echo "==========================================\n";

$admin = login('wooze27@gmail.com', 'admin123');
$officer = login('enf.officer@test.lra', 'officer123');
$acctmgr = login('account.manager@test.lra', 'manager123');
$valmgr = login('val.manager@test.lra', 'manager123');

// ---- happy path: tasks default --------------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status&range=month', null, $admin);
checkCode($code, 200, 'admin tasks staff/status → 200');
check(!empty($r['data']['meta']['available_metrics']), 'meta.available_metrics populated');
check(isset($r['data']['meta']['group_options']['staff']), 'meta.group_options has staff');
check(isset($r['data']['meta']['pie_options']['task_status']), 'meta.pie_options has task_status');
check(is_array($r['data']['meta']['staff_options'] ?? null) && count($r['data']['meta']['staff_options']) > 0, 'meta.staff_options populated');
check(is_array($r['data']['meta']['section_options'] ?? null) && count($r['data']['meta']['section_options']) > 0, 'meta.section_options populated');
check(isset($r['data']['kpis']) && count($r['data']['kpis']) >= 3, 'kpis present');
check($r['data']['bar']['shape'] === 'simple', 'tasks bar is simple shape');
check(is_array($r['data']['bar']['data']), 'bar data present');
check(is_array($r['data']['pie']['data']), 'pie data present');
check(isset($r['data']['records']['rows']) && isset($r['data']['records']['total']) && isset($r['data']['records']['last_page']), 'records paginated shape present');

// ---- metric / dimension matrix ---------------------------------------------
$matrix = [
    'bills' => '&group_by=payment_status&pie=property_classification&range=year',
    'collections' => '&group_by=staff&pie=property_type&range=year',
    'payments' => '&group_by=staff&pie=match_status',
    'discoveries' => '&group_by=discovery_status&pie=decision_path&range=year',
    'valuations' => '&group_by=staff&pie=completion_status',
    'visits' => '&group_by=visit_status&pie=delivery_status&range=quarter',
    'targets' => '&group_by=metric&pie=metric&range=year',
    'tasks' => '&group_by=section&pie=enforcement_stage',
];
foreach ($matrix as $m => $extra) {
    [$code] = api('GET', "/dashboard/analytics?metric={$m}{$extra}", null, $admin);
    checkCode($code, 200, "admin {$m} matrix → 200");
}

// ---- grouped (targets style) bar shape --------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=month&pie=completion_status&range=year', null, $admin);
checkCode($code, 200, 'admin tasks month/grouped → 200');
check(in_array($r['data']['bar']['shape'], ['simple', 'grouped'], true), 'bar shape recognised');

// ---- numeric staff drill ----------------------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status', null, $admin);
checkCode($code, 200, 'admin tasks baseline → 200');
$pid = null;
$pname = '';
foreach ($r['data']['bar']['data'] as $row) {
    if (!empty($row['id']) && $row['value'] > 0) {
        $pid = $row['id'];
        $pname = $row['label'];
        break;
    }
}
check($pid !== null, 'found a staff member with tasks to drill');
if ($pid !== null) {
    [$c2, $r2] = api('GET', "/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status&record_dim=staff&record_value={$pid}&per_page=50", null, $admin);
    checkCode($c2, 200, 'staff record drill → 200');
    $ok = true;
    foreach ($r2['data']['records']['rows'] ?? [] as $row) {
        if ($row[4] !== $pname && $row[4] !== '—') {
            $ok = false;
        }
    }
    check($ok, 'staff drill rows all belong to drilled staff');
}

// ---- pie slice drill by label ----------------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status', null, $admin);
$slice = null;
foreach ($r['data']['pie']['data'] as $s) {
    if (($s['value'] ?? 0) > 0) {
        $slice = $s;
        break;
    }
}
check($slice !== null, 'pie has a populated slice');
if ($slice !== null) {
    $val = urlencode($slice['label']);
    [$c2, $r2] = api('GET', "/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status&record_dim=task_status&record_value={$val}", null, $admin);
    checkCode($c2, 200, 'status record drill → 200');
    $ok = true;
    foreach ($r2['data']['records']['rows'] ?? [] as $row) {
        if ($row[3] !== $slice['label']) {
            $ok = false;
        }
    }
    check($ok, 'status drill rows all match drilled status');
}

// ---- section drill ----------------------------------------------------------
[$c2, $r2] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status&record_dim=section&record_value=Enforcement', null, $admin);
checkCode($c2, 200, 'section record drill → 200');
if (($r2['data']['records']['total'] ?? 0) > 0) {
    $ok = true;
    foreach ($r2['data']['records']['rows'] as $row) {
        if ($row[2] !== 'Enforcement') {
            $ok = false;
        }
    }
    check($ok, 'section drill rows all Enforcement');
}

// ---- temporal drill matches bar count ---------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=month&pie=task_status&range=year', null, $admin);
checkCode($code, 200, 'month group baseline → 200');
$mval = $r['data']['bar']['data'][0]['label'] ?? null;
check($mval !== null, 'month bar has a label');
if ($mval !== null) {
    [$c2, $r2] = api('GET', '/dashboard/analytics?metric=tasks&group_by=month&pie=task_status&range=year&record_dim=month&record_value='.urlencode($mval), null, $admin);
    checkCode($c2, 200, 'month record drill → 200');
    check($r2['data']['records']['total'] == $r['data']['bar']['data'][0]['value'], 'month drill total equals bar value');
}

// ---- money-flagged KPIs -----------------------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=bills&group_by=payment_status&pie=payment_status&range=year', null, $admin);
checkCode($code, 200, 'bills baseline → 200');
$money = false;
foreach ($r['data']['kpis'] as $k) {
    if (!empty($k['money'])) {
        $money = true;
    }
}
check($money, 'bills KPIs include money-flagged values');

// ---- drill "open register" links --------------------------------------------
$drillLinks = [
    ['tasks', 'task_status', '/drill/tasks'],
    ['bills', 'payment_status', '/drill/bills'],
    ['collections', 'property_classification', '/drill/payments'],
    ['payments', 'payment_status', '/drill/payments'],
    ['discoveries', 'discovery_status', '/drill/discoveries'],
    ['valuations', 'valuation_status', '/drill/valuations'],
    ['visits', 'visit_status', '/drill/visits'],
    ['targets', 'metric', '/targets'],
];
foreach ($drillLinks as [$m, $pie, $expected]) {
    [$code, $r] = api('GET', "/dashboard/analytics?metric={$m}&group_by=month&pie={$pie}&range=year", null, $admin);
    checkCode($code, 200, "drill link {$m} → 200");
    check(($r['data']['meta']['drill'] ?? null) === $expected, "drill link {$m} maps to {$expected}");
}

// ---- scoping & permissions ---------------------------------------------------
[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status', null, $officer);
checkCode($code, 200, 'officer tasks analytics → 200');
check(count($r['data']['records']['rows'] ?? []) <= 50, 'officer records bounded by own scope');

[$code] = api('GET', '/dashboard/analytics?metric=bills&group_by=staff&pie=payment_status', null, $admin);
checkCode($code, 200, 'admin bills baseline → 200');

[$code, $r] = api('GET', '/dashboard/analytics?metric=bills&group_by=staff&pie=payment_status', null, $officer);
checkCode($code, 403, 'officer bills analytics denied (no bills.view) → 403');

[$code, $r] = api('GET', '/dashboard/analytics?metric=bills&group_by=staff&pie=payment_status', null, $acctmgr);
checkCode($code, 200, 'account manager bills (section scope) → 200');

[$code] = api('GET', '/dashboard/analytics?metric=valuations&group_by=staff&pie=valuation_status', null, $acctmgr);
checkCode($code, 403, 'account manager valuations denied → 403');

[$code, $r] = api('GET', '/dashboard/analytics?metric=valuations&group_by=staff&pie=valuation_status', null, $valmgr);
checkCode($code, 200, 'valuation manager valuations (section scope) → 200');
check(count($r['data']['meta']['staff_options'] ?? []) >= 1, 'valuation manager staff options populated');

[$c3, $r3] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status', null, $officer);
checkCode($c3, 200, 'own-scope officer analytics baseline → 200');
check(count($r3['data']['meta']['staff_options'] ?? []) === 1, 'own-scope officer staff options limited to self');

// ---- guards -----------------------------------------------------------------
[$code] = api('GET', '/dashboard/analytics?metric=tasks&group_by=not_a_dim&pie=task_status', null, $admin);
checkCode($code, 422, 'unsupported group_by → 422');
[$code] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=not_a_dim', null, $admin);
checkCode($code, 422, 'unsupported pie dimension → 422');
[$code] = api('GET', '/dashboard/analytics?metric=notametric&group_by=staff', null, $admin);
checkCode($code, 422, 'unknown metric → 422');

[$code, $r] = api('GET', '/dashboard/analytics?metric=tasks&group_by=staff&pie=task_status&per_page=999', null, $admin);
checkCode($code, 200, 'oversized per_page accepted');
check(count($r['data']['records']['rows'] ?? []) <= 50, 'per_page clamped to 50');

summary('Phase 9');