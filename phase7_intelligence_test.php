<?php
// phase7_intelligence_test.php - Management Intelligence, Performance
// Dashboards & New Property Discovery (Phase 7 feature increment).
require __DIR__.'/api_test_helpers.php';

$admin   = login('wooze27@gmail.com', 'admin123');
$acct    = login('account.manager@test.lra', 'manager123');
$enf     = login('enf.officer@test.lra', 'officer123');
$val     = login('val.officer@test.lra', 'officer123');
$valmgr  = login('val.manager@test.lra', 'manager123');

check((bool) $admin, 'admin login');
check((bool) $enf, 'enf login');
check((bool) $valmgr, 'valmgr login');

// Resolve numeric user ids for assignment payloads.
[$c, $r] = api('GET', '/users?q=enf.officer&per_page=5', null, $admin);
$enfId = $r['data']['data'][0]['id'] ?? null;
[$c, $r] = api('GET', '/users?q=val.officer&per_page=5', null, $admin);
$valId = $r['data']['data'][0]['id'] ?? null;
check((bool) $enfId, 'enf officer id resolved');
check((bool) $valId, 'val officer id resolved');

/* ============ NEW PROPERTY DISCOVERY ============ */

// Field discovery via the rewired mobile endpoint returns an ND- reference and
// NEVER generates a document number or property id.
$addr = 'Discovery Phase St '.uniqid();
[$c, $r] = api('POST', '/enforcement/discover', [
    'property_address' => $addr,
    'gps_lat' => 6.32,
    'gps_lng' => -10.78,
    'owner_name' => 'Phase Discovery Owner',
    'documents' => ['property_photo' => 'file://x'],
], $enf);
checkCode($c, 201, 'POST /enforcement/discover → 201');
$mobileRef = $r['data']['discovery_reference'] ?? null;
check(str_starts_with((string) $mobileRef, 'ND-'), 'discover returns ND- reference');
check(! isset($r['data']['document_number']), 'discover does not generate a document number');

// Full discovery lifecycle (Path B — valuation).
[$c, $r] = api('POST', '/discoveries', [
    'owner_name' => 'Phase Owner B',
    'owner_contact' => '+231770000001',
    'tin' => 'TIN-PHASE-B',
    'property_address' => 'Discovery Ave '.uniqid(),
    'property_classification' => 'Residential',
    'gps_lat' => 6.33,
    'gps_lng' => -10.77,
], $enf);
checkCode($c, 201, 'POST /discoveries → 201');
$d = $r['data'] ?? [];
$discId = $d['id'] ?? null;
check((bool) $discId, 'discovery created with id');
check(str_starts_with((string) ($d['discovery_reference'] ?? ''), 'ND-'), 'discovery_reference ND-YYYY-#####');
check(($d['property_id'] ?? null) === null, 'discovery has NO property_id (LITAS only)');
check(($d['document_number'] ?? null) === null, 'discovery has NO document_number (LITAS only)');

[$c, $r] = api('POST', "/discoveries/{$discId}/submit", [], $enf);
checkCode($c, 200, 'discovery submit → 200');
check(($r['data']['status'] ?? '') === 'SUBMITTED', 'status SUBMITTED');

// Officer cannot review.
[$c, $r] = api('POST', "/discoveries/{$discId}/review", ['manager_remarks' => 'x'], $enf);
checkCode($c, 403, 'officer review denied → 403');

[$c, $r] = api('POST', "/discoveries/{$discId}/review", ['manager_remarks' => 'Confirmed'], $valmgr);
checkCode($c, 200, 'valuation manager review → 200');
check(($r['data']['status'] ?? '') === 'UNDER_MANAGER_REVIEW', 'status UNDER_MANAGER_REVIEW');

[$c, $r] = api('POST', "/discoveries/{$discId}/classify", ['decision_path' => 'valuation', 'classification_decision' => 'Needs valuation'], $valmgr);
checkCode($c, 200, 'classify → 200');
check(($r['data']['status'] ?? '') === 'CLASSIFIED', 'status CLASSIFIED');
check(($r['data']['decision_path'] ?? '') === 'valuation', 'decision_path valuation');

