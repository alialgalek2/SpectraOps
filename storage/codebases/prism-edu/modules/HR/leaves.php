<?php
/**
 * HR Module - Staff Leave Tracking & Approval Portal
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'hr', 'teacher', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();
$full_name = Auth::fullName();
$is_hr_admin = in_array($user_role, ['admin', 'school_admin', 'hr']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle New Leave Application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply_leave') {
    $target_user_id = ($is_hr_admin && !empty($_POST['staff_user_id'])) ? intval($_POST['staff_user_id']) : $user_id;
    $leave_type     = $_POST['leave_type'] ?? 'Annual';
    $start_date     = $_POST['start_date'] ?? '';
    $end_date       = $_POST['end_date'] ?? '';
    $reason         = trim($_POST['reason'] ?? '');

    if (!empty($start_date) && !empty($end_date) && !empty($reason)) {
        $days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
        if ($days < 1) $days = 1;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO staff_leaves (user_id, leave_type, start_date, end_date, total_days, reason, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending')
            ");
            $stmt->execute([$target_user_id, $leave_type, $start_date, $end_date, $days, $reason]);

            // Dispatch inter-module event
            EventDispatcher::dispatch('leave.applied', [
                'user_id'    => $target_user_id,
                'leave_type' => $leave_type,
                'days'       => $days
            ]);

            $_SESSION['flash_msg'] = "Leave application for $days day(s) submitted successfully for administrative review!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: leaves.php");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error submitting leave: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please specify valid start/end dates and a reason for leave.";
        $msg_type = "danger";
    }
}

// Handle Admin/HR Approval / Rejection
if ($is_hr_admin && isset($_GET['action_leave']) && isset($_GET['id'])) {
    $lid = intval($_GET['id']);
    $status = $_GET['action_leave'] === 'approve' ? 'Approved' : 'Rejected';

    $upd = $pdo->prepare("UPDATE staff_leaves SET status = ?, approved_by = ?, actioned_at = NOW() WHERE leave_id = ?");
    $upd->execute([$status, $user_id, $lid]);

    $_SESSION['flash_msg'] = "Leave request #$lid has been $status.";
    $_SESSION['flash_msg_type'] = $status === 'Approved' ? 'success' : 'warning';
    header("Location: leaves.php");
    exit();
}

// Fetch Staff Leaves List
if ($is_hr_admin) {
    $leavesStmt = $pdo->query("
        SELECT l.*, u.full_name as staff_name, u.role as staff_role, au.full_name as approver_name
        FROM staff_leaves l
        JOIN users u ON l.user_id = u.user_id
        LEFT JOIN users au ON l.approved_by = au.user_id
        ORDER BY l.applied_at DESC
    ");
} else {
    $leavesStmt = $pdo->prepare("
        SELECT l.*, u.full_name as staff_name, u.role as staff_role, au.full_name as approver_name
        FROM staff_leaves l
        JOIN users u ON l.user_id = u.user_id
        LEFT JOIN users au ON l.approved_by = au.user_id
        WHERE l.user_id = ?
        ORDER BY l.applied_at DESC
    ");
    $leavesStmt->execute([$user_id]);
}
$leaves = $leavesStmt->fetchAll();

// All staff list for admin/HR application
$staffList = $pdo->query("SELECT user_id, full_name, role FROM users WHERE role IN ('teacher', 'admin', 'hr', 'finance') AND status = 'Active' ORDER BY full_name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('staff_leaves') ?> - HR Desk</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('staff_leaves'),
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-people-roof me-1 text-primary"></i> ' . (is_rtl() ? 'سجل الموظفين' : 'Staff') . '</a>',
        '<a href="payroll.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> ' . __('staff_payroll') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-person-walking-arrow-right me-2"></i><?= __('staff_leaves') ?></h2>
                <p class="mb-0 text-white-50 small"><?= __('staff_leaves_sub') ?></p>
            </div>
            <button class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs" data-bs-toggle="modal" data-bs-target="#applyLeaveModal">
                <i class="fa-solid fa-calendar-plus me-1"></i> <?= __('apply_leave') ?>
            </button>
        </div>
    </div>

    <!-- Leaves Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold mb-0 text-foreground"><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Staff Leave Requests (<?= count($leaves) ?>)</h5>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Staff Member</th>
                        <th>Leave Type</th>
                        <th>Duration</th>
                        <th>Days</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Applied On</th>
                        <?php if ($user_role === 'admin'): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                        <tr>
                            <td colspan="<?= $user_role === 'admin' ? 9 : 8 ?>" class="text-center text-muted py-4">No leave requests found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $l): ?>
                            <tr>
                                <td><?= $l['leave_id'] ?></td>
                                <td class="fw-bold">
                                    <?= htmlspecialchars($l['staff_name']) ?>
                                    <small class="d-block text-muted"><?= ucfirst($l['staff_role']) ?></small>
                                </td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($l['leave_type']) ?></span></td>
                                <td>
                                    <i class="fa-regular fa-calendar me-1 text-primary"></i>
                                    <?= date('M d', strtotime($l['start_date'])) ?> &rarr; <?= date('M d, Y', strtotime($l['end_date'])) ?>
                                </td>
                                <td class="fw-bold"><?= $l['total_days'] ?> day(s)</td>
                                <td><?= htmlspecialchars($l['reason']) ?></td>
                                <td>
                                    <?php if ($l['status'] === 'Approved'): ?>
                                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i> Approved</span>
                                    <?php elseif ($l['status'] === 'Rejected'): ?>
                                        <span class="badge bg-danger"><i class="fa-solid fa-xmark me-1"></i> Rejected</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-hourglass-half me-1"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= date('M d, Y', strtotime($l['applied_at'])) ?></td>
                                <?php if ($user_role === 'admin'): ?>
                                    <td>
                                        <?php if ($l['status'] === 'Pending'): ?>
                                            <a href="?action_leave=approve&id=<?= $l['leave_id'] ?>" class="btn btn-sm btn-success fw-bold" title="Approve">
                                                <i class="fa-solid fa-check"></i>
                                            </a>
                                            <a href="?action_leave=reject&id=<?= $l['leave_id'] ?>" class="btn btn-sm btn-danger fw-bold ms-1" title="Reject">
                                                <i class="fa-solid fa-xmark"></i>
                                            </a>
                                        <?php else: ?>
                                            <small class="text-muted">Reviewed by <?= htmlspecialchars($l['approver_name'] ?? 'Admin') ?></small>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Apply Leave Modal -->
<div class="modal fade" id="applyLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="apply_leave">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-calendar-plus me-2 text-warning"></i><?= __('apply_leave') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($user_role === 'admin'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Select Staff Member</label>
                            <select name="staff_user_id" class="form-select fw-bold">
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?= $s['user_id'] ?>" <?= $s['user_id'] == $user_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['full_name']) ?> (<?= ucfirst($s['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold"><?= __('leave_type') ?> <span class="text-danger">*</span></label>
                        <select name="leave_type" class="form-select fw-bold" required>
                            <option value="Annual">Annual Paid Leave</option>
                            <option value="Sick">Sick / Medical Leave</option>
                            <option value="Casual">Casual / Personal Leave</option>
                            <option value="Maternity">Maternity / Paternity Leave</option>
                            <option value="Study">Study / Examination Leave</option>
                            <option value="Unpaid">Unpaid Leave</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control fw-bold" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control fw-bold" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Detailed Reason / Handover Plan <span class="text-danger">*</span></label>
                        <textarea name="reason" class="form-control" rows="3" placeholder="State reason and substitute teacher arrangements..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary fw-bold px-4"><?= __('submit') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
