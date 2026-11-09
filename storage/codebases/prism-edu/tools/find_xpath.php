<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

$files = [
    'modules/HR/payroll.php',
    'modules/HR/payroll_ledger.php',
    'modules/HR/employees.php',
    'modules/HR/leaves.php',
    'modules/HR/hr_dashboard.php',
    'modules/Finance/payroll_disbursement.php',
    'modules/Finance/fees.php',
    'modules/Finance/payments.php',
    'modules/Grading/teacher_grades.php',
    'modules/Grading/exams.php',
    'modules/LMS/assignments.php',
    'modules/LMS/attendance.php',
    'modules/LMS/timetable.php'
];

foreach ($files as $f) {
    $full = __DIR__ . '/../' . $f;
    if (!file_exists($full)) continue;

    ob_start();
    try {
        include $full;
        $html = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        continue;
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('/html/body/div[1]/div[4]/div/table/tbody/tr[5]/td[5]/span');
    if ($nodes->length > 0) {
        echo "FOUND EXACT XPATH in $f: " . $doc->saveHTML($nodes->item(0)) . "\n";
    } else {
        // Also check if any span in tr[5]/td[5] or tr/td[5] exists in table
        $nodes2 = $xpath->query('//table/tbody/tr[5]/td[5]/span');
        if ($nodes2->length > 0) {
            echo "Found //table/tbody/tr[5]/td[5]/span in $f: " . $doc->saveHTML($nodes2->item(0)) . "\n";
        }
    }
}