// Path B routing creates the Valuation record (LITAS ids stay blank).
[$c, $r] = api('POST', "/discoveries/{$discId}/route-to-valuation", ['officer_id' => $valId], $valmgr);
checkCode($c, 200, 'route-to-valuation → 200');
$createdValuationId = $r['data']['valuation_id'] ?? null;
check((bool) $createdValuationId, 'valuation created from discovery');
check(($r['data']['status'] ?? '') === 'VALUATION_ASSIGNED', 'discovery VALUATION_ASSIGNED');

[$c, $r] = api('GET', "/valuations/{$createdValuationId}", null, $val);
checkCode($c, 200, 'valuation visible to val officer');
check(($r['data']['valuation_type'] ?? '') === 'new_property', 'valuation type new_property');
check(($r['data']['property_id'] ?? null) === null, 'valuation property_id blank');

// Attach property descriptions + photo, then submit.
[$c, $r] = api('POST', "/valuations/{$createdValuationId}/descriptions", [
    'descriptions' => [
        ['description' => 'Building', 'level' => 'Ground', 'area_sqft' => 1200, 'quantity' => 2, 'amount' => 5000, 'depreciation_pct' => 10],
    ],
], $val);
checkCode($c, 200, 'valuation descriptions → 200');
check((float) ($r['data']['totals']['total_property_value'] ?? 0) == 9000.0, 'rowFormula Amount×Qty×(1-Depr%) = 9000');

$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
[$c, $r] = api('POST', '/evidence/photos', [
    'photo_type' => 'PROPERTY_FULL_VIEW',
    'valuation_id' => $createdValuationId,
    'data_uri' => $png,
    'gps_lat' => 6.33,
    'gps_lng' => -10.77,
], $val);
checkCode($c, 201, 'valuation property photo → 201');

[$c, $r] = api('POST', "/valuations/{$createdValuationId}/submit", [
    'assessed_value' => 180000,
    'annual_tax' => 3600,
    'applicable_tax_rate' => 2,
    'other_amounts' => 0,
], $val);
checkCode($c, 200, 'valuation submit → 200');
check(($r['data']['status'] ?? '') === 'Submitted', 'valuation status Submitted');

// Sync: discovery reflects the valuation lifecycle.
[$c, $r] = api('GET', "/discoveries/{$discId}", null, $admin);
check(($r['data']['status'] ?? '') === 'VALUATION_MANAGER_REVIEW', 'discovery synced VALUATION_MANAGER_REVIEW');

[$c, $r] = api('POST', "/valuations/{$createdValuationId}/review", ['decision' => 'forward_ac', 'remarks' => 'OK'], $valmgr);
checkCode($c, 200, 'valuation forward to AC → 200');

[$c, $r] = api('GET', "/discoveries/{$discId}", null, $admin);
check(($r['data']['status'] ?? '') === 'PENDING_AC_APPROVAL', 'discovery synced PENDING_AC_APPROVAL');

// AC decides.
[$c, $r] = api('POST', "/valuations/{$createdValuationId}/decide", ['decision' => 'approve'], $admin);
checkCode($c, 200, 'AC approve valuation → 200');

[$c, $r] = api('GET', "/discoveries/{$discId}", null, $admin);
check(($r['data']['status'] ?? '') === 'AC_APPROVED', 'discovery synced AC_APPROVED after approval');

// Path A — account route.
[$c, $r] = api('POST', '/discoveries', [
    'owner_name' => 'Phase Owner A',
    'property_address' => 'Discovery Road '.uniqid(),
    'gps_coordinate' => '6.34,-10.76',
], $enf);
$dA = ($r['data']['id'] ?? null);
[$c, $r] = api('POST', "/discoveries/{$dA}/submit", [], $enf);
api('POST', "/discoveries/{$dA}/review", [], $valmgr);
[$c, $r] = api('POST', "/discoveries/{$dA}/classify", ['decision_path' => 'account'], $valmgr);
checkCode($c, 200, 'classify account → 200');

[$c, $r] = api('POST', '/discoveries/'.$dA.'/route-to-account', [], $valmgr);
checkCode($c, 200, 'route-to-account → 200');
check(($r['data']['status'] ?? '') === 'SENT_TO_ACCOUNT', 'status SENT_TO_ACCOUNT');

