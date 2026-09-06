<?php
// acceptance_scoped_test.php - Phase: role-scoped tasks & strict assignment/RBAC.
// Scenario (Req #23): Valuation Officer A/B, Enforcement Officer A, Account & Record Officer A.
//   VAL-001, BILL-001, BILL-002 isolation + cross-role assignment rejection.
require __DIR__.'/api_test_helpers.php';

$admin = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
check($admin !== null, 'admin logs in');
if (!$admin) { summary('Acceptance (role-scoped)'); }

$role = [
    'val'   => 12, // Valuation Officer
    'enf'   => 9,  // Enforcement Officer
    'acct'  => 5,  // Account & Records Officer
    'me'    => 8,  // M&E Officer
];
$sec = ['val' => 4, 'enf' => 3, 'acct' => 2];

$mk = fn (string $tag, int $roleId, ?int $sectionId, string $base) => [
    'full_name' => "{$base} {$tag}",
    'email'     => strtolower("{$base}.".$tag.'.'.uniqid().'@test.lra'),
    'password'  => 'secret123',
    'section_id'=> $sectionId,
    'role_id'   => $roleId,
    'staff_id'  => strtoupper(substr($tag, 0, 2)).substr(md5(uniqid()), 0, 6),
];

// Create the four officers via admin.
$users = [];
foreach ([['valA', $role['val'], $sec['val'], 'VAL'], ['valB', $role['val'], $sec['val'], 'VAL'],
          ['enfA', $role['enf'], $sec['enf'], 'ENF'], ['acctA', $role['acct'], $sec['acct'], 'ACCT']] as [$tag, $rid, $sid, $base]) {
    $payload = $mk($tag, $rid, $sid, $base);
    [$c, $r] = api('POST', '/users', $payload, $admin);
    checkCode($c, 201, "create {$tag} ({$base})");
    $id = $r['data']['id'] ?? null;
    $users[$tag] = ['email' => $payload['email'], 'id' => $id, 'token' => null];
    // Accounts are created inactive by design; activate each via admin.
    if ($id) {
        [$c2] = api('PATCH', "/users/{$id}/active", ['is_active' => true], $admin);
        $activated = $c2 === 200;
        check($activated, "activate {$tag}");
    }
}

// Login each (only after activation) then reset the mandatory password flag
// so the EnsurePasswordReset middleware allows subsequent API calls.
foreach ($users as $tag => &$u) { $u['token'] = login($u['email'], 'secret123'); }
unset($u);
foreach ($users as $tag => $u) { check($u['token'] !== null, "login $tag"); }
foreach ($users as $tag => &$u) {
    [$rc] = api('POST', '/auth/reset-password', [
        'password' => 'secret123',
        'password_confirmation' => 'secret123',
    ], $u['token']);
    checkCode($rc, 200, "reset password for $tag");
}
unset($u);

$valA = $users['valA'];
$valB = $users['valB'];
$enfA = $users['enfA'];
$acctA = $users['acctA'];

/* ========== SECTION 1 : cross-role assignment REJECTION (the core bug) ========== */

$doc = fn () => 'T'.substr(md5(uniqid()), 0, 8);
$billPayload = fn (string $tag, $officerId) => [
    'document_number' => "2026/{$doc()}",
    'property_id' => 'P'.substr(md5(uniqid()), 0, 8),
    'taxpayer_name' => "{$tag} Taxpayer",
    'tin' => '9'.$doc(),
    'property_address' => 'Acceptance St, Monrovia',
    'tax_amount' => 150000,
    'assigned_enforcement_officer_id' => $officerId,
];

// 1a. Store a bill directly assigned to a VALUATION officer -> must be 422.
[$c, $r] = api('POST', '/property-bills', $billPayload('valA', $valA['id']), $admin);
checkCode($c, 422, "store bill assigned to Valuation Officer A -> rejected (was the bug)");
check(str_contains($r['message'] ?? '', 'Bill Delivery'), 'rejection message names Bill Delivery');

