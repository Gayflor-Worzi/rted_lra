<?php
// phase3_6_test.php - Enforcement, Payments, Valuations, M&E/Appeals, Reports.
require __DIR__.'/api_test_helpers.php';

$admin = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
$acct  = login('account.manager@test.lra', 'manager123');
$enf   = login('enf.officer@test.lra', 'officer123');
$val   = login('val.officer@test.lra', 'officer123');
$valmgr = login('val.manager@test.lra', 'manager123');
$acmgr = login('account.manager@test.lra', 'manager123');

check((bool) $admin, 'admin login');
check((bool) $enf, 'enf login');
check((bool) $val, 'val login');

/* ============ ENFORCEMENT ============ */

// Officer views their mobile assignments.
[$c, $r] = api('GET', '/enforcement-assignments/my?per_page=5', null, $enf);
checkCode($c, 200, 'GET /enforcement-assignments/my → 200');
$asg = $r['data']['data'] ?? [];
check(count($asg) > 0, 'officer has assignments');
$taskId = $asg[0]['id'] ?? null;
$billId = $asg[0]['property_bill_id'] ?? null;
check((bool) $taskId, 'assignment has task id');
check((bool) $billId, 'assignment has property_bill_id');

// Record a field visit.
[$c, $r] = api('POST', '/enforcement-visits', [
    'assignment_id' => $taskId,
    'property_bill_id' => $billId,
    'status' => 'Full Entry',
    'bill_delivery_status' => 'delivered',
    'notes' => 'Phase test visit',
    'gps_lat' => 6.3,
    'gps_lng' => -10.8,
    'gps_accuracy' => 4,
], $enf);
checkCode($c, 201, 'POST /enforcement-visits → 201');
$visitId = $r['data']['id'] ?? null;
check((bool) $visitId, 'visit recorded with id');
check(str_starts_with($r['data']['visit_reference'] ?? '', 'VIS-'), 'visit_reference VIS-YYYY-#####');
check(($r['data']['gps_accuracy'] ?? null) == 4, 'gps_accuracy captured on visit');
[$c, $r] = api('GET', "/property-bills/{$billId}", null, $admin);
check($r['data']['delivery_date'] !== null, 'bill delivery_date stamped on delivery');

// Permission: unauth user cannot record a visit.
[$c, $r] = api('POST', '/enforcement-visits', ['property_bill_id' => $billId, 'status' => 'x']);
checkCode($c, 401, 'record visit unauthenticated → 401');

// Discover a property (officer).
[$c, $r] = api('POST', '/enforcement/discover', [
    'property_address' => 'Phase Test St, Monrovia '.uniqid(),
    'gps_lat' => 6.31,
    'gps_lng' => -10.79,
    'owner_name' => 'Phase Test Owner',
    'documents' => ['property_photo' => 'file://x', 'nin_slip' => 'file://y'],
], $enf);
checkCode($c, 201, 'POST /enforcement/discover → 201');

// Submit a payment claim from the field.
[$c, $r] = api('POST', '/enforcement/submit-receipt', [
    'billing_number' => $asg[0]['property_bill']['document_number'] ?? '2026/45872',
    'amount' => 100.00,
    'period' => '2026 Q1',
    'receipt_number' => 'RCT-PHASE-'.uniqid(),
], $enf);
checkCode($c, 201, 'POST /enforcement/submit-receipt → 201');
$claimId = $r['data']['id'] ?? null;
check((bool) $claimId, 'payment claim id returned');

// Unknown bill reference rejected.
[$c, $r] = api('POST', '/enforcement/submit-receipt', [
    'billing_number' => 'NOPE-'.uniqid(), 'amount' => 10, 'receipt_number' => 'x',
], $enf);
checkCode($c, 422, 'submit-receipt unknown bill → 422');

/* ============ PAYMENTS ============ */

// Account manager sees pending queue.
[$c, $r] = api('GET', '/payments/queue', null, $acct);
checkCode($c, 200, 'GET /payments/queue → 200');
check(is_array($r['data']['data'] ?? null), 'queue returns data');