[$c, $r] = api('POST', "/discoveries/{$dA}/account-processing", [
    'property_id' => 'LITAS-PROP-94731',
    'document_number' => '2026/88551',
], $acct);
checkCode($c, 200, 'account processing (source ids recorded) → 200');
check(($r['data']['property_id'] ?? '') === 'LITAS-PROP-94731', 'source property_id recorded');
check(($r['data']['status'] ?? '') === 'PROCESSED_IN_LITAS', 'status PROCESSED_IN_LITAS');

[$c, $r] = api('POST', "/discoveries/{$dA}/complete", [], $acct);
checkCode($c, 200, 'discovery complete → 200');
check(($r['data']['status'] ?? '') === 'COMPLETED', 'status COMPLETED');

// Discovery list + stats + scope guards.
[$c, $r] = api('GET', '/discoveries?per_page=5', null, $valmgr);
checkCode($c, 200, 'GET /discoveries → 200');
check(count($r['data']['data'] ?? []) > 0, 'discoveries listed');

[$c, $r] = api('GET', '/discoveries/stats', null, $valmgr);
checkCode($c, 200, 'GET /discoveries/stats → 200');
check(is_array($r['data']['by_status'] ?? null), 'stats by_status present');

[$c, $r] = api('GET', '/discoveries', null, $acct);
checkCode($c, 200, 'ACCT sees discoveries');

// Discovery evidence photos filtered by discovery_id.
[$c, $r] = api('GET', "/evidence/photos?discovery_id={$discId}", null, $admin);
checkCode($c, 200, 'photos filtered by discovery_id → 200');

/* ============ STAFF TARGETS ============ */

[$c, $r] = api('POST', '/targets', [
    'user_id' => $enfId,
    'section' => 'Enforcement',
    'metric' => 'bills_delivered',
    'target_value' => 8,
    'measurement_unit' => 'bills',
    'frequency' => 'Monthly',
    'period' => '2026',
], $admin);
checkCode($c, 201, 'create target → 201');
$tId = $r['data']['id'] ?? null;
check((bool) $tId, 'target id returned');
check(($r['data']['status'] ?? '') === 'Draft', 'target starts Draft');

[$c, $r] = api('POST', "/targets/{$tId}/approve", [], $admin);
checkCode($c, 200, 'target approve → 200');
check(($r['data']['status'] ?? '') === 'Approved', 'target Approved');

[$c, $r] = api('POST', "/targets/refresh/{$tId}", [], $admin);
checkCode($c, 200, 'target refresh → 200');
check(($r['data']['refreshed'] ?? 0) >= 1, 'achieved value recomputed from records');
check(array_key_exists('refreshed', $r['data'] ?? []), 'refresh response carries refreshed count');

[$c, $r] = api('POST', '/targets', ['user_id' => $enfId, 'metric' => 'visits', 'target_value' => 5], $enf);
checkCode($c, 403, 'officer cannot create targets → 403');

[$c, $r] = api('GET', '/targets?period=2026', null, $admin);
checkCode($c, 200, 'targets index → 200');

/* ============ M&E OPERATIONAL POWERS ============ */

[$c, $r] = api('GET', '/me/review-board?walkin=1', null, $admin);
checkCode($c, 200, 'M&E review board → 200');

[$c, $r] = api('GET', '/tasks?per_page=20', null, $admin);
$active = array_values(array_filter($r['data']['data'] ?? [], fn ($t) => ! in_array($t['status'] ?? '', ['Resolved', 'Closed', 'Paid'], true)));
$candidate = null;
foreach ($active as $t) {
    if ((int) ($t['assigned_to'] ?? 0) !== (int) $valId) {
        $candidate = $t;
        break;
    }
}
$taskId = $candidate['id'] ?? null;
check((bool) $taskId, 'admin has an active task to operate on');
check((int) ($candidate['assigned_to'] ?? 0) !== (int) $valId, 'task not already assigned to target officer');

[$c, $r] = api('POST', "/me/tasks/{$taskId}/revise", ['priority' => 'High', 'notes' => 'Revisit deadline'], $admin);
checkCode($c, 200, 'M&E task revision → 200');
check(($r['data']['priority'] ?? '') === 'High', 'priority revised');