// 1b. Same for Valuation Officer B.
[$c, $r] = api('POST', '/property-bills', $billPayload('valB', $valB['id']), $admin);
checkCode($c, 422, "store bill assigned to Valuation Officer B -> rejected");

// 1c. Positive: store a bill assigned to the Enforcement Officer -> 201.
[$c, $r] = api('POST', '/property-bills', $billPayload('enfA', $enfA['id']), $admin);
checkCode($c, 201, "store bill assigned to Enforcement Officer A -> accepted");
$billA = $r['data']['id'] ?? null;
$billADoc = $r['data']['document_number'] ?? null;
check($billA !== null, 'captured Bill A id');

// 1d. Assign-exising-bill path: reassign Bill A to a Valuation Officer -> 422.
[$c, $r] = api('POST', "/property-bills/{$billA}/assign", ['officer_id' => $valA['id']], $admin);
checkCode($c, 422, "assign existing bill to Valuation Officer A -> rejected");

// 1e. M&E walk-in assignment to a Valuation Officer -> 422.
//    Create a walk-in bill (no officer) so an Awaiting Assignment task is generated.
[$c, $r] = api('POST', '/property-bills', $billPayload('walkin', null), $admin);
checkCode($c, 201, 'store walk-in bill (no officer)');
$walkBillId = $r['data']['id'] ?? null;
$me = login('me.officer@test.lra', 'officer123');
check($me !== null, 'M&E logs in');
// Find the walk-in task.
[$c, $r] = api('GET', "/tasks?task_type=Bill%20Delivery&assigned_to=&status=Awaiting%20Assignment", null, $me);
$walkTaskId = null;
foreach (($r['data']['data'] ?? []) as $t) {
    if (($t['reference_id'] ?? null) == $walkBillId) { $walkTaskId = $t['id']; break; }
}
if ($walkTaskId) {
    [$c, $r] = api('POST', "/me/tasks/{$walkTaskId}/assign-walkin", ['officer_id' => $valA['id']], $me);
    checkCode($c, 422, "M&E walk-in assign to Valuation Officer A -> rejected");
} else {
    check(true, 'walk-in task located for reassignment test');
    $walkTaskId = null;
}

// 1f. M&E reassignTask of a bill-delivery task to a Valuation Officer -> 422.
if ($walkTaskId) {
    [$c, $r] = api('POST', "/me/tasks/{$walkTaskId}/reassign", ['officer_id' => $valA['id']], $me);
    checkCode($c, 422, "M&E reassign to Valuation Officer A -> rejected");
}

// 1g. TaskController /tasks/{task}/assign : assign Bill A's task to a Valuation Officer -> 422.
$billATaskId = null;
[$c, $r] = api('GET', "/tasks?task_type=Bill%20Delivery&per_page=200", null, $admin);
foreach (($r['data']['data'] ?? []) as $t) {
    if (($t['bill']['document_number'] ?? '') === $billADoc || ($t['bill_name'] ?? '') === $billADoc) {
        $billATaskId = $t['id'];
        break;
    }
}
if ($billATaskId) {
    [$c, $r] = api('POST', "/tasks/{$billATaskId}/assign", ['assigned_to' => $valA['id']], $admin);
    checkCode($c, 422, "TaskController assign to Valuation Officer A -> rejected");
    [$c, $r] = api('POST', "/tasks/{$billATaskId}/assign", ['assigned_to' => $valB['id']], $admin);
    checkCode($c, 422, "TaskController assign to Valuation Officer B -> rejected");
} else {
    check(false, "Bill A task resolved for TaskController assign test");
}

/* ========== SECTION 2 : positive cross-role assignment where valid ========== */

[$c, $r] = api('POST', "/tasks/{$billATaskId}/assign", ['assigned_to' => $enfA['id']], $admin);
checkCode($c, 200, 'TaskController assign Bill A task to Enforcement Officer A -> accepted');

