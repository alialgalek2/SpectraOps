<?php
/**
 * API Controller: System Update Operations
 * Handles AJAX update checking, automated migrations execution, and patch deployment.
 */

require_once __DIR__ . '/../core/init.php';

header('Content-Type: application/json; charset=UTF-8');

// Access Control: Admin / School Admin Only
if (!Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. System Update permissions required.']);
    exit();
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'check':
        require dirname(__DIR__) . '/controllers/AdminUpdateController.php';
        exit();

    case 'apply':
        require dirname(__DIR__) . '/controllers/AdminUpdateController.php';
        exit();

    case 'check_update':
        $force = !empty($input['force']) || !empty($_GET['force']);
        $updateInfo = SystemUpdater::checkForUpdates($force);
        echo json_encode($updateInfo);
        break;

    case 'run_migrations':
        $migResult = SystemUpdater::runPendingMigrations();
        echo json_encode($migResult);
        break;

    case 'apply_update':
        $targetVersion = trim((string)($input['version_number'] ?? 'v2.5.0'));
        $targetBuild   = (int)($input['build_number'] ?? 2500);
        $releaseName   = trim((string)($input['release_name'] ?? 'EduMaster Nova Release'));
        $changelog     = (array)($input['changelog'] ?? []);
        $adminUser     = Auth::user()['full_name'] ?? 'Administrator';

        $deployResult = SystemUpdater::applyUpdate($targetVersion, $targetBuild, $releaseName, $changelog, $adminUser);
        echo json_encode($deployResult);
        break;

    case 'get_history':
        $history = SystemUpdater::getVersionHistory();
        echo json_encode(['success' => true, 'history' => $history]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action specified.']);
        break;
}