[$c, $r] = api('POST', "/me/tasks/{$taskId}/reassign", ['officer_id' => $valId], $admin);
checkCode($c, 200, 'M&E task reassign → 200');
check(($r['data']['assigned_to'] ?? '') == $valId, 'task reassigned to valuation officer');

// Data-quality flagging against a bill.
[$c, $r] = api('GET', '/property-bills?per_page=1', null, $admin);
$billId = $r['data']['data'][0]['id'] ?? null;
[$c, $r] = api('POST', '/me/flags', ['bill_id' => $billId, 'issue' => 'TIN missing on record', 'severity' => 'High'], $valmgr);
checkCode($c, 201, 'data-quality flag → 201');
$flagId = $r['data']['id'] ?? null;
check((bool) $flagId, 'flag id returned');

[$c, $r] = api('PATCH', "/me/flags/{$flagId}/resolve", ['remarks' => 'TIN added'], $valmgr);
checkCode($c, 200, 'flag resolve → 200');
check(($r['data']['status'] ?? '') === 'Resolved', 'flag Resolved');

[$c, $r] = api('GET', '/me/flags', null, $valmgr);
checkCode($c, 200, 'flags index → 200');

/* ============ COMMAND DASHBOARD (division + drill) ============ */

[$c, $r] = api('GET', '/dashboard/division?range=month', null, $admin);
checkCode($c, 200, 'GET /dashboard/division → 200');
$dv = $r['data'] ?? [];
check(isset($dv['kpis']['tasks']['overdue']), 'division KPI tasks present');
check(isset($dv['kpis']['valuations']['total_assessed_30d']), 'valuation KPIs present');
check(isset($dv['discovery_pipeline']['awaiting_valuation']), 'discovery pipeline present');
check(isset($dv['sections']['Enforcement']), 'section splits present');
check(is_array($dv['staff_performance'] ?? null), 'staff performance array present');
check(isset($dv['target_averages']) && is_array($dv['target_averages']), 'target averages array present');

// Section scoping: valuation manager sees the same shape (their section).
[$c, $r] = api('GET', '/dashboard/division?range=month', null, $valmgr);
checkCode($c, 200, 'division view for section manager → 200');
check(isset($r['data']['staff_performance']), 'manager division staff performance present');

// Own-scope officer is denied division summary.
[$c, $r] = api('GET', '/dashboard/division', null, $enf);
checkCode($c, 403, 'officer division view denied → 403');

// Drill listings.
[$c, $r] = api('GET', '/dashboard/drill?table=tasks&status=Assigned', null, $admin);
checkCode($c, 200, 'drill tasks → 200');

[$c, $r] = api('GET', '/dashboard/drill?table=discoveries&status=AC_APPROVED', null, $admin);
checkCode($c, 200, 'drill discoveries → 200');

[$c, $r] = api('GET', '/dashboard/drill?table=targets&status=Approved', null, $admin);
checkCode($c, 200, 'drill targets → 200');

[$c, $r] = api('GET', '/dashboard/drill?table=staff&section=ENF', null, $admin);
checkCode($c, 200, 'drill staff → 200');

[$c, $r] = api('GET', '/dashboard/drill?table=bogus', null, $admin);
checkCode($c, 422, 'drill unknown table → 422');

[$c, $r] = api('GET', '/dashboard/my', null, $enf);
checkCode($c, 200, 'personal dashboard still healthy → 200');

/* ============ RESUBMITTED — 17th discovery status ============ */

// Path B discovery driven to AC rejection, reopened, corrected & resubmitted.
[$c, $r] = api('POST', '/discoveries', [
    'owner_name' => 'Resubmit Owner',
    'owner_contact' => '+231770000099',
    'tin' => 'TIN-RS-1',
    'property_address' => 'Correction Lane '.uniqid(),
    'property_classification' => 'Commercial',
    'gps_lat' => 6.35,
    'gps_lng' => -10.75,
], $enf);
checkCode($c, 201, 'resubmit: discovery created');
$discR = $r['data']['id'] ?? null;
check((bool) $discR, 'resubmit: discovery id resolved');

[$c, $r] = api('POST', "/discoveries/{$discR}/submit", [], $enf);
checkCode($c, 200, 'resubmit: submit → 200');