// Confirm the claim → creates a verified Payment.
[$c, $r] = api('POST', "/payments/verifications/{$claimId}/confirm", [
    'verified_amount' => 100.00,
    'litas_reference' => 'LITAS-PHASE-TEST',
], $acct);
if ($claimId) {
    checkCode($c, 200, 'POST /payments/verifications/{id}/confirm → 200');
    check(($r['data']['verification_status'] ?? '') === 'Confirmed', 'claim confirmed');
}

// Reject flow on a fresh claim.
[$c, $r] = api('POST', '/enforcement/submit-receipt', [
    'billing_number' => $asg[0]['property_bill']['document_number'] ?? '2026/45872',
    'amount' => 50.00,
    'receipt_number' => 'RCT-REJ-'.uniqid(),
], $enf);
$rejectId = $r['data']['id'] ?? null;
if ($rejectId) {
    [$c, $r] = api('POST', "/payments/verifications/{$rejectId}/reject", ['reason' => 'Receipt mismatch'], $acct);
    checkCode($c, 200, 'POST /payments/verifications/{id}/reject → 200');
    check(($r['data']['verification_status'] ?? '') === 'Rejected', 'claim rejected');
}

/* ============ VALUATIONS ============ */

// Officer creates a valuation draft (field form: owner contact, TIN, GPS).
[$c, $r] = api('POST', '/valuations', [
    'valuation_type' => 'new_property',
    'owner_name' => 'Phase Test Owner',
    'owner_contact' => '+231 770 000 111',
    'tin' => 'TIN-99779988',
    'property_classification' => 'Residential',
    'property_address' => 'Valuation St, Monrovia',
    'gps_coordinate' => '6.3114,-10.7988',
    'descriptions' => [
        [
            'description' => 'Ground floor commercial unit',
            'level' => 'Ground',
            'area_sqft' => 1200,
            'quantity' => 1,
            'amount' => 100000.00,
            'building_age' => 5,
            'depreciation_pct' => 10,
        ],
    ],
], $val);
checkCode($c, 201, 'POST /valuations → 201');
$valId = $r['data']['id'] ?? null;
check((bool) $valId, 'valuation draft created');

// Photo evidence attached to the draft (camera/upload endpoint).
[$c, $r] = api('POST', '/evidence/photos', [
    'photo_type' => 'PROPERTY_FULL_VIEW',
    'valuation_id' => $valId,
    'gps_coordinate' => '6.3114,-10.7988',
    'data_uri' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
], $val);
checkCode($c, 201, 'POST /evidence/photos → 201');
check(($r['data']['photo_reference'] ?? '') !== '', 'evidence photo reference generated');

// Property Description sub-table → computed value (amount × qty × (1−depr/100)).
[$c, $r] = api('GET', "/valuations/{$valId}", null, $val);
checkCode($c, 200, 'GET /valuations/{id} → 200');
check((float) ($r['data']['descriptions'][0]['value'] ?? 0) === 90000.00, 'description value 100000×1×0.9 = 90000');

// An incomplete draft (no GPS, no photo, no descriptions) must be rejected at submit.
[$c, $r] = api('POST', '/valuations', [
    'valuation_type' => 'new_property',
    'owner_name' => 'Incomplete Owner',
    'property_address' => 'Incomplete St, Monrovia',
], $val);
$incompleteId = $r['data']['id'] ?? null;
check((bool) $incompleteId, 'incomplete draft created');
[$c, $r] = api('POST', "/valuations/{$incompleteId}/submit", ['assessed_value' => 50000.00, 'annual_tax' => 2500.00], $val);
checkCode($c, 422, 'incomplete valuation cannot submit → 422');
check(str_contains($r['message'] ?? '', 'missing required fields'), 'submit guard lists missing fields');

