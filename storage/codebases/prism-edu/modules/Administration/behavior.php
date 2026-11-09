<?php
/**
 * Administration Module - Student Behavioral Conduct & Incident Registry
 * Merits, Commendations, Disciplinary Infractions & Remedial Action Tracking
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$msg = "";
$msg_type = "info";

// Handle New Incident / Merit Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_record') {
    $student_id   = intval($_POST['student_id'] ?? 0);
    $record_type  = in_array($_POST['record_type'] ?? '', ['Merit', 'Infraction']) ? $_POST['record_type'] : 'Merit';
    $category     = trim($_POST['category'] ?? 'General');
    $title        = trim($_POST['title'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $points       = intval($_POST['points'] ?? ($record_type === 'Merit' ? 1 : -1));
    $inc_date     = $_POST['incident_date'] ?? date('Y-m-d');
    $action_taken = trim($_POST['action_taken'] ?? '');
    $status       = $_POST['status'] ?? 'Reported';
    $notified     = isset($_POST['parent_notified']) ? 1 : 0;

    if ($student_id > 0 && !empty($title)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO student_behavior_records 
                (student_id, record_type, category, title, description, points, incident_date, reported_by, action_taken, status, parent_notified) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$student_id, $record_type, $category, $title, $description, $points, $inc_date, Auth::id(), $action_taken, $status, $notified]);

            $msg = (is_rtl() ? "تم تسجيل واقعة السلوك / التكريم بنجاح!" : "Behavioral incident / commendation recorded successfully!");
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error recording behavior: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $record_id    = intval($_POST['record_id'] ?? 0);
    $status       = $_POST['status'] ?? 'Reported';
    $action_taken = trim($_POST['action_taken'] ?? '');

    if ($record_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE student_behavior_records SET status = ?, action_taken = ? WHERE record_id = ?");
            $stmt->execute([$status, $action_taken, $record_id]);
            $msg = is_rtl() ? "تم تحديث حالة السلوك والإجراء المتخذ!" : "Record status & corrective action updated!";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Filter Records
$filter_type = $_GET['type'] ?? 'all';
$query = "
    SELECT br.*, 
           s.admission_no, COALESCE(u_s.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name,
           u_r.full_name as reporter_name
    FROM student_behavior_records br
    JOIN students s ON br.student_id = s.student_id
    JOIN users u_s ON s.user_id = u_s.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN users u_r ON br.reported_by = u_r.user_id
";
$params = [];
if ($filter_type !== 'all') {
    $query .= " WHERE br.record_type = ?";
    $params[] = $filter_type;
}
$query .= " ORDER BY br.incident_date DESC, br.record_id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_merits = (int)$pdo->query("SELECT COUNT(*) FROM student_behavior_records WHERE record_type = 'Merit'")->fetchColumn();
$total_infractions = (int)$pdo->query("SELECT COUNT(*) FROM student_behavior_records WHERE record_type = 'Infraction'")->fetchColumn();
$pending_investigations = (int)$pdo->query("SELECT COUNT(*) FROM student_behavior_records WHERE status IN ('Reported', 'Under Review')")->fetchColumn();

// Students for Dropdown
$students_list = $pdo->query("
    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE s.is_deleted = 0
    ORDER BY student_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'سجل السلوك والانضباط والتكريمات' : 'Behavior & Disciplinary Registry' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'السلوك والانضباط المدرسي' : 'Student Behavior & Disciplinary Registry',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="attendance_desk.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-check me-1 text-success"></i> ' . (is_rtl() ? 'الحضور' : 'Attendance') . '</a>',
            '<a href="student_dossier.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card me-1 text-info"></i> ' . (is_rtl() ? 'الملف الشامل' : 'Dossier') . '</a>',
            '<a href="households.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-people-roof me-1 text-primary"></i> ' . (is_rtl() ? 'العائلات' : 'Households') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-shield-heart text-warning me-2"></i><?= is_rtl() ? 'سجل السلوك والانضباط والتكريمات المدرسية' : 'Behavioral Conduct, Merits & Incident Registry' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'رصد التكريمات والإنجازات الأكاديمية والمخالفات السلوكية مع متابعة الإجراءات العلاجية وإخطارات أولياء الأمور.' : 'Track positive citizenship merits, honor rolls, infractions, parental conferences, and corrective disciplinary actions.' ?>
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#newRecordModal">
                    <i class="fa-solid fa-plus me-1"></i><?= is_rtl() ? 'تسجيل واقعة / تكريم' : 'Log Incident / Merit' ?>
                </button>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-info me-2 fs-5"></i>
                <div><?= htmlspecialchars($msg) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- KPI Cards Grid -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-star"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_merits ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'تكريمات وشهادات تميز' : 'Merits & Commendations' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-danger me-3">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_infractions ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'مخالفات وإجراءات سلوكية' : 'Infractions & Policy Violations' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $pending_investigations ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'قضايا قيد المراجعة والتحقيق' : 'Pending Administrative Review' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="small fw-bold text-muted-foreground me-2"><?= is_rtl() ? 'نوع السجل:' : 'Filter Type:' ?></span>
                <a href="behavior.php?type=all" class="btn btn-sm <?= $filter_type === 'all' ? 'btn-primary' : 'btn-outline-border' ?> rounded-pill px-3">
                    <?= is_rtl() ? 'الكل' : 'All Records' ?>
                </a>
                <a href="behavior.php?type=Merit" class="btn btn-sm <?= $filter_type === 'Merit' ? 'btn-success text-white' : 'btn-outline-border' ?> rounded-pill px-3">
                    <i class="fa-solid fa-star me-1"></i><?= is_rtl() ? 'التكريمات والإنجازات' : 'Merits Only' ?>
                </a>
                <a href="behavior.php?type=Infraction" class="btn btn-sm <?= $filter_type === 'Infraction' ? 'btn-danger text-white' : 'btn-outline-border' ?> rounded-pill px-3">
                    <i class="fa-solid fa-circle-exclamation me-1"></i><?= is_rtl() ? 'المخالفات والانضباط' : 'Infractions Only' ?>
                </a>
            </div>
        </div>

        <!-- Records Table -->
        <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
            <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-clipboard-list me-2 text-primary"></i><?= is_rtl() ? 'سجل الحوادث والتكريمات' : 'Conduct Ledger' ?> (<?= count($records) ?>)
                </h6>
                <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($records) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr class="text-muted-foreground small text-uppercase font-bold">
                                <th class="ps-3"><?= is_rtl() ? 'التاريخ والنوع' : 'Date & Type' ?></th>
                                <th><?= is_rtl() ? 'الطالب والفصل' : 'Student & Section' ?></th>
                                <th><?= is_rtl() ? 'تفاصيل الواقعة / الإنجاز' : 'Incident / Title' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'النقاط' : 'Points' ?></th>
                                <th><?= is_rtl() ? 'الإجراء المتخذ' : 'Action Taken' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                                <th class="text-end pe-3"><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد سجلات مطابقة في هذا الفلتر.' : 'No behavioral records logged matching criteria.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($records as $r): 
                                    $is_merit = ($r['record_type'] === 'Merit');
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="small fw-bold font-monospace"><?= htmlspecialchars($r['incident_date']) ?></div>
                                            <span class="badge bg-<?= $is_merit ? 'success' : 'danger' ?>-subtle text-<?= $is_merit ? 'success' : 'danger' ?> mt-1">
                                                <i class="fa-solid fa-<?= $is_merit ? 'award' : 'triangle-exclamation' ?> me-1"></i>
                                                <?= htmlspecialchars($r['record_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($r['student_name']) ?></div>
                                            <div class="small text-muted-foreground font-monospace">
                                                <?= htmlspecialchars($r['admission_no']) ?> · <?= htmlspecialchars($r['class_name'] ?? 'General') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-foreground"><?= htmlspecialchars($r['title']) ?></div>
                                            <div class="small text-muted-foreground"><?= htmlspecialchars($r['category']) ?>: <?= htmlspecialchars($r['description'] ?? '') ?></div>
                                        </td>
                                        <td class="text-center font-monospace fw-bold fs-6 <?= $is_merit ? 'text-success' : 'text-danger' ?>">
                                            <?= $r['points'] > 0 ? "+{$r['points']}" : $r['points'] ?>
                                        </td>
                                        <td>
                                            <div class="small text-foreground"><?= htmlspecialchars($r['action_taken'] ?? 'Pending action') ?></div>
                                            <?php if ($r['parent_notified']): ?>
                                                <span class="badge bg-info-subtle text-info" style="font-size: 0.68rem;"><i class="fa-solid fa-bell me-1"></i>Parent Notified</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-<?= $r['status'] === 'Resolved' || $r['status'] === 'Closed' ? 'success' : 'warning' ?>-subtle text-<?= $r['status'] === 'Resolved' || $r['status'] === 'Closed' ? 'success' : 'warning' ?>">
                                                <?= htmlspecialchars($r['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button type="button" class="btn btn-outline-border btn-sm rounded-lg" 
                                                    data-bs-toggle="modal" data-bs-target="#editModal"
                                                    onclick="populateEditModal(<?= htmlspecialchars(json_encode($r)) ?>)">
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
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

    <!-- Modal: New Record -->
    <div class="modal fade" id="newRecordModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="create_record">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-shield-heart me-2 text-warning"></i><?= is_rtl() ? 'تسجيل واقعة سلوك / إنجاز' : 'Log Behavioral Event / Merit' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اختيار الطالب' : 'Select Student' ?></label>
                                <select name="student_id" class="form-select bg-background border-border text-foreground" required>
                                    <option value=""><?= is_rtl() ? '-- اختر الطالب --' : '-- Select Student --' ?></option>
                                    <?php foreach ($students_list as $s): ?>
                                        <option value="<?= $s['student_id'] ?>"><?= htmlspecialchars($s['student_name']) ?> (<?= htmlspecialchars($s['class_name'] ?? 'General') ?> - #<?= htmlspecialchars($s['admission_no']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'نوع السجل' : 'Record Classification' ?></label>
                                <select name="record_type" class="form-select bg-background border-border text-foreground" required>
                                    <option value="Merit">Merit / Commendation (تكريم / إشادة تميز)</option>
                                    <option value="Infraction">Disciplinary Infraction (مخالفة سلوكية)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'التصنيف' : 'Category' ?></label>
                                <input type="text" name="category" class="form-control bg-background border-border text-foreground" placeholder="e.g. Academic Honor, Classroom Disruption, Leadership" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تاريخ الواقعة' : 'Incident Date' ?></label>
                                <input type="date" name="incident_date" class="form-control bg-background border-border text-foreground" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'عنوان السجل' : 'Record Title' ?></label>
                                <input type="text" name="title" class="form-control bg-background border-border text-foreground" placeholder="e.g. First Prize in Science Exhibition / Late submission repeat" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'التفاصيل والوصف' : 'Description & Narrative' ?></label>
                                <textarea name="description" class="form-control bg-background border-border text-foreground" rows="2" placeholder="Full incident context..."></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'النقاط السلوكية (+ / -)' : 'Points (+ / -)' ?></label>
                                <input type="number" name="points" class="form-control bg-background border-border text-foreground" value="1" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الإجراء المتخذ / التكريم' : 'Action Taken / Honor' ?></label>
                                <input type="text" name="action_taken" class="form-control bg-background border-border text-foreground" placeholder="Certificate / Detention / Counseling">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="parent_notified" value="1" id="parentNotifCheck" checked>
                                    <label class="form-check-label small" for="parentNotifCheck">
                                        <?= is_rtl() ? 'إخطار ولي الأمر تلقائياً' : 'Notify Parent via Portal Notice' ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تسجيل الواقعة' : 'Save Record' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Edit Status -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="record_id" id="edit_record_id" value="0">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground"><?= is_rtl() ? 'تحديث حالة السلوك' : 'Update Record Status' ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الحالة' : 'Status' ?></label>
                            <select name="status" id="edit_status" class="form-select bg-background border-border text-foreground">
                                <option value="Reported">Reported</option>
                                <option value="Under Review">Under Review</option>
                                <option value="Resolved">Resolved</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الإجراء العلاجي النهائي' : 'Final Remedial / Corrective Action' ?></label>
                            <input type="text" name="action_taken" id="edit_action_taken" class="form-control bg-background border-border text-foreground">
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><?= is_rtl() ? 'تحديث' : 'Save Changes' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function populateEditModal(data) {
            document.getElementById('edit_record_id').value = data.record_id;
            document.getElementById('edit_status').value = data.status;
            document.getElementById('edit_action_taken').value = data.action_taken || '';
        }
    </script>
</body>
</html>
