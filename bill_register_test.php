<?php
// bill_register_test.php - RETD Bill Register (LITAS-sourced identifiers only).
require __DIR__.'/api_test_helpers.php';

$am = login('account.manager@test.lra', 'manager123');
$admin = login('wooze27@gmail.com', 'admin123');
$officer = login('enf.officer@test.lra', 'officer123');
check($am && $admin && $officer, 'demo logins');

$suffix = substr(md5(uniqid('', true)), 0, 6);
$walkTaxpayer = 'Walkin Tester '.substr($suffix, 0, 4);
$docWalk = '2026/9'.substr(md5($suffix), 0, 4);
$docDirect = '2026/8'.substr(md5($suffix.'d'), 0, 4);

// 1. Walk-in bill -> Awaiting Assignment, unpaid, Pending Print, task created.
[$c, $r] = api('POST', '/property-bills', [
    'document_number' => $docWalk,
    'property_id' => 'P-'.$suffix,
    'taxpayer_name' => $walkTaxpayer,
    'tin' => '2000'.$suffix,
    'property_address' => "12 Test Rd, $suffix",
    'property_classification' => 'Commercial',
    'tax_amount' => 125000,
    'interest_charged' => 5000,
    'tax_period' => '2026',
], $am);
checkCode($c, 201, 'walk-in bill created');
check($r['data']['total_tax_due'] == 130000.00, 'total = tax + interest');
check($r['data']['case_status'] === 'Awaiting Assignment', 'walk-in queued for enforcement');
check($r['data']['recipient_type'] === 'Walk-in Taxpayer', 'recipient set to walk-in');
$walkBill = $r['data']['id'];

[$c, $r] = api('GET', "/property-bills/{$walkBill}", null, $am);
checkCode($c, 200, 'bill detail shows relations');
check(is_array($r['data']['tasks']) && count($r['data']['tasks']) === 1, 'bill has one task');
$walkTask = $r['data']['tasks'][0]['id'];

// 2. Direct-assigned bill -> also creates a task assigned to the officer.
[$c, $r] = api('POST', '/property-bills', [
    'document_number' => $docDirect,
    'property_id' => 'Q-'.$suffix,
    'tin' => '3000'.$suffix,
    'taxpayer_name' => 'Direct Tester',
    'property_address' => '5 Direct Rd',
    'tax_amount' => 80000,
    'assigned_enforcement_officer_id' => 8, // Enforcement Officer demo
], $admin);
checkCode($c, 201, 'direct-assigned bill created');
check($r['data']['case_status'] === 'Assigned', 'direct bill active immediately');
$directBill = $r['data']['id'];

// 3. Duplicate Document # from LITAS is rejected (no double-logging).
[$c, $r] = api('POST', '/property-bills', ['document_number' => $docDirect, 'property_id' => 'Z-'.$suffix, 'tin' => '4000'.$suffix, 'taxpayer_name' => 'Dup', 'property_address' => 'x', 'tax_amount' => 1], $am);
checkCode($c, 422, 'duplicate LITAS Document # rejected');
check(isset($r['errors']['document_number']), 'duplicate surfaces as document_number error');

// 4. Assign the walk-in bill to the officer.
[$c, $r] = api('POST', "/property-bills/{$walkBill}/assign", ['officer_id' => 8], $am);
checkCode($c, 200, 'walk-in bill assigned to officer');
check($r['data']['task_reference'] !== null, 'assignment returns task reference');

// 5. Assign to a non-officer is rejected.
[$c, $r] = api('POST', "/property-bills/{$walkBill}/assign", ['officer_id' => 1], $am);
checkCode($c, 422, 'assigning to non-officer rejected');

// 6. Amount edit recomputes totals and outstanding.
[$c, $r] = api('PUT', "/property-bills/{$walkBill}", ['tax_amount' => 140000], $am);
checkCode($c, 200, 'bill amount edited');
check($r['data']['total_tax_due'] == 145000.00, 'total recomputed after edit (tax + interest)');
check($r['data']['outstanding_balance'] == 145000.00, 'outstanding resyncs');

// 7. Updates never touch the LITAS identifiers.
[$c, $r] = api('PUT', "/property-bills/{$walkBill}", ['document_number' => 'HACKED/99', 'property_id' => 'H1'], $am);
checkCode($c, 200, 'update with unknown fields tolerated');
[$c, $r] = api('GET', "/property-bills/{$walkBill}", null, $am);
check($r['data']['document_number'] === $docWalk, 'Document # unchanged (LITAS-owned)');
check($r['data']['property_id'] === 'P-'.$suffix, 'Property ID unchanged (LITAS-owned)');

// 8. Global search finds bills by Document # and by taxpayer.
[$c, $r] = api('GET', '/search?q='.$docDirect, null, $admin);
checkCode($c, 200, 'search by Document #');
check(count($r['data']) === 1 && $r['data'][0]['document_number'] === $docDirect, 'search returns the direct bill');

[$c, $r] = api('GET', '/search?q='.urlencode($walkTaxpayer), null, $admin);
check($c === 200 && count($r['data'] ?? []) === 1 && ($r['data'][0]['id'] ?? null) === $walkBill, 'search by taxpayer name');

// 9. Officer scope: The walking-desk officer should not see ACCT-only view; officer only sees assigned.
[$c, $r] = api('GET', '/tasks/my', null, $officer);
checkCode($c, 200, 'officer own tasks after assignments');
$names = array_column($r['data']['data'] ?? [], 'bill_name');
check(in_array($docWalk, $names) && in_array($docDirect, $names), 'officer sees both assigned bills');

summary('Bill register');