[$c, $r] = api('POST', "/discoveries/{$discR}/review", ['manager_remarks' => 'ok'], $valmgr);
checkCode($c, 200, 'resubmit: review → 200');

[$c, $r] = api('POST', "/discoveries/{$discR}/classify", ['decision_path' => 'valuation', 'classification_decision' => 'Needs valuation'], $valmgr);
checkCode($c, 200, 'resubmit: classify → 200');

[$c, $r] = api('POST', "/discoveries/{$discR}/route-to-valuation", ['officer_id' => $valId], $valmgr);
checkCode($c, 200, 'resubmit: route-to-valuation → 200');
$valResub = $r['data']['valuation_id'] ?? null;
check((bool) $valResub, 'resubmit: valuation created from discovery');

[$c, $r] = api('POST', "/valuations/{$valResub}/descriptions", ['descriptions' => [
    ['description' => 'Shop', 'level' => 'Ground', 'area_sqft' => 800, 'quantity' => 1, 'amount' => 4000, 'depreciation_pct' => 0],
]], $val);
checkCode($c, 200, 'resubmit: valuation descriptions → 200');

[$c, $r] = api('POST', '/evidence/photos', [
    'photo_type' => 'PROPERTY_FULL_VIEW',
    'valuation_id' => $valResub,
    'data_uri' => $png,
    'gps_lat' => 6.35,
    'gps_lng' => -10.75,
], $val);
checkCode($c, 201, 'resubmit: valuation photo → 201');

[$c, $r] = api('POST', "/valuations/{$valResub}/submit", [
    'assessed_value' => 90000, 'annual_tax' => 1800, 'applicable_tax_rate' => 2, 'other_amounts' => 0,
], $val);
checkCode($c, 200, 'resubmit: valuation submit → 200');
check(($r['data']['status'] ?? '') === 'Submitted', 'resubmit: valuation Submitted');

[$c, $r] = api('POST', "/valuations/{$valResub}/review", ['decision' => 'forward_ac', 'remarks' => 'OK'], $valmgr);
checkCode($c, 200, 'resubmit: forward to AC → 200');

[$c, $r] = api('POST', "/valuations/{$valResub}/decide", ['decision' => 'reject', 'remarks' => 'Improve evidence'], $admin);
checkCode($c, 200, 'resubmit: AC rejects valuation → 200');

[$c, $r] = api('GET', "/discoveries/{$discR}", null, $admin);
check(($r['data']['status'] ?? '') === 'AC_REJECTED', 'resubmit: discovery synced AC_REJECTED');

// Reopen keeps the rejection marker; submit now refuses, resubmit moves it on.
[$c, $r] = api('POST', "/discoveries/{$discR}/reopen", [], $admin);
checkCode($c, 200, 'resubmit: reopen → 200');
check(($r['data']['status'] ?? '') === 'DISCOVERED', 'resubmit: reopened to DISCOVERED');
check(($r['data']['ac_decision'] ?? '') === 'rejected', 'resubmit: rejection marker preserved');

[$c, $r] = api('POST', "/discoveries/{$discR}/submit", [], $enf);
checkCode($c, 422, 'resubmit: plain submit refused after reopen → 422');

[$c, $r] = api('POST', "/discoveries/{$discR}/resubmit", [], $enf);
checkCode($c, 200, 'resubmit: resubmit → 200');
check(($r['data']['status'] ?? '') === 'RESUBMITTED', 'resubmit: status RESUBMITTED');

[$c, $r] = api('POST', "/discoveries/{$discR}/review", ['manager_remarks' => 'Corrected'], $valmgr);
checkCode($c, 200, 'resubmit: reviewed again → 200');
check(($r['data']['status'] ?? '') === 'UNDER_MANAGER_REVIEW', 'resubmit: back under manager review');

// Fresh records cannot resubmit (no rejection history).
[$c, $r] = api('POST', '/discoveries', ['property_address' => 'Fresh St '.uniqid(), 'gps_coordinate' => '6.3,-10.7'], $enf);
$discFresh = ($r['data']['id'] ?? null);
[$c, $r] = api('POST', "/discoveries/{$discFresh}/resubmit", [], $enf);
checkCode($c, 422, 'resubmit: fresh discovery cannot resubmit → 422');

summary('Phase 7 — Management Intelligence, Dashboards & Discovery');