<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING PRIVACY-FIRST TELEMETRY & ZERO-PII SCRUBBER ===\n\n";

// 1. Test PII Sanitization
$rawString = "User john.doe@example.com logged in with password=SuperSecretPassword123 and token Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9. Path: C:\\Users\\WMIS\\school_system\\core\\init.php on db mysql:host=localhost;dbname=school_db;user=root;password=rootpass. Phone: +1 555-123-4567, Card: 4111 2222 3333 4444.";

$sanitized = Telemetry::sanitize($rawString);

echo "Original string:\n$rawString\n\n";
echo "Sanitized string:\n$sanitized\n\n";

assert(!str_contains($sanitized, 'john.doe@example.com'), "Email should be redacted");
assert(str_contains($sanitized, '[REDACTED_EMAIL]'), "Email placeholder should exist");
assert(!str_contains($sanitized, 'SuperSecretPassword123'), "Password should be redacted");
assert(!str_contains($sanitized, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'), "Bearer token should be redacted");
assert(!str_contains($sanitized, 'Users\\WMIS'), "OS username directory should be normalized");
assert(!str_contains($sanitized, '4111 2222 3333 4444'), "Card number should be redacted");
assert(!str_contains($sanitized, '555-123-4567'), "Phone number should be redacted");

echo "[PASS] Multi-Pass Data Sanitization verified (Zero-PII compliant).\n\n";

// 2. Test Error Staging
Telemetry::stageError(
    "Test handled exception: database timeout for user admin@school.org with secret=MyKey123",
    __FILE__,
    __LINE__,
    'warning',
    "Trace: in C:\\xampp\\htdocs\\school_system\\modules\\Finance\\fees.php line 45",
    'ERR_TEST_01',
    'Finance'
);

$stagedCount = (int)$pdo->query("SELECT COUNT(*) FROM system_telemetry_logs WHERE error_code = 'ERR_TEST_01'")->fetchColumn();
assert($stagedCount > 0, "Staged error record should exist in system_telemetry_logs");
echo "[PASS] Error staging into `system_telemetry_logs` verified.\n\n";

// 3. Test Health Metrics
$metrics = Telemetry::getHealthMetrics();
echo "Health metrics: Health Score: {$metrics['health_score']}%, Status: {$metrics['status_label']}, Staged: {$metrics['staged_count']}\n";
assert(is_numeric($metrics['health_score']) && $metrics['health_score'] >= 0 && $metrics['health_score'] <= 100, "Health score should be valid number");
echo "[PASS] Health score metrics verified.\n\n";

// 4. Test Dispatching
$dispatchResult = Telemetry::dispatchPendingLogs(10);
echo "Dispatch result: " . json_encode($dispatchResult) . "\n";
assert($dispatchResult['success'] === true, "Telemetry dispatch should succeed");
echo "[PASS] Telemetry batch dispatch verified.\n\n";

echo "=== ALL TELEMETRY TESTS PASSED ===\n";
