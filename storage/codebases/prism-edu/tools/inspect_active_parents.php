<?php
require_once __DIR__ . '/../core/init.php';

echo "=== ACTIVE PARENT ACCOUNTS IN DATABASE ===\n\n";

try {
    // 1. Check parent users
    $stmt = $pdo->query("
        SELECT 
            u.user_id, 
            u.username, 
            u.email, 
            u.full_name, 
            u.role, 
            u.status,
            p.parent_id
        FROM users u
        LEFT JOIN parents p ON u.user_id = p.user_id
        WHERE u.role = 'parent' OR u.role_id = 5
        ORDER BY u.user_id ASC
    ");
    $parents = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($parents)) {
        echo "No parent accounts found in `users` table with role='parent' or role_id=5.\n";
    } else {
        foreach ($parents as $idx => $p) {
            echo "--------------------------------------------------------\n";
            echo "[" . ($idx + 1) . "] Parent User ID: " . $p['user_id'] . "\n";
            echo "    Full Name:      " . $p['full_name'] . "\n";
            echo "    Username:       " . ($p['username'] ?: '(Not Set / Uses Email)') . "\n";
            echo "    Email / Login:  " . $p['email'] . "\n";
            echo "    Role:           " . $p['role'] . "\n";
            echo "    Status:         " . ($p['status'] ?? 'Active') . "\n";
            echo "    Parent Table ID:" . ($p['parent_id'] ?: 'N/A') . "\n";

            // Linked Children
            $parent_uid = $p['user_id'];
            $parent_pid = $p['parent_id'] ?? 0;
            
            $cStmt = $pdo->prepare("
                SELECT 
                    s.student_id, 
                    s.admission_no, 
                    su.full_name AS student_name, 
                    c.class_name,
                    psm.relationship
                FROM parent_student_map psm
                JOIN students s ON psm.student_id = s.student_id
                JOIN users su ON s.user_id = su.user_id
                LEFT JOIN classes c ON s.class_id = c.class_id
                WHERE psm.parent_user_id = ? OR (psm.parent_id = ? AND ? > 0)
            ");
            $cStmt->execute([$parent_uid, $parent_pid, $parent_pid]);
            $children = $cStmt->fetchAll(PDO::FETCH_ASSOC);

            // Also check students.parent_id directly if empty
            if (empty($children)) {
                $cStmt2 = $pdo->prepare("
                    SELECT 
                        s.student_id, 
                        s.admission_no, 
                        su.full_name AS student_name, 
                        c.class_name,
                        'Guardian' AS relationship
                    FROM students s
                    JOIN users su ON s.user_id = su.user_id
                    LEFT JOIN classes c ON s.class_id = c.class_id
                    WHERE s.parent_id = ? OR (s.parent_id = ? AND ? > 0)
                ");
                $cStmt2->execute([$parent_uid, $parent_pid, $parent_pid]);
                $children = $cStmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            echo "    Linked Children (" . count($children) . "):\n";
            if (empty($children)) {
                echo "      (None explicitly mapped. In parent_portal.php, it will fallback to first 5 enrolled students)\n";
            } else {
                foreach ($children as $c) {
                    echo "      - Student ID: " . $c['student_id'] . " | " . $c['student_name'] . " (Adm: " . $c['admission_no'] . ", Class: " . ($c['class_name'] ?: 'N/A') . ") [Relation: " . ($c['relationship'] ?: 'Parent') . "]\n";
                }
            }
        }
        echo "--------------------------------------------------------\n";
    }

    // Also let's inspect all users roles distribution
    echo "\n=== SYSTEM USER ROLES BREAKDOWN ===\n";
    $roleStmt = $pdo->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
    while ($r = $roleStmt->fetch(PDO::FETCH_ASSOC)) {
        echo "Role: " . str_pad($r['role'] ?: '(empty)', 15) . " => " . $r['count'] . " users\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
