<?php
declare(strict_types=1);

/**
 * PRISM EDU - Hybrid Delta-Sync Architecture Verification Suite
 */

echo "=== PRISM EDU Hybrid Delta-Sync Architecture Test Suite ===\n";

$passCount = 0;
$failCount = 0;

function assert_check(string $desc, bool $cond): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $passCount++;
    } else {
        echo "  [FAIL] $desc\n";
        $failCount++;
    }
}

/**
 * Robust HTTP POST helper working with or without curl extension
 */
function http_post_json(string $url, array $data): array {
    $payload = json_encode($data);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => (int)$code, 'body' => (string)$body];
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
                'content' => $payload,
                'ignore_errors' => true,
                'timeout' => 10
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $hdr, $m)) {
                    $code = (int)$m[1];
                }
            }
        }
        return ['code' => $code, 'body' => (string)$body];
    }
}

require_once __DIR__ . '/../config/Database.php';
$pdo = \App\Config\Database::getConnection();

// --- 1. Database Table & Index Verification ---
echo "\n--- 1. Database Table & Index Verification ---\n";
$tables = $pdo->query("SHOW TABLES LIKE 'attendance'")->fetchAll(PDO::FETCH_COLUMN);
assert_check("Table 'attendance' exists", in_array('attendance', $tables));

$cols = $pdo->query("DESCRIBE attendance")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, 'Field');
assert_check("'attendance' table contains 'id'", in_array('id', $colNames));
assert_check("'attendance' table contains 'student_id'", in_array('student_id', $colNames));
assert_check("'attendance' table contains 'class_id'", in_array('class_id', $colNames));
assert_check("'attendance' table contains 'status'", in_array('status', $colNames));
assert_check("'attendance' table contains 'attendance_date'", in_array('attendance_date', $colNames));
assert_check("'attendance' table contains 'updated_at'", in_array('updated_at', $colNames));

$indexes = $pdo->query("SHOW INDEX FROM attendance")->fetchAll(PDO::FETCH_ASSOC);
$indexedCols = array_column($indexes, 'Column_name');
assert_check("Index exists on 'updated_at'", in_array('updated_at', $indexedCols));

// --- 2. Server-Side Delta Endpoint Direct Execution ---
echo "\n--- 2. Server-Side Delta Endpoint Direct Execution ---\n";
$testDate = '2026-09-13';
$testStudentId = 99999;
$testClassId = 101;

// Clean up any existing test row
$pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND class_id = ?")->execute([$testStudentId, $testClassId]);

// Ingest an inbound mutation via sync endpoint
$testPayload = [
    'last_synced_at' => '1970-01-01 00:00:00',
    'mutations' => [
        [
            'entity' => 'attendance',
            'payload' => [
                'student_id' => $testStudentId,
                'class_id'   => $testClassId,
                'status'     => 'Present',
                'date'       => $testDate
            ]
        ]
    ]
];

$res = http_post_json('http://localhost:8080/school_system/api/v1/sync.php', $testPayload);
assert_check("HTTP POST to /api/v1/sync.php returns 200 OK (Got {$res['code']})", $res['code'] === 200);

$resData = json_decode($res['body'] ?: '', true);
assert_check("Response has status == 'success'", isset($resData['status']) && $resData['status'] === 'success');
assert_check("Response contains 'server_time'", isset($resData['server_time']) && !empty($resData['server_time']));
assert_check("Response contains 'updates' array", isset($resData['updates']) && is_array($resData['updates']));

// Verify mutation was committed to database
$stmtCheck = $pdo->prepare("SELECT * FROM attendance WHERE student_id = ? AND class_id = ?");
$stmtCheck->execute([$testStudentId, $testClassId]);
$insertedRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
assert_check("Mutation successfully inserted record into 'attendance' table", !empty($insertedRow) && $insertedRow['status'] === 'Present');

// --- 3. Delta Fetching with Timestamp Watermark ---
echo "\n--- 3. Delta Fetching with Timestamp Watermark ---\n";
// Request sync with last_synced_at equal to just before the insert
$justBefore = date('Y-m-d H:i:s', time() - 30);
$res2 = http_post_json('http://localhost:8080/school_system/api/v1/sync.php', ['last_synced_at' => $justBefore]);
$data2 = json_decode($res2['body'] ?: '', true);

$foundOurRow = false;
if (!empty($data2['updates'])) {
    foreach ($data2['updates'] as $up) {
        if ((int)$up['student_id'] === $testStudentId) {
            $foundOurRow = true;
            break;
        }
    }
}
assert_check("Delta query returns newly modified record when updated_at > last_sync", $foundOurRow);

// Request sync with future timestamp watermark -> should return empty updates
$futureTime = '2099-12-31 23:59:59';
$res3 = http_post_json('http://localhost:8080/school_system/api/v1/sync.php', ['last_synced_at' => $futureTime]);
$data3 = json_decode($res3['body'] ?: '', true);
assert_check("Future last_synced_at yields 0 updates (deltas properly filtered)", empty($data3['updates']));

// Clean up test row
$pdo->prepare("DELETE FROM attendance WHERE student_id = ? AND class_id = ?")->execute([$testStudentId, $testClassId]);

// --- 4. Client-Side Script Verification ---
echo "\n--- 4. Client-Side Script Verification (assets/js/prism-sync.js) ---\n";
$jsPath = __DIR__ . '/../assets/js/prism-sync.js';
assert_check("prism-sync.js file exists", file_exists($jsPath));

$jsContent = (string)file_get_contents($jsPath);
assert_check("prism-sync.js defines class PrismDataSync", str_contains($jsContent, 'class PrismDataSync'));
assert_check("prism-sync.js instantiates window.PrismSyncEngine", str_contains($jsContent, 'window.PrismSyncEngine = new PrismDataSync()'));
assert_check("prism-sync.js handles online event listener", str_contains($jsContent, "window.addEventListener('online'"));
assert_check("prism-sync.js handles visibilitychange event listener", str_contains($jsContent, "document.addEventListener('visibilitychange'"));
assert_check("prism-sync.js pauses sync when document.hidden", str_contains($jsContent, 'if (document.hidden)'));
assert_check("prism-sync.js dispatches 'prism:data-updated' event", str_contains($jsContent, "'prism:data-updated'"));
assert_check("prism-sync.js manages 'prism_offline_queue' localStorage key", str_contains($jsContent, 'prism_offline_queue'));
assert_check("prism-sync.js manages 'prism_last_sync' localStorage key", str_contains($jsContent, 'prism_last_sync'));
assert_check("prism-sync.js defines queueMutation() helper", str_contains($jsContent, 'queueMutation(entity, payload)'));

// --- 5. Global Footer Script Inclusion ---
echo "\n--- 5. Global Footer Script Inclusion ---\n";
$langContent = (string)file_get_contents(__DIR__ . '/../includes/lang.php');
assert_check("includes/lang.php includes prism-sync.js in render_platform_footer", str_contains($langContent, 'assets/js/prism-sync.js'));

$dashContent = (string)file_get_contents(__DIR__ . '/../dashboard.php');
assert_check("dashboard.php includes prism-sync.js", str_contains($dashContent, 'assets/js/prism-sync.js'));

echo "\nSummary: $passCount passed, $failCount failed.\n";
if ($failCount === 0) {
    echo "SUCCESS: All 25 Delta-Sync Architecture tests passed flawlessly!\n";
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
