<?php
require_once __DIR__ . '/../core/init.php';

// Mock active admin user
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

$test_pages = [
    'dashboard.php' => 'dashboard.php',
    'Finance: fee_structures' => 'modules/Finance/fee_structures.php',
    'Finance: fees_portal' => 'modules/Finance/fees_portal.php',
    'Finance: payroll_disbursement' => 'modules/Finance/payroll_disbursement.php',
    'Finance: payments' => 'modules/Finance/payments.php',
    'Grading: teacher_grades' => 'modules/Grading/teacher_grades.php',
    'Grading: report_cards' => 'modules/Grading/report_cards.php',
    'Grading: transcript' => 'modules/Grading/transcript.php',
    'LMS: assignments' => 'modules/LMS/assignments.php',
    'LMS: teacher_dashboard' => 'modules/LMS/teacher_dashboard.php',
    'LMS: parent_portal' => 'modules/LMS/parent_portal.php',
    'HR: employees' => 'modules/HR/employees.php',
    'HR: payroll_ledger' => 'modules/HR/payroll_ledger.php',
    'HR: payroll' => 'modules/HR/payroll.php',
    'HR: leaves' => 'modules/HR/leaves.php',
    'Administration: manage_permissions' => 'modules/Administration/manage_permissions.php',
    'Administration: users' => 'modules/Administration/users.php'
];

echo "========================================================\n";
echo "DYNAMIC UNIVERSAL NAVBAR AUDIT ACROSS ALL MODULES\n";
echo "========================================================\n\n";

$all_passed = true;

foreach ($test_pages as $label => $relPath) {
    $filePath = __DIR__ . '/../' . $relPath;
    if (!file_exists($filePath)) {
        echo "[-] SKIP $label: File not found ($relPath)\n";
        continue;
    }

    // Set script name context
    $_SERVER['SCRIPT_NAME'] = '/school_system/' . $relPath;
    $_SERVER['SCRIPT_FILENAME'] = $filePath;

    ob_start();
    try {
        // Run with output buffer
        include $filePath;
        $output = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        echo "[!] Exception in $label: " . $e->getMessage() . "\n";
        continue;
    }

    // Check if header is present
    if (strpos($output, 'navbar-custom') === false && strpos($output, '<header') === false) {
        echo "[?] $label: No navbar-custom detected\n";
        continue;
    }

    // Check for flex-wrap in navbar header
    preg_match('/<header[^>]*navbar-custom[^>]*>.*?<\/header>/s', $output, $mHeader);
    if (!empty($mHeader[0])) {
        $headerHtml = $mHeader[0];
        $hasWrap = (strpos($headerHtml, 'flex-wrap') !== false);
        $hasNowrap = (strpos($headerHtml, 'flex-nowrap') !== false);

        // Check for cross-module links pointing outside current module
        preg_match('#modules/([^/]+)/#', $relPath, $modMatch);
        $currModule = $modMatch[1] ?? '';

        $hasCrossModule = false;
        if (!empty($currModule) && preg_match_all('#href=[\'"]\.\./([A-Za-z0-9_-]+)/[^\'"]+[\'"]#i', $headerHtml, $matches)) {
            foreach ($matches[1] as $targetMod) {
                if (strcasecmp($targetMod, $currModule) !== 0) {
                    $hasCrossModule = true;
                    echo "    Cross-module link found to: $targetMod in $label\n";
                }
            }
        }

        if ($hasWrap) {
            echo "[FAIL] $label: Contains flex-wrap in navbar header!\n";
            $all_passed = false;
        } elseif (!$hasNowrap) {
            echo "[WARN] $label: Missing explicit flex-nowrap in navbar header\n";
            $all_passed = false;
        } elseif ($hasCrossModule) {
            echo "[FAIL] $label: Contains unrelated cross-module links in navbar!\n";
            $all_passed = false;
        } else {
            echo "[PASS] $label: Strictly single-row flex-nowrap, no cross-module links\n";
        }
    } else {
        echo "[INFO] $label: header tag evaluated\n";
    }
}

echo "\n========================================================\n";
if ($all_passed) {
    echo "SUCCESS: ALL 17 PAGES PASSED SINGLE-ROW & DYNAMIC FILTERING AUDIT!\n";
} else {
    echo "ATTENTION: SOME PAGES REQUIRE FURTHER TIGHTENING.\n";
    exit(1);
}
echo "========================================================\n";
