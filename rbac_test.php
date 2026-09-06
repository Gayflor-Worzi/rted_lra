<?php
// rbac_test.php - permission catalog, role scoping, assignment guards, protected roles.
require __DIR__.'/api_test_helpers.php';

$admin = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
$am = login('account.manager@test.lra', 'manager123');
$officer = login('enf.officer@test.lra', 'officer123');
$valManager = login('val.manager@test.lra', 'manager123');

check($admin && $am && $officer && $valManager, 'four demo accounts log in');

// 1. Permissions are exposed via the checklist and via /auth/me.
[$c, $r] = api('GET', '/admin/permission-catalog', null, $admin);
checkCode($c, 200, 'permission catalog available to admin');
check(isset($r['data']['tasks']), 'catalog has tasks module');
check(isset($r['data']['rbac']), 'catalog has rbac module');

[$c, $r] = api('GET', '/admin/permission-catalog', null, $officer);
checkCode($c, 403, 'officer cannot read permission catalog');

// 2. Enforcement Officer is scoped 'own' — cannot see other officers' tasks/bills.
[$c, $r] = api('GET', '/tasks/my', null, $officer);
checkCode($c, 200, 'officer GET /tasks/my');
check($r['data']['total'] >= 0, 'officer sees own task list');

[$c, $r] = api('GET', '/tasks', null, $officer);
checkCode($c, 200, 'officer GET /tasks (own scope)');

// 3. User creation guard: AM cannot create a System Administrator account.
[$c, $r] = api('POST', '/users', [
    'full_name' => 'Fake Admin',
    'email' => 'fakeadmin'.uniqid().'@test.lra',
    'password' => 'secret123',
    'section_id' => 1,
    'role_id' => 1,
], $am);
checkCode($c, 403, 'AM cannot create System Administrator');
check(str_contains($r['message'] ?? '', 'System Administrator'), '403 message explains the rule');

// 4. AM can create operational accounts (Enforcement Officer role).
[$c, $r] = api('POST', '/users', [
    'full_name' => 'New Enf Officer',
    'email' => 'newoff'.uniqid().'@test.lra',
    'password' => 'secret123',
    'section_id' => 3,
    'role_id' => 9,
    'supervisor_id' => 6,
    'staff_id' => 'EO'.substr(md5(uniqid()), 0, 6),
], $am);
checkCode($c, 201, 'AM creates Enforcement Officer account');

// 5. Non-staff roles cannot touch the users endpoints.
[$c, $r] = api('GET', '/users', null, $officer);
checkCode($c, 403, 'officer cannot list users');

// 6. Supervisor cannot grant roles outside its power (staff.view only, no assign_role).
[$c, $r] = api('GET', '/users', null, $valManager);
checkCode($c, 200, 'valuation manager can view staff');

// 7. Role update: admin can change permissions; System Administrator role is protected.
[$c, $r] = api('GET', '/admin/roles', null, $admin);
$mneRole = null;
foreach (($r['data'] ?? []) as $role) {
    if ($role['name'] === 'M&E Officer') {
        $mneRole = $role;
    }
}
check($mneRole !== null, 'roles list contains M&E Officer');
if ($mneRole) {
    $newPerms = $mneRole['permissions'];
    $newPerms[] = 'notifications.view'; // already there; harmless idempotent write
    [$c, $r] = api('PUT', "/admin/roles/{$mneRole['id']}", ['default_scope' => 'division', 'permissions' => $newPerms], $admin);
    checkCode($c, 200, 'admin updates M&E role checklist (idempotent)');

    [$c, $r2] = api('GET', '/admin/permission-catalog', null, $admin);
    checkCode($c, 200, 'admin can reread catalog after role update');
}

[$c, $r] = api('GET', '/admin/roles', null, $admin);
foreach (($r['data'] ?? []) as $role) {
    if ($role['name'] === 'System Administrator') {
        [$c2, $r2] = api('PUT', "/admin/roles/{$role['id']}", ['description' => 'hack'], $admin);
        checkCode($c2, 422, 'System Administrator role is protected from edits');
    }
}

// 8. Dashboard is role-aware.
[$c, $r] = api('GET', '/dashboard/my', null, $officer);
checkCode($c, 200, 'officer dashboard');
check($r['data']['scope'] === 'own', 'officer dashboard reports scope own');

[$c, $r] = api('GET', '/dashboard/my', null, $admin);
check($r['data']['scope'] === 'system', 'admin dashboard reports scope system');

// 9. lookup/roles honors assignable set.
[$c, $r] = api('GET', '/lookup/roles', null, $am);
checkCode($c, 200, 'AM lookup/roles');
$hasSysAdmin = false;
foreach (($r['data'] ?? []) as $role) {
    if ($role['name'] === 'System Administrator') {
        $hasSysAdmin = true;
    }
}
check(! $hasSysAdmin, 'AM lookup excludes System Administrator');

// 10. Non-administrator cannot broadcast.
[$c, $r] = api('POST', '/notifications/broadcast', ['message' => 'hi'], $am);
checkCode($c, 403, 'non-admin cannot broadcast');

summary('RBAC');