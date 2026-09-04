<?php
// task_engine_test.php - unified task lifecycle, state machine, scope, history.
require __DIR__.'/api_test_helpers.php';

$am = login('account.manager@test.lra', 'manager123');
$admin = login('wooze27@gmail.com', 'admin123');
$officer = login('enf.officer@test.lra', 'officer123');
check($am && $admin && $officer, 'demo logins');

// Fresh task via a direct-assigned bill (unique refs so state is predictable).
$suffix = substr(md5(uniqid('', true)), 0, 6);
[$c, $bill] = api('POST', '/property-bills', [
    'document_number' => '2026/5'.substr(md5($suffix), 0, 4),
    'property_id' => 'T-'.$suffix,
    'tin' => '5000'.$suffix,
    'taxpayer_name' => 'Task Tester',
    'property_address' => '9 Engine Rd',
    'tax_amount' => 45000,
    'assigned_enforcement_officer_id' => 8,
], $admin);
checkCode($c, 201, 'seeded bill for task engine');
$billId = $bill['data']['id'];

[$c, $r] = api('GET', "/property-bills/{$billId}", null, $admin);
$task = $r['data']['tasks'][0];
$tid = $task['id'];
checkCode($c, 200, 'bill detail');
check($task['status'] === 'Assigned', 'new task starts Assigned (direct assignment)');
check(str_starts_with($task['task_reference'], 'TASK-'.date('Y').'-'), 'internal TASK-YYYY-##### reference format');
check(! preg_match('/^(2026\/)/', $task['task_reference']), 'task reference never collides with LITAS Doc #');

// Legal walking path through the lifecycle.
$moves = [
    'Assigned' => 'Out for Delivery',
    'Out for Delivery' => 'Delivered',
    'Delivered' => 'Payment Follow-up',
];
$prev = null;
foreach ($moves as $from => $to) {
    [$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => $to, 'action' => 'auto', 'remarks' => 'test'], $officer);
    checkCode($c, 200, "officer transition $from -> $to");
    $prev = $r['data']['task']['status'] ?? null;
}
check($prev === 'Payment Follow-up', 'task reached Payment Follow-up');

// Illegal jump: Payment Follow-up -> Paid is not allowed by the state machine.
[$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => 'Paid', 'action' => 'mark-paid'], $officer);
checkCode($c, 422, 'illegal transition (Follow-up -> Paid) rejected');
check(str_contains($r['message'] ?? '', 'Illegal task transition'), 'rejection explains state machine');

// Officer may escalate.
[$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => 'Escalated', 'action' => 'escalate', 'remarks' => 'no response'], $officer);
checkCode($c, 200, 'officer escalates payment follow-up');

// But officer has no permission to force 'Paid' (payments.claim is claim, not verify) and illegal anyway.
[$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => 'Paid', 'action' => 'verify'], $officer);
check(in_array($c, [403, 422]), 'officer cannot force Paid (validation + state machine)');

// History is recorded with actor + reasons.
[$c, $r] = api('GET', "/tasks/{$tid}", null, $admin);
checkCode($c, 200, 'admin reads task detail');
check(is_array($r['data']['history']) && count($r['data']['history']) >= 4, 'history chain present');
$last = end($r['data']['history']);
check($last['to_status'] === 'Escalated', 'history records escalate step');
check($last['performed_by'] === 8, 'history records performing user');

// Scope enforcement: a different Enforcement Officer (section ENF) exists? Only officer 8.
// Use Account & Records Officer (id 5, scope own) - should NOT see task 8's task.
$acctOfficer = login('account.officer@test.lra', 'account123');
check($acctOfficer !== null, 'account officer login');
[$c, $r] = api('GET', "/tasks/{$tid}", null, $acctOfficer);
checkCode($c, 403, 'out-of-scope user cannot read the task');

// Account Manager (section scope for ENF? No — ACCT) also out of scope for ENF task.
[$c, $r] = api('GET', "/tasks/{$tid}", null, $am);
checkCode($c, 403, 'account manager (ACCT) cannot read ENF task');

// Supervisor sees reports: Enforcement Supervisor (id 7) is in ENF section.
$sup = login('enf.supervisor@test.lra', 'officer123');
check($sup !== null, 'enf supervisor login');
[$c, $r] = api('GET', "/tasks/{$tid}", null, $sup);
checkCode($c, 200, 'enf supervisor sees team tasks');

// Closed tasks cannot transition.
[$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => 'Closed', 'action' => 'close', 'remarks' => 'done'], $admin);
checkCode($c, 200, 'admin closes resolved case');
[$c, $r] = api('POST', "/tasks/{$tid}/transition", ['to_status' => 'Assigned', 'action' => 'reopen'], $admin);
checkCode($c, 422, 'closed task refuses transitions');

summary('Task engine');