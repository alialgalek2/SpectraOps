<?php
/**
 * Administration Module - User Account Management Desk
 * Dedicated Standalone Page
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin or School Admin
Auth::requireRole(['admin', 'school_admin']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Create User Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_user') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = strtolower(trim($_POST['role'] ?? 'teacher'));
    $status    = $_POST['status'] ?? 'Active';

    $role_map = ['admin' => 1, 'teacher' => 2, 'finance' => 3, 'student' => 4, 'parent' => 5, 'hr' => 13];
    $role_id  = $role_map[$role] ?? 4;

    if (!empty($full_name) && !empty($username) && !empty($password) && !empty($role)) {
        try {
            // Check username uniqueness
            $chk = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$username]);
            if ($chk->fetch()) {
                $_SESSION['flash_msg'] = is_rtl() ? "اسم المستخدم مسجل مسبقاً، يرجى اختيار اسم آخر." : "Username already exists. Please choose a different one.";
                $_SESSION['flash_msg_type'] = "danger";
            } else {
                $hashed_pass = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, role_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $hashed_pass, $full_name, $email, $role, $role_id, $status]);
                $_SESSION['flash_msg'] = __('user_created_success', 'User account created successfully!');
                $_SESSION['flash_msg_type'] = "success";
                header("Location: users.php");
                exit();
            }
        } catch (\PDOException $e) {
            $msg = "Error creating user: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى تعبئة جميع الحقول الإلزامية." : "Please fill in all mandatory fields.";
        $msg_type = "danger";
    }
}

// Handle User Status Toggle
if (isset($_GET['toggle_user_id'])) {
    $uid = intval($_GET['toggle_user_id']);
    $target_status = $_GET['status'] ?? 'Inactive';
    if ($uid > 0 && in_array($target_status, ['Active', 'Inactive', 'Suspended'])) {
        $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ?");
        $stmt->execute([$target_status, $uid]);
        $_SESSION['flash_msg'] = is_rtl() ? "تم تحديث حالة المستخدم بنجاح." : "User status updated to $target_status.";
        $_SESSION['flash_msg_type'] = "success";
        header("Location: users.php");
        exit();
    }
}

// Filters
$role_filter = trim($_GET['role'] ?? '');
$search_q    = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM users WHERE 1=1";
$params = [];

if (!empty($role_filter)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
}
if (!empty($search_q)) {
    $sql .= " AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $term = "%$search_q%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$sql .= " ORDER BY user_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_users = (int)($pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() ?: 0);
$active_users = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn() ?: 0);
$staff_count  = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin', 'teacher', 'hr', 'finance')")->fetchColumn() ?: 0);
$portal_users = (int)($pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('student', 'parent')")->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('user_management') ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => __('user_management'),
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid py-4 px-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Welcome Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-users me-1 text-emerald-300"></i> <?= number_format($total_users) ?> <?= is_rtl() ? 'مستخدم مسجل' : 'Registered Accounts' ?>
                    </span>
                </div>
                <h2 class="font-heading fw-bold text-white mb-1">
                    <i class="fa-solid fa-users-gear me-2"></i><?= __('user_management') ?>
                </h2>
                <p class="text-white-50 mb-0 small"><?= __('user_management_sub') ?></p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light text-primary fw-bold shadow-xs rounded-lg px-3 py-2" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="fa-solid fa-user-plus me-1"></i> <?= __('create_user') ?>
                </button>
                <a href="manage_permissions.php" class="btn btn-outline-light rounded-lg px-3 py-2">
                    <i class="fa-solid fa-user-shield me-1"></i> <?= is_rtl() ? 'الصلاحيات' : 'Permissions' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- 4-Column Responsive KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي الحسابات' : 'Total Accounts' ?></span>
                    <div class="bg-accent text-primary p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fa-solid fa-users fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_users) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-circle-check me-1"></i> <?= number_format($active_users) ?></span>
                    <span class="text-muted-foreground">· Active status</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الكادر التعليمي والإداري' : 'Faculty & Staff' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(16,185,129,0.12); color: #10B981;">
                        <i class="fa-solid fa-chalkboard-user fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($staff_count) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-shield-halved me-1"></i> Staff</span>
                    <span class="text-muted-foreground">· System operators</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'حسابات الطلاب وأولياء الأمور' : 'Students & Parents' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-user-graduate fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($portal_users) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-graduation-cap me-1"></i> Portal</span>
                    <span class="text-muted-foreground">· Academic users</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الأمان والصلاحيات' : 'Access Security' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(245,158,11,0.12); color: #F59E0B;">
                        <i class="fa-solid fa-key fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1">RBAC</div>
                <div class="d-flex align-items-center gap-2 small">
                    <a href="manage_permissions.php" class="text-warning fw-semibold text-decoration-none"><i class="fa-solid fa-sliders me-1"></i> Configure Matrix</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Search & Filter Card -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-3 mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-secondary border-border"><i class="fa-solid fa-magnifying-glass text-muted-foreground"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="<?= is_rtl() ? 'بحث بالاسم أو اسم المستخدم أو البريد...' : 'Search by name, username, or email...' ?>" value="<?= htmlspecialchars($search_q) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="role" class="form-select" onchange="this.form.submit()">
                    <option value=""><?= is_rtl() ? '-- تصفية حسب الدور (الكل) --' : '-- Filter by Role (All) --' ?></option>
                    <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>><?= __('role_admin') ?></option>
                    <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>><?= __('role_teacher') ?></option>
                    <option value="hr" <?= $role_filter === 'hr' ? 'selected' : '' ?>><?= __('role_hr', 'HR Manager') ?></option>
                    <option value="finance" <?= $role_filter === 'finance' ? 'selected' : '' ?>><?= __('role_finance') ?></option>
                    <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>><?= __('role_student') ?></option>
                    <option value="parent" <?= $role_filter === 'parent' ? 'selected' : '' ?>><?= __('role_parent') ?></option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary fw-bold w-100"><?= is_rtl() ? 'تطبيق الفلتر' : 'Filter' ?></button>
                <?php if ($role_filter || $search_q): ?>
                    <a href="users.php" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Users Table Card -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <div>
                <h5 class="fw-bold font-heading mb-0 text-foreground"><i class="fa-solid fa-users text-primary me-2"></i><?= __('user_management') ?> (<?= count($users_list) ?>)</h5>
                <small class="text-muted-foreground"><?= is_rtl() ? 'قائمة حسابات الدخول المسجلة وصلاحياتها في المنظومة.' : 'List of registered system login accounts and permissions.' ?></small>
            </div>
            <button class="btn btn-primary btn-sm rounded-lg fw-bold" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fa-solid fa-user-plus me-1"></i> <?= __('create_user') ?>
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th><?= __('full_name') ?></th>
                        <th><?= __('username') ?></th>
                        <th><?= __('role') ?></th>
                        <th><?= __('email_address') ?></th>
                        <th class="text-center">Status</th>
                        <th class="text-center"><?= __('actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users_list)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted-foreground">
                                <i class="fa-solid fa-user-slash fs-3 d-block mb-2 text-muted"></i>
                                <?= is_rtl() ? 'لم يتم العثور على أي مستخدمين مطابقين للبحث.' : 'No users found matching your criteria.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users_list as $u): ?>
                            <?php
                                $roleBadgeMap = [
                                    'admin' => 'bg-danger-subtle text-danger border-danger',
                                    'school_admin' => 'bg-danger-subtle text-danger border-danger',
                                    'teacher' => 'bg-primary-subtle text-primary border-primary',
                                    'hr' => 'bg-warning-subtle text-warning border-warning',
                                    'finance' => 'bg-success-subtle text-success border-success',
                                    'student' => 'bg-info-subtle text-info border-info',
                                    'parent' => 'bg-secondary-subtle text-secondary border-secondary'
                                ];
                                $rClass = $roleBadgeMap[strtolower($u['role'])] ?? 'bg-secondary-subtle text-muted-foreground';
                            ?>
                            <tr>
                                <td><?= $u['user_id'] ?></td>
                                <td class="fw-bold text-foreground">
                                    <i class="fa-solid fa-user text-muted me-1"></i> <?= htmlspecialchars($u['full_name']) ?>
                                </td>
                                <td><code class="px-2 py-1 bg-secondary rounded border border-border"><?= htmlspecialchars($u['username']) ?></code></td>
                                <td>
                                    <span class="badge border px-2.5 py-1 rounded-full font-medium <?= $rClass ?>">
                                        <?= ucfirst(str_replace('_', ' ', $u['role'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($u['email'] ?: '—') ?></td>
                                <td class="text-center">
                                    <span class="badge <?= $u['status'] === 'Active' ? 'bg-success' : ($u['status'] === 'Suspended' ? 'bg-warning' : 'bg-danger') ?>">
                                        <?= $u['status'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <a href="manage_permissions.php?user_id=<?= $u['user_id'] ?>" class="btn btn-outline-primary" title="<?= is_rtl() ? 'تعديل الصلاحيات' : 'Manage Permissions' ?>">
                                            <i class="fa-solid fa-key"></i>
                                        </a>
                                        <?php if ($u['status'] === 'Active'): ?>
                                            <a href="users.php?toggle_user_id=<?= $u['user_id'] ?>&status=Inactive" class="btn btn-outline-danger" title="<?= is_rtl() ? 'تعطيل الحساب' : 'Deactivate' ?>" onclick="return confirm('<?= is_rtl() ? 'هل أنت متأكد من تعطيل هذا الحساب؟' : 'Deactivate this user account?' ?>');">
                                                <i class="fa-solid fa-user-slash"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="users.php?toggle_user_id=<?= $u['user_id'] ?>&status=Active" class="btn btn-outline-success" title="<?= is_rtl() ? 'تفعيل الحساب' : 'Activate' ?>">
                                                <i class="fa-solid fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border border-border bg-card shadow-lg rounded-xl">
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                <div class="modal-header border-bottom border-border">
                    <h5 class="modal-title fw-bold font-heading text-foreground"><i class="fa-solid fa-user-plus text-primary me-2"></i><?= __('create_user') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('full_name') ?> *</label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. Dr. Ahmed Hassan">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('username') ?> *</label>
                        <input type="text" name="username" class="form-control font-monospace" required placeholder="e.g. ahmed.hassan">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('email_address') ?></label>
                        <input type="email" name="email" class="form-control" placeholder="e.g. ahmed@school.edu">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('role') ?> *</label>
                        <select name="role" class="form-select" required>
                            <option value="teacher"><?= __('role_teacher') ?></option>
                            <option value="hr"><?= __('role_hr', 'HR Manager') ?></option>
                            <option value="admin"><?= __('role_admin') ?></option>
                            <option value="finance"><?= __('role_finance') ?></option>
                            <option value="student"><?= __('role_student') ?></option>
                            <option value="parent"><?= __('role_parent') ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('initial_password') ?> *</label>
                        <input type="password" name="password" class="form-control" required placeholder="••••••••">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground">Status</label>
                        <select name="status" class="form-select">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Suspended">Suspended</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top border-border">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary fw-bold px-3 py-2"><?= __('create_account') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
