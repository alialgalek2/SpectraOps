<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING DYNAMIC DASHBOARD RENDERING ACROSS ROLES ===\n\n";

$rolesToTest = [
    'admin' => ['user_id' => 1, 'name' => 'System Administrator'],
    'teacher' => ['user_id' => 9, 'name' => 'Prof. Sarah Jenkins'],
    'student' => ['user_id' => 3, 'name' => 'Ali Algale'],
    'parent' => ['user_id' => 2, 'name' => 'Ali Algale Parent'],
    'hr' => ['user_id' => 4, 'name' => 'HR Manager'],
    'finance' => ['user_id' => 5, 'name' => 'Finance Director']
];

$allPassed = true;

foreach ($rolesToTest as $testRole => $userData) {
    $_SESSION['user_id'] = $userData['user_id'];
    $_SESSION['username'] = $testRole . '_user';
    $_SESSION['role'] = $testRole;
    $_SESSION['full_name'] = $userData['name'];
    
    ob_start();
    try {
        include __DIR__ . '/../dashboard.php';
        $output = ob_get_clean();
        
        $hasModernStats = strpos($output, 'stat-card-modern') !== false;
        $hasActionCard = strpos($output, 'admin-action-card') !== false;
        
        echo "Role: " . str_pad($testRole, 10);
        
        if (in_array($testRole, ['admin', 'hr', 'finance'])) {
            if ($hasModernStats) {
                echo " [PASS] Displays Institutional KPI Cards (stat-card-modern)\n";
            } else {
                echo " [FAIL] Expected stat-card-modern for institutional role\n";
                $allPassed = false;
            }
        } else {
            // Teacher, Student, Parent
            if (!$hasModernStats && $hasActionCard) {
                echo " [PASS] Hid generic stats card & displayed custom personalized deck (admin-action-card)\n";
            } else {
                echo " [FAIL] Expected generic stats hidden and custom action cards shown. hasModernStats: " . ($hasModernStats ? 'YES' : 'NO') . ", hasActionCard: " . ($hasActionCard ? 'YES' : 'NO') . "\n";
                $allPassed = false;
            }
        }
    } catch (Throwable $t) {
        ob_end_clean();
        echo "Role: " . str_pad($testRole, 10) . " [ERROR] " . $t->getMessage() . " in " . $t->getFile() . ":" . $t->getLine() . "\n";
        $allPassed = false;
    }
}

echo "\n" . ($allPassed ? "ALL ROLES TESTED SUCCESSFULLY: 100% PASS!" : "SOME TESTS FAILED") . "\n";
