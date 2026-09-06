<?php
// auth_lifecycle_test.php - login, activation, forced/voluntary reset, sessions.
require __DIR__.'/api_test_helpers.php';

$admin = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
check($admin !== null, 'admin login');
if (! $admin) {
    exit(1);
}

// 1. Invalid credentials -> 422 with errors (not enveloped twice)
[$c, $r] = api('POST', '/auth/login', ['email' => 'wooze27@gmail.com', 'password' => 'wrong']);
checkCode($c, 422, 'bad password returns 422');
check(isset($r['errors']), 'validation errors kept native (no double-wrap)');

// 2. Create a pending user via Account Manager, then exercise lifecycle.
[$c, $r] = api('POST', '/auth/login', ['email' => 'account.manager@test.lra', 'password' => 'manager123']);
$am = $r['data']['token'] ?? null;
check($am !== null, 'account manager login');

$email = 'lifecycle'.uniqid().'@test.lra';
[$c, $new] = api('POST', '/users', [
    'full_name' => 'Lifecycle User',
    'email' => $email,
    'password' => 'TempPass-1',
    'section_id' => 2,
    'role_id' => 5,
    'supervisor_id' => 3,
    'staff_id' => 'LC'.substr(md5(uniqid()), 0, 6),
], $am);
checkCode($c, 201, 'AM creates pending account');
check($new['data']['is_active'] === false, 'new account starts inactive');
check($new['data']['must_reset_password'] === true, 'new account flags forced reset');
$newId = $new['data']['id'];

// 3. Inactive user cannot log in.
[$c, $r] = api('POST', '/auth/login', ['email' => $email, 'password' => 'TempPass-1']);
checkCode($c, 403, 'inactive login blocked with 403');

// 4. Admin activates.
[$c, $r] = api('PATCH', "/users/{$newId}/active", ['is_active' => true], $admin);
checkCode($c, 200, 'admin activates account');

// 5. Login works now, flagged must_reset.
[$c, $r] = api('POST', '/auth/login', ['email' => $email, 'password' => 'TempPass-1']);
checkCode($c, 200, 'activated login ok');
check($r['data']['must_reset'] === true, 'login flags forced reset');
$tok = $r['data']['token'];

// 6. Forced reset (must_reset) accepted without current password.
[$c, $r] = api('POST', '/auth/reset-password', ['password' => 'NewPass-123', 'password_confirmation' => 'NewPass-123'], $tok);
checkCode($c, 200, 'forced reset succeeds without current password');

// 7. Voluntary reset now requires current password.
$tok2 = login($email, 'NewPass-123');
check($tok2 !== null, 'login with new password ok');
[$c, $r] = api('POST', '/auth/reset-password', ['password' => 'EvePass-123', 'password_confirmation' => 'EvePass-123'], $tok2);
checkCode($c, 422, 'voluntary reset without current password rejected');

[$c, $r] = api('POST', '/auth/reset-password', ['current_password' => 'wrong', 'password' => 'EvePass-123', 'password_confirmation' => 'EvePass-123'], $tok2);
checkCode($c, 422, 'voluntary reset with wrong current password rejected');

[$c, $r] = api('POST', '/auth/reset-password', ['current_password' => 'NewPass-123', 'password' => 'KeepPass-456', 'password_confirmation' => 'KeepPass-456'], $tok2);
checkCode($c, 200, 'voluntary reset with correct current password ok');

// 8. Old password no longer works; old token from before reset is revoked; current session survives.
[$c, $r] = api('POST', '/auth/login', ['email' => $email, 'password' => 'NewPass-123']);
checkCode($c, 422, 'old password rejected after reset');

[$c, $r] = api('GET', '/auth/me', null, $tok);
checkCode($c, 401, 'pre-reset token revoked');

[$c, $r] = api('GET', '/auth/me', null, $tok2);
checkCode($c, 200, 'resetting session stays valid');

// 9. Self-deactivation is refused.
[$c, $r] = api('PATCH', "/users/1/active", ['is_active' => false], $admin);
checkCode($c, 422, 'self-deactivation refused');

// 10. Logout revokes the token used.
[$c, $r] = api('POST', '/auth/logout', [], $tok2);
checkCode($c, 200, 'logout ok');
[$c, $r] = api('GET', '/auth/me', null, $tok2);
checkCode($c, 401, 'logged-out token is dead');

summary('Auth lifecycle');