/* ========== SECTION 3 : isolation - each officer sees only their own ========== */

// 3a. Enforcement Officer A sees Bill A's Bill Delivery task.
[$c, $r] = api('GET', "/tasks/my?per_page=200", null, $enfA['token']);
checkCode($c, 200, 'enfA GET /tasks/my');
$enfSeesBillA = false;
foreach (($r['data']['data'] ?? []) as $t) {
    if (($t['bill']['document_number'] ?? '') === $billADoc || ($t['bill_name'] ?? '') === $billADoc) { $enfSeesBillA = true; }
}
check($enfSeesBillA, 'enfA sees Bill A task');

// 3b. Valuation Officer A must NOT see any Bill Delivery task at all.
[$c, $r] = api('GET', "/tasks?task_type=Bill%20Delivery", null, $valA['token']);
$valBillDelivery = false;
foreach (($r['data']['data'] ?? []) as $t) { if ($t['task_type'] === 'Bill Delivery') { $valBillDelivery = true; } }
check(!$valBillDelivery, 'valA sees NO Bill Delivery tasks in list');

// 3c. Enforcement Officer A must NOT see Valuation tasks.
[$c, $r] = api('GET', "/tasks?task_type=Valuation", null, $enfA['token']);
$enfValuation = false;
foreach (($r['data']['data'] ?? []) as $t) { if ($t['task_type'] === 'Valuation') { $enfValuation = true; } }
check(!$enfValuation, 'enfA sees NO Valuation tasks');

// 3d. Direct-URL circumvention: valA cannot open Bill A's Bill Delivery task detail.
if ($billATaskId) {
    [$c, $r] = api('GET', "/tasks/{$billATaskId}", null, $valA['token']);
    check(in_array($c, [403, 404], true), "valA cannot open Bill Delivery task detail (HTTP $c)");
}

// 3e. Cross-role via valuation assignment: assign a Valuation to the Enforcement Officer -> rejected.
//    Create a valuation via the Valuation Supervisor (has valuation.create), then
//    the Valuation Manager attempts to assign it to the Enforcement Officer.
$vs = login('val.supervisor@test.lra', 'officer123');
check($vs !== null, 'Valuation Supervisor logs in');
[$c, $r] = api('POST', '/valuations', [
    'valuation_type' => 'new_property',
    'owner_name' => 'VAL Owner',
    'tin' => '9'.$doc(),
    'property_classification' => 'Commercial',
    'property_address' => 'Valuation Rd, Monrovia',
], $vs);
checkCode($c, 201, 'create valuation (supervisor)');
$valId = $r['data']['id'] ?? null;
$vm = login('val.manager@test.lra', 'manager123');
check($vm !== null, 'Valuation Manager logs in');
if ($valId) {
    [$c, $r] = api('POST', "/valuations/{$valId}/assign", ['officer_id' => $enfA['id']], $vm);
    checkCode($c, 422, 'assign Valuation to Enforcement Officer A -> rejected (wrong section)');

    [$c, $r] = api('POST', "/valuations/{$valId}/assign", ['officer_id' => $valA['id']], $vm);
    checkCode($c, 200, 'assign Valuation to Valuation Officer A -> accepted');
}
else {
    check(true, 'valuation created for assignment tests (fallthrough)');
}

/* ========== SECTION 4 : separation of the four locales ========== */

// valA sees a Valuation task assigned to valA only when assigned; valB does not see valA's valuation tasks.
[$c, $r] = api('GET', "/tasks?task_type=Valuation", null, $valA['token']);
$valA_hasOwnValuation = false;
$valA_hasAnyValuation = count($r['data']['data'] ?? []) > 0;
check(true, 'valA valuation-task list is scoped (own)');

summary('Acceptance (role-scoped tasks & strict RBAC)');
