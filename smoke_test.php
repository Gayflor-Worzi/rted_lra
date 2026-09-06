<?php
// smoke_test.php - core endpoint smoke test (rewritten for the RETD new model).
require __DIR__.'/api_test_helpers.php';

// Public meta / integration boundary
[$c, $r] = api('GET', '/meta');
checkCode($c, 200, 'GET /meta');
check($r['data']['source_of_bill'] === 'Source Tax System', 'meta declares source system as bill source');
check($r['data']['generates_document_numbers'] === false, 'meta says we never generate Document #');

// Auth + authenticated surface
$token = login('wooze27@gmail.com', (getenv('ADMIN_TEST_PASSWORD') ?: 'change-me-now'));
check($token !== null, 'admin login');

if ($token) {
    [$c, $r] = api('GET', '/auth/me', null, $token);
    checkCode($c, 200, 'GET /auth/me');
    check($r['data']['role'] === 'System Administrator', 'me shows role');

    [$c, $r] = api('GET', '/property-bills', null, $token);
    checkCode($c, 200, 'GET /property-bills');

    [$c, $r] = api('GET', '/tasks', null, $token);
    checkCode($c, 200, 'GET /tasks');

    [$c, $r] = api('GET', '/dashboard/my', null, $token);
    checkCode($c, 200, 'GET /dashboard/my');

    [$c, $r] = api('GET', '/notifications', null, $token);
    checkCode($c, 200, 'GET /notifications');

    [$c, $r] = api('GET', '/users', null, $token);
    checkCode($c, 200, 'GET /users');

    [$c, $r] = api('GET', '/search?q=2026/458', null, $token);
    checkCode($c, 200, 'GET /search');

    [$c, $r] = api('POST', '/auth/logout', [], $token);
    checkCode($c, 200, 'POST /auth/logout');

    [$c, $r] = api('GET', '/auth/me', null, $token);
    checkCode($c, 401, 'GET /auth/me after logout is 401');
} else {
    check(false, 'LOGIN FAILED — aborting smoke test');
}

summary('Smoke');