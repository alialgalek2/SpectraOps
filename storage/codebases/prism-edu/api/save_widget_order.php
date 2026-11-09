<?php
/**
 * API Endpoint: Save User Dashboard Widget Order & Modules Sequence
 * Persists customized drag-and-drop widget arrangement for both Today panel and Main Modules grid.
 */

require_once __DIR__ . '/../core/init.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

$user_id = Auth::id() ?: ($_SESSION['user_id'] ?? null);

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

$type = $input['type'] ?? $_POST['type'] ?? (isset($input['modules_order']) || isset($_POST['modules_order']) ? 'modules' : 'today');

if ($type === 'modules' || isset($input['modules_order']) || isset($_POST['modules_order'])) {
    // ==========================================
    // Handle Modules Sequence Customization
    // ==========================================
    $modulesOrder = $input['modules_order'] ?? $_POST['modules_order'] ?? $input['widget_order'] ?? null;
    
    if (!is_array($modulesOrder)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid modules order format. Expected array of module IDs.']);
        exit();
    }

    $cleanModules = [];
    foreach ($modulesOrder as $mId) {
        $mId = trim((string)$mId);
        if (!empty($mId) && !in_array($mId, $cleanModules)) {
            $cleanModules[] = $mId;
        }
    }

    $jsonModules = json_encode($cleanModules);

    if (isset($pdo)) {
        try {
            if (class_exists('DynamicDashboardEngine')) {
                DynamicDashboardEngine::ensureModuleOrderColumnExists($pdo);
            }
            $stmt = $pdo->prepare("UPDATE users SET dashboard_modules_order = ? WHERE user_id = ?");
            $stmt->execute([$jsonModules, $user_id]);
            $_SESSION['dashboard_modules_order'] = $cleanModules;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }

    echo json_encode([
        'success'       => true,
        'type'          => 'modules',
        'modules_order' => $cleanModules,
        'message'       => 'Module sequence updated and saved successfully'
    ]);
    exit();

} else {
    // ==========================================
    // Handle Today Panel Widgets Customization
    // ==========================================
    $order = $input['widget_order'] ?? $_POST['widget_order'] ?? null;

    if (!is_array($order)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid widget order format. Expected array of widget IDs.']);
        exit();
    }

    $allowedWidgets = ['academic_calendar', 'system_alerts', 'quick_actions', 'academic_term', 'today_tasks'];
    $cleanOrder = [];
    foreach ($order as $wId) {
        $wId = trim((string)$wId);
        if (in_array($wId, $allowedWidgets) && !in_array($wId, $cleanOrder)) {
            $cleanOrder[] = $wId;
        }
    }

    foreach ($allowedWidgets as $aw) {
        if (!in_array($aw, $cleanOrder)) {
            $cleanOrder[] = $aw;
        }
    }

    $jsonOrder = json_encode($cleanOrder);

    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET widget_order = ? WHERE user_id = ?");
            $stmt->execute([$jsonOrder, $user_id]);
            $_SESSION['widget_order'] = $cleanOrder;
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
    }

    echo json_encode([
        'success'      => true,
        'type'         => 'today',
        'widget_order' => $cleanOrder,
        'message'      => 'Widget order updated and saved successfully'
    ]);
    exit();
}
