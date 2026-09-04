<?php

require __DIR__.'/api_test_helpers.php';

function download(string $url, string $token): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/octet-stream', 'Authorization: Bearer '.$token],
        CURLOPT_TIMEOUT => 60,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [$code, $body, $type];
}

echo "Phase 8 — Titled Reports Export (CSV/PDF)\n";
echo "==========================================\n";

$admin = login('wooze27@gmail.com', 'admin123');
$officer = login('enf.officer@test.lra', 'officer123');

// ---- CSV exports -----------------------------------------------------------
[$code, $body, $type] = download('http://127.0.0.1:8000/api/v1/reports/bills/export?format=csv&start_date=2026-01-01&end_date=2026-12-31', $admin);
checkCode($code, 200, 'bills CSV export → 200');
check(stripos($type ?? '', 'text/csv') !== false, 'bills CSV content-type');
check(strpos($body, "\xEF\xBB\xBF") === 0, 'bills CSV UTF-8 BOM present');
check(stripos($body, 'Property Bills Register') !== false, 'bills CSV carries title');
check(stripos($body, 'start_date=2026-01-01') !== false, 'bills CSV carries filter context');
check(stripos($body, 'Reference') !== false && stripos($body, 'Status') !== false, 'bills CSV header row present');
check(substr_count($body, "\n") >= 4, 'bills CSV has title/meta/header/data rows');

[$code, $body] = download('http://127.0.0.1:8000/api/v1/reports/collections/export?format=csv', $admin);
checkCode($code, 200, 'collections CSV export → 200');
check(stripos($body, 'Collections Register') !== false, 'collections CSV carries title');

[$code, $body] = download('http://127.0.0.1:8000/api/v1/reports/enforcement/export?format=csv', $admin);
checkCode($code, 200, 'enforcement CSV export → 200');
check(stripos($body, 'Enforcement Task Register') !== false, 'enforcement CSV carries title');

[$code, $body] = download('http://127.0.0.1:8000/api/v1/reports/valuations/export?format=csv', $admin);
checkCode($code, 200, 'valuations CSV export → 200');
check(stripos($body, 'Valuations Register') !== false, 'valuations CSV carries title');

[$code, $body] = download('http://127.0.0.1:8000/api/v1/reports/payment-queue/export?format=csv', $admin);
checkCode($code, 200, 'payment-queue CSV export → 200');
check(stripos($body, 'Payment Verification Queue') !== false, 'payment-queue CSV carries title');

// ---- PDF export ------------------------------------------------------------
[$code, $body, $type] = download('http://127.0.0.1:8000/api/v1/reports/bills/export?format=pdf&filter=this_month', $admin);
checkCode($code, 200, 'bills PDF export → 200');
check(substr($body, 0, 4) === '%PDF', 'bills PDF is binary PDF (%PDF magic)');
check(stripos($type ?? '', 'application/pdf') !== false, 'bills PDF content-type');
check(preg_match('/\/Type\s*\/Catalog/', $body) === 1, 'bills PDF has Catalog object');
check(preg_match('/\/Type\s*\/Page\b/', $body) === 1, 'bills PDF has rendered Page object');
check(strlen($body) > 2000, 'bills PDF carries body data');

[$code, $body] = download('http://127.0.0.1:8000/api/v1/reports/valuations/export?format=pdf', $admin);
checkCode($code, 200, 'valuations PDF export → 200');
check(substr($body, 0, 4) === '%PDF', 'valuations PDF magic');

// ---- guards ----------------------------------------------------------------
[$code] = download('http://127.0.0.1:8000/api/v1/reports/bills/export?format=csv', $officer);
checkCode($code, 403, 'officer bills export denied → 403');

[$code] = download('http://127.0.0.1:8000/api/v1/reports/unknown/export?format=csv', $admin);
checkCode($code, 422, 'unknown report kind → 422');

[$code] = download('http://127.0.0.1:8000/api/v1/reports/bills/export?format=xlsx', $admin);
checkCode($code, 422, 'invalid format → 422');

summary('Phase 8 — Reports Export');