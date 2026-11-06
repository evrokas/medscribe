<?php
// A stand-in for ZPMS's patient API, for `php -S` (router script). Behaviour by path:
//   key must be "good-key" (else 401); /unavailable/... answers 503
//   guid aaaaaaaa-...  a good patient          bbbbbbbb-...  404
//   cccccccc-...       malformed JSON          dddddddd-...  answers after 6 s (client must time out at 3 s)
//   eeeeeeee-...       200 but wrong field types, and a patient with a different guid
//   search "Μαρ"       two good rows, one bad row, plus a row with a malformed guid
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_starts_with($path, '/unavailable')) { http_response_code(503); echo '{"error":"unavailable"}'; return; }
if (($_SERVER['HTTP_X_MEDSCRIBE_API_KEY'] ?? '') !== 'good-key') { http_response_code(401); echo '{"error":"unauthorized"}'; return; }
$good = ['guid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'name' => 'Μαρία Δοκιμή', 'dob' => '1980-03-15', 'gender' => 'F', 'amka' => '22222222222'];
if (preg_match('#^/api/v1/patients/search/(.+)$#', $path, $m)) {
    $t = urldecode($m[1]);
    if ($t === 'Μαρ') {
        echo json_encode(['matches' => [
            ['guid' => $good['guid'], 'name' => $good['name'], 'amka' => $good['amka']],
            ['guid' => 'ffffffff-2222-4222-8222-ffffffffffff', 'name' => 'Μάριος Άλλος', 'amka' => null],
            ['guid' => 'not-a-guid', 'name' => 'Bad Guid', 'amka' => null],
            ['guid' => 'ffffffff-3333-4333-8333-ffffffffffff', 'name' => 123, 'amka' => null],
        ]], JSON_UNESCAPED_UNICODE);
    } else echo '{"matches":[]}';
    return;
}
if (preg_match('#^/api/v1/patients/([0-9a-fA-F-]{36})$#', $path, $m)) {
    switch (strtolower(substr($m[1], 0, 8))) {
        case 'aaaaaaaa': echo json_encode(['patient' => $good], JSON_UNESCAPED_UNICODE); return;
        case 'bbbbbbbb': http_response_code(404); echo '{"error":"not found"}'; return;
        case 'cccccccc': echo '{"patient": '; return;
        case 'dddddddd': sleep(6); echo json_encode(['patient' => $good]); return;
        case 'eeeeeeee': echo json_encode(['patient' => ['guid' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa', 'name' => 'Wrong Patient']]); return;
    }
}
http_response_code(404); echo '{"error":"not found"}';
