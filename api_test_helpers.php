<?php
// api_test_helpers.php - shared curl client + assertion helpers for the
// RETD Tasks Management API suite (Phase 1+2: core + RBAC).

$GLOBALS['__PASS'] = 0;
$GLOBALS['__FAIL'] = 0;
$GLOBALS['__FAILMSG'] = [];

function api(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    $url = (getenv('API_BASE') ?: 'http://127.0.0.1:8000').'/api/v1'.$path;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, json_decode((string) $raw, true)];
}

function check(bool $cond, string $label, ?string $detail = null): void
{
    if ($cond) {
        $GLOBALS['__PASS']++;
        echo "  PASS  $label\n";
    } else {
        $GLOBALS['__FAIL']++;
        $GLOBALS['__FAILMSG'][] = $label.($detail ? " — $detail" : '');
        echo "  FAIL  $label".($detail ? " — $detail" : '')."\n";
    }
}

function checkCode(int $actual, int $expected, string $label): void
{
    check($actual === $expected, $label, "expected HTTP $expected, got $actual");
}

function login(string $email, string $password): ?string
{
    [$code, $r] = api('POST', '/auth/login', ['email' => $email, 'password' => $password]);
    if ($code !== 200) {
        return null;
    }

    return $r['data']['token'] ?? null;
}

function summary(string $suite): void
{
    echo "\n$suite — PASS: {$GLOBALS['__PASS']}  FAIL: {$GLOBALS['__FAIL']}\n";
    if ($GLOBALS['__FAILMSG']) {
        echo "Failures:\n";
        foreach ($GLOBALS['__FAILMSG'] as $m) {
            echo "  - $m\n";
        }
    }
    exit($GLOBALS['__FAIL'] > 0 ? 1 : 0);
}