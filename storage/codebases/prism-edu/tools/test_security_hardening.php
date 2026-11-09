<?php
/**
 * Test Suite: Production Security Hardening & Concurrency Tuning
 * Validates Phase 1 (Code-level security) and Phase 2 (Performance & deployment tuning).
 */

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../controllers/UploadController.php';

echo "=== Production Security & Concurrency Verification ===\n";

$pass = 0;
$fail = 0;

function test_assert($desc, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $pass++;
    } else {
        echo "  [FAIL] $desc\n";
        $fail++;
    }
}

// 1. Database Connection Factory Tests
$pdo1 = \App\Config\Database::getConnection();
test_assert("App\\Config\\Database returns PDO instance", $pdo1 instanceof PDO);
test_assert("ATTR_ERRMODE is ERRMODE_EXCEPTION", $pdo1->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
test_assert("ATTR_DEFAULT_FETCH_MODE is FETCH_ASSOC", $pdo1->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC);
test_assert("ATTR_EMULATE_PREPARES is false (Native prepared statements)", empty($pdo1->getAttribute(PDO::ATTR_EMULATE_PREPARES)));

$pdo2 = Database::getConnection();
test_assert("core/Database::getConnection() bridges to App\\Config\\Database singleton", $pdo1 === $pdo2);

// 2. CSRF Protection Helpers
$token = generateCsrfToken();
test_assert("generateCsrfToken() generates valid 64-char hex token", is_string($token) && strlen($token) === 64 && ctype_xdigit($token));
test_assert("verifyCsrfToken() validates genuine token", verifyCsrfToken($token) === true);
test_assert("verifyCsrfToken() rejects forged token", verifyCsrfToken('forged_token_12345') === false);
test_assert("verifyCsrfToken() rejects null/empty token", verifyCsrfToken('') === false && verifyCsrfToken(null) === false);

// 3. UploadController Security Validation
// 3.1 Reject file error
$resErr = \App\Controllers\UploadController::handleLogoUpload(['error' => UPLOAD_ERR_NO_FILE]);
test_assert("UploadController rejects upload errors", $resErr['success'] === false);

// 3.2 Reject oversized file (> 2MB)
$resOversize = \App\Controllers\UploadController::handleLogoUpload([
    'error' => UPLOAD_ERR_OK,
    'size' => 3 * 1024 * 1024,
    'tmp_name' => __DIR__ . '/test_db_factory.php'
]);
test_assert("UploadController rejects files > 2MB", $resOversize['success'] === false);

// 3.3 Reject forbidden MIME type (e.g. text/plain or PHP)
$dummyText = __DIR__ . '/dummy_script.txt';
file_put_contents($dummyText, "echo 'hello';");
$resMime = \App\Controllers\UploadController::handleLogoUpload([
    'error' => UPLOAD_ERR_OK,
    'size' => 100,
    'tmp_name' => $dummyText
]);
@unlink($dummyText);
test_assert("UploadController blocks non-whitelisted MIME types", $resMime['success'] === false && str_contains($resMime['message'], 'Invalid file format'));

// 3.4 Accept valid PNG logo
$validPng = __DIR__ . '/../uploads/branding/prism_logo_dark_canvas.png';
if (file_exists($validPng)) {
    $tempCopy = __DIR__ . '/temp_test_logo.png';
    copy($validPng, $tempCopy);
    $resValid = \App\Controllers\UploadController::handleLogoUpload([
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($tempCopy),
        'tmp_name' => $tempCopy
    ]);
    @unlink($tempCopy);
    test_assert("UploadController accepts valid PNG", $resValid['success'] === true);
    test_assert("UploadController assigns unguessable filename (prism_logo_*.png)", str_starts_with($resValid['fileName'], 'prism_logo_'));
    // Clean up created test upload
    if (!empty($resValid['fileName'])) {
        @unlink(__DIR__ . '/../storage/uploads/branding/' . $resValid['fileName']);
        @unlink(__DIR__ . '/../uploads/branding/' . $resValid['fileName']);
    }
}

// 4. Live HTTP Security Headers & Login CSRF
$ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 5]]);
$resp = @file_get_contents('http://localhost:8080/school_system/login.php', false, $ctx);
$headers = implode("\n", $http_response_header ?? []);

test_assert("HTTP header X-Frame-Options: SAMEORIGIN present", stripos($headers, 'X-Frame-Options: SAMEORIGIN') !== false);
test_assert("HTTP header X-Content-Type-Options: nosniff present", stripos($headers, 'X-Content-Type-Options: nosniff') !== false);
test_assert("HTTP header X-XSS-Protection present", stripos($headers, 'X-XSS-Protection') !== false);
test_assert("HTTP header Referrer-Policy present", stripos($headers, 'Referrer-Policy: strict-origin-when-cross-origin') !== false);
test_assert("HTTP header Content-Security-Policy present", stripos($headers, 'Content-Security-Policy') !== false);
test_assert("Login form contains hidden CSRF token input", str_contains($resp, 'name="csrf_token"'));

// 5. Apache .htaccess & Upload Folders Protection
test_assert("public/.htaccess exists", file_exists(__DIR__ . '/../public/.htaccess'));
test_assert("uploads/.htaccess exists", file_exists(__DIR__ . '/../uploads/.htaccess'));
test_assert("storage/uploads/.htaccess exists", file_exists(__DIR__ . '/../storage/uploads/.htaccess'));

$rootHt = file_get_contents(__DIR__ . '/../.htaccess');
test_assert(".htaccess has Options -Indexes", str_contains($rootHt, 'Options -Indexes'));
test_assert(".htaccess has mod_deflate compression", str_contains($rootHt, 'mod_deflate.c'));
test_assert(".htaccess has mod_expires caching", str_contains($rootHt, 'mod_expires.c'));

// 6. Database Indexes
function hasIndex($pdo, $table, $indexName) {
    try {
        $stmt = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

test_assert("users index idx_users_role_status exists", hasIndex($pdo1, 'users', 'idx_users_role_status'));
test_assert("students unique index idx_students_student_code exists", hasIndex($pdo1, 'students', 'idx_students_student_code'));
test_assert("students index idx_students_enrollment_class exists", hasIndex($pdo1, 'students', 'idx_students_enrollment_class'));
test_assert("student_attendance index idx_student_att_class_date exists", hasIndex($pdo1, 'student_attendance', 'idx_student_att_class_date'));
test_assert("audit_logs index idx_audit_created_action exists", hasIndex($pdo1, 'audit_logs', 'idx_audit_created_action'));

// 7. Production Settings Profile
test_assert(".env.production exists", file_exists(__DIR__ . '/../.env.production'));
$envProd = file_get_contents(__DIR__ . '/../.env.production');
test_assert(".env.production has APP_ENV=production", str_contains($envProd, 'APP_ENV=production'));
test_assert(".env.production has DB_NAME=prism_edu", str_contains($envProd, 'DB_NAME=prism_edu'));

echo "\nSummary: $pass passed, $fail failed.\n";
if ($fail === 0) {
    echo "SUCCESS: All security hardening and concurrency checks passed!\n";
    exit(0);
} else {
    echo "FAILURE: Some security checks failed.\n";
    exit(1);
}