// Submit for review (form now complete).
[$c, $r] = api('POST', "/valuations/{$valId}/submit", [
    'assessed_value' => 50000.00,
    'annual_tax' => 2500.00,
], $val);
checkCode($c, 200, 'POST /valuations/{id}/submit → 200');
check(($r['data']['status'] ?? '') === 'Submitted', 'valuation submitted');
check(($r['data']['total_property_value'] ?? null) == 90000.00, 'total property value rolled up');
check(($r['data']['total_tax_payable'] ?? null) == 2500.00, 'total tax payable rolled up');

// Manager forwards to AC.
[$c, $r] = api('POST', "/valuations/{$valId}/review", ['decision' => 'forward_ac', 'remarks' => 'OK'], $valmgr);
checkCode($c, 200, 'manager review forward_ac → 200');
check(($r['data']['status'] ?? '') === 'AC Approval', 'valuation at AC approval');

// AC approves. (Admin has all perms.)
[$c, $r] = api('POST', "/valuations/{$valId}/decide", ['decision' => 'approve', 'remarks' => 'Approve'], $admin);
checkCode($c, 200, 'AC decide approve → 200');
check(($r['data']['status'] ?? '') === 'Approved', 'valuation approved');

// Account manager marks processed in source system.
[$c, $r] = api('POST', "/valuations/{$valId}/processing", [], $acmgr);
checkCode($c, 200, 'POST /valuations/{id}/processing → 200');

// Valuations list/scope.
[$c, $r] = api('GET', '/valuations?status=Approved', null, $valmgr);
checkCode($c, 200, 'GET /valuations → 200');

/* ============ M&E + APPEALS ============ */

// Administrative/M&E can view queries; raise one.
[$c, $r] = api('GET', '/me/queries', null, $admin);
checkCode($c, 200, 'GET /me/queries → 200');

[$c, $r] = api('POST', '/me/queries', [
    'title' => 'Phase test query',
    'description' => 'Does this query sync?',
], $admin);
checkCode($c, 201, 'POST /me/queries → 201');
$queryId = $r['data']['id'] ?? null;
check((bool) $queryId, 'query id returned');

[$c, $r] = api('POST', "/me/queries/{$queryId}/respond", ['response' => 'Yes it does.'], $admin);
checkCode($c, 200, 'respond query → 200');

[$c, $r] = api('POST', "/me/queries/{$queryId}/close", [], $admin);
checkCode($c, 200, 'close query → 200');

// Appeal workflow.
[$c, $r] = api('POST', '/appeals', [
    'bill_id' => 1,
    'reason' => 'Disagree with assessment',
    'description' => 'Phase test appeal',
], $admin);
checkCode($c, 201, 'POST /appeals → 201');
$appealId = $r['data']['id'] ?? null;
check((bool) $appealId, 'appeal id returned');

[$c, $r] = api('POST', "/appeals/{$appealId}/decide", ['decision' => 'upheld', 'notes' => 'Granted'], $admin);
checkCode($c, 200, 'decide appeal → 200');

[$c, $r] = api('GET', '/appeals', null, $admin);
checkCode($c, 200, 'GET /appeals → 200');

/* ============ REPORTS ============ */

[$c, $r] = api('GET', '/reports/bills', null, $acmgr);
checkCode($c, 200, 'GET /reports/bills → 200');

[$c, $r] = api('GET', '/reports/collections', null, $acmgr);
checkCode($c, 200, 'GET /reports/collections → 200');

[$c, $r] = api('GET', '/reports/enforcement', null, $acmgr);
checkCode($c, 200, 'GET /reports/enforcement → 200');

[$c, $r] = api('GET', '/reports/valuations', null, $valmgr);
checkCode($c, 200, 'GET /reports/valuations → 200');

[$c, $r] = api('GET', '/reports/payment-queue', null, $acmgr);
checkCode($c, 200, 'GET /reports/payment-queue → 200');

// Reports require permission — Enforcement Officer lacks reports.view.
[$c, $r] = api('GET', '/reports/bills', null, $enf);
checkCode($c, 403, 'reports/bills as officer → 403');

summary('Phase 3-6 (Enforcement, Payments, Valuations, M&E/Appeals, Reports)');
