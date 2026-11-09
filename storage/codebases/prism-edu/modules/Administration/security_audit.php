<?php
/**
 * Administration Module - Role-Based Access Control (RBAC) & System Security Audit Ledger
 * Immutable audit trail, before/after JSON diffs, sensitive action monitoring.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$msg = "";
$msg_type = "info";

// Filter params
$filter_action = $_GET['action_type'] ?? 'all';
$filter_user   = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$search_query  = trim($_GET['q'] ?? '');

$query = "
    SELECT al.*, u.username, u.full_name, u.role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE 1=1
";
$params = [];

if ($filter_action !== 'all') {
    $query .= " AND al.action = ?";
    $params[] = $filter_action;
}
if ($filter_user > 0) {
    $query .= " AND al.user_id = ?";
    $params[] = $filter_user;
}
if (!empty($search_query)) {
    $query .= " AND (al.details LIKE ? OR al.table_affected LIKE ? OR al.ip_address LIKE ?)";
    $term = "%$search_query%";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$query .= " ORDER BY al.log_id DESC LIMIT 200";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_logs = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
$recent_logins = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%LOGIN%'")->fetchColumn();
$modifications = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%UPDATE%' OR action LIKE '%MODIFIED%' OR action LIKE '%GRADE%' OR action LIKE '%FEE%'")->fetchColumn();
$critical_deletes = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action LIKE '%DELETE%'")->fetchColumn();

// Fetch System Users for filter
$users_list = $pdo->query("SELECT user_id, username, full_name, role FROM users WHERE status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Distinct actions
$action_types = [];
try {
    $action_types = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <?php require_once __DIR__ . '/../../core/header.php'; ?>
    <title><?= is_rtl() ? 'سجل التدقيق الأمني ومصفوفة الصلاحيات' : 'Security Audit Ledger & RBAC' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <?php
    Layout::start([
        'title'         => is_rtl() ? 'سجل التدقيق الأمني والرقابة' : 'Security Audit Trail & RBAC',
        'active_module' => 'admin',
        'breadcrumbs'   => [
            ['label' => __('dashboard'), 'url' => '../../dashboard.php'],
            ['label' => __('administration'), 'url' => 'index.php'],
            ['label' => is_rtl() ? 'سجل التدقيق الأمني' : 'Security Audit', 'url' => null]
        ],
        'file_path'     => 'modules/Administration/security_audit.php',
        'ptr_enabled'   => true
    ]);
    ?>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-shield-halved text-primary me-2"></i><?= is_rtl() ? 'سجل التدقيق والرقابة الأمنية غير القابل للتعديل' : 'Immutable System Audit Ledger & Security Trail' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'تتبع كل العمليات الحساسة في النظام: تسجيل الدخول، تعديل الدرجات، فواتير الرسوم، والحذف مع لقطات البيانات السابقة واللاحقة (Before / After Snapshots).' : 'Track login timestamps, IP addresses, grade alteration events, invoice fee adjustments, and record snapshots.' ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="manage_permissions.php" class="btn btn-primary btn-sm rounded-lg">
                    <i class="fa-solid fa-user-shield me-1"></i><?= is_rtl() ? 'مصفوفة الصلاحيات (RBAC)' : 'Manage RBAC Matrix' ?>
                </a>
            </div>
        </div>

        <!-- KPI Metrics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-database"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($total_logs) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي أحداث التدقيق' : 'Total Audit Events' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($recent_logins) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'جلسات تسجيل الدخول' : 'Access Logins' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($modifications) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'تعديلات البيانات الحساسة' : 'Sensitive Data Modifies' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-danger me-3">
                        <i class="fa-solid fa-trash-can"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($critical_deletes) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'عمليات الحذف' : 'Deletions Logged' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <input type="text" name="q" class="form-control form-control-sm bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'بحث بالبيانات أو IP أو الجدول...' : 'Search details, IP, table...' ?>" value="<?= htmlspecialchars($search_query) ?>">
                </div>
                <div class="col-md-3">
                    <select name="action_type" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <option value="all"><?= is_rtl() ? '-- جميع الإجراءات --' : '-- All Action Types --' ?></option>
                        <?php foreach ($action_types as $act): ?>
                            <option value="<?= htmlspecialchars($act) ?>" <?= $filter_action === $act ? 'selected' : '' ?>><?= htmlspecialchars($act) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <select name="user_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <option value="0"><?= is_rtl() ? '-- جميع المستخدمين --' : '-- All Responsible Users --' ?></option>
                        <?php foreach ($users_list as $u): ?>
                            <option value="<?= $u['user_id'] ?>" <?= $filter_user === (int)$u['user_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm rounded-lg flex-fill">
                        <i class="fa-solid fa-filter me-1"></i><?= is_rtl() ? 'تصفية' : 'Filter' ?>
                    </button>
                    <a href="security_audit.php" class="btn btn-outline-border btn-sm rounded-lg" title="Reset">
                        <i class="fa-solid fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- Audit Ledger Table -->
        <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
            <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-list me-2 text-primary"></i><?= is_rtl() ? 'سجل العمليات والتدقيق الأمني' : 'Security Audit Trail Log' ?>
                </h6>
                <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($logs) ?> Events Displayed</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr class="text-muted-foreground small text-uppercase font-bold">
                                <th class="ps-3" style="width: 170px;"><?= is_rtl() ? 'التوقيت و IP' : 'Timestamp & IP' ?></th>
                                <th><?= is_rtl() ? 'المستخدم المسؤول' : 'Responsible User' ?></th>
                                <th><?= is_rtl() ? 'الإجراء الأمني' : 'Security Action' ?></th>
                                <th><?= is_rtl() ? 'الجدول والسجل' : 'Target Entity' ?></th>
                                <th><?= is_rtl() ? 'تفاصيل العملية' : 'Audit Narrative' ?></th>
                                <th class="text-end pe-3"><?= is_rtl() ? 'لقطة البيانات' : 'Snapshot' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد سجلات تدقيق مطابقة لبحثك.' : 'No audit trail events found.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($logs as $l): 
                                    $act = $l['action'];
                                    $badge_class = 'bg-secondary text-foreground';
                                    if (strpos($act, 'DELETE') !== false) $badge_class = 'bg-danger-subtle text-danger';
                                    elseif (strpos($act, 'LOGIN') !== false) $badge_class = 'bg-success-subtle text-success';
                                    elseif (strpos($act, 'ENROLL') !== false || strpos($act, 'CREATE') !== false) $badge_class = 'bg-primary-subtle text-primary';
                                    elseif (strpos($act, 'UPDATE') !== false || strpos($act, 'MODIFIED') !== false) $badge_class = 'bg-warning-subtle text-warning';
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="small fw-bold font-monospace text-foreground"><?= htmlspecialchars($l['created_at']) ?></div>
                                            <div class="small text-muted-foreground font-monospace" style="font-size: 0.72rem;">
                                                <i class="fa-solid fa-network-wired me-1"></i><?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($l['full_name'] ?? 'System Process') ?></div>
                                            <div class="small text-muted-foreground">
                                                <span class="badge bg-secondary" style="font-size: 0.68rem;"><?= htmlspecialchars($l['role'] ?? 'System') ?></span>
                                                #<?= $l['user_id'] ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $badge_class ?> px-2.5 py-1 rounded-pill font-monospace" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars($l['action']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="font-monospace text-primary fw-semibold"><?= htmlspecialchars($l['table_affected'] ?? 'System') ?></span>
                                            <?php if (!empty($l['record_id'])): ?>
                                                <span class="text-muted-foreground font-monospace small">#<?= $l['record_id'] ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-foreground text-wrap" style="max-width: 320px;">
                                                <?= htmlspecialchars($l['details'] ?? '—') ?>
                                            </div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <?php if (!empty($l['old_values']) || !empty($l['new_values'])): ?>
                                                <button type="button" class="btn btn-outline-border btn-sm rounded-lg" 
                                                        data-bs-toggle="modal" data-bs-target="#diffModal"
                                                        onclick="viewDiff(<?= htmlspecialchars(json_encode($l)) ?>)">
                                                    <i class="fa-solid fa-code-compare text-primary"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted-foreground small">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: View Before / After Snapshot Diff -->
    <div class="modal fade" id="diffModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-card border-border">
                <div class="modal-header border-border">
                    <h5 class="modal-title font-heading fw-bold text-foreground">
                        <i class="fa-solid fa-code-compare me-2 text-primary"></i><?= is_rtl() ? 'لقطة البيانات السابقة واللاحقة (Before / After)' : 'Record State Snapshot Diff' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="small fw-bold text-danger border-bottom pb-2 mb-2">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i>Before Modification
                            </h6>
                            <pre id="diff_before" class="p-3 bg-background border border-border rounded-lg text-danger font-monospace small" style="max-height: 250px; overflow-y: auto;"></pre>
                        </div>
                        <div class="col-md-6">
                            <h6 class="small fw-bold text-success border-bottom pb-2 mb-2">
                                <i class="fa-solid fa-check me-1"></i>After Modification
                            </h6>
                            <pre id="diff_after" class="p-3 bg-background border border-border rounded-lg text-success font-monospace small" style="max-height: 250px; overflow-y: auto;"></pre>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إغلاق' : 'Close' ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php Layout::end(); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDiff(data) {
            document.getElementById('diff_before').innerText = data.old_values ? JSON.stringify(JSON.parse(data.old_values), null, 2) : '(None / New Record)';
            document.getElementById('diff_after').innerText = data.new_values ? JSON.stringify(JSON.parse(data.new_values), null, 2) : '(None / Deleted Record)';
        }
    </script>
</body>
</html>
