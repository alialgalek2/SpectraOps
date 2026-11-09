<?php
/**
 * Administration Module - Parent / Guardian Mapping & Household Relationship Engine
 * Relational Mapping, Sibling Discovery for Finance Multi-Child Discounts,
 * Access Hierarchy & Custody Restriction Flags.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'finance']);

$msg = "";
$msg_type = "info";

// Handle Map Student to Parent / Household
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link_parent_student') {
    $parent_id     = intval($_POST['parent_id'] ?? 0);
    $student_id    = intval($_POST['student_id'] ?? 0);
    $relationship  = trim($_POST['relationship'] ?? 'Guardian');
    $is_payer      = isset($_POST['is_primary_payer']) ? 1 : 0;
    $is_academic   = isset($_POST['is_primary_academic']) ? 1 : 0;
    $is_pickup     = isset($_POST['is_authorized_pickup']) ? 1 : 0;
    $has_custody   = isset($_POST['has_custody_restriction']) ? 1 : 0;
    $custody_notes = trim($_POST['custody_notes'] ?? '');

    if ($parent_id > 0 && $student_id > 0) {
        try {
            // Find parent's user_id
            $pUserId = (int)$pdo->query("SELECT user_id FROM parents WHERE parent_id = $parent_id")->fetchColumn();

            $stmt = $pdo->prepare("
                INSERT INTO parent_student_map 
                (parent_id, student_id, relationship, parent_user_id, is_primary_payer, is_primary_academic, is_authorized_pickup, has_custody_restriction, custody_notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                relationship = VALUES(relationship), parent_user_id = VALUES(parent_user_id),
                is_primary_payer = VALUES(is_primary_payer), is_primary_academic = VALUES(is_primary_academic),
                is_authorized_pickup = VALUES(is_authorized_pickup), has_custody_restriction = VALUES(has_custody_restriction),
                custody_notes = VALUES(custody_notes)
            ");
            $stmt->execute([$parent_id, $student_id, $relationship, $pUserId, $is_payer, $is_academic, $is_pickup, $has_custody, $custody_notes]);

            // Also update legacy parent_id on students table if not set
            $pdo->prepare("UPDATE students SET parent_id = ? WHERE student_id = ? AND (parent_id IS NULL OR parent_id = 0)")->execute([$parent_id, $student_id]);

            $msg = is_rtl() ? "تم ربط الطالب بحساب الأسرة بنجاح وتحديث الصلاحيات!" : "Student linked to household with designated contact hierarchy!";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Linkage Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle Unlink Sibling / Parent
if (isset($_GET['unlink_parent']) && isset($_GET['unlink_student'])) {
    $pid = intval($_GET['unlink_parent']);
    $sid = intval($_GET['unlink_student']);
    if ($pid > 0 && $sid > 0) {
        try {
            $pdo->prepare("DELETE FROM parent_student_map WHERE parent_id = ? AND student_id = ?")->execute([$pid, $sid]);
            $msg = is_rtl() ? "تم فك ارتباط الطالب بحساب ولي الأمر." : "Household association removed.";
            $msg_type = "warning";
        } catch (\PDOException $e) {
            $msg = "Unlink Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch All Parents with Linked Siblings
$households_stmt = $pdo->query("
    SELECT p.parent_id, p.national_id, p.occupation, u.full_name as parent_name, u.email as parent_email,
           (
               SELECT COUNT(*) FROM parent_student_map psm2 WHERE psm2.parent_id = p.parent_id
           ) as child_count
    FROM parents p
    JOIN users u ON p.user_id = u.user_id
    ORDER BY child_count DESC, u.full_name ASC
");
$households = $households_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch All Link Mappings for Detailed Roster
$mappings_stmt = $pdo->query("
    SELECT psm.*, 
           u_p.full_name as parent_name, u_p.email as parent_email,
           s.admission_no, COALESCE(u_s.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
           c.class_name
    FROM parent_student_map psm
    JOIN parents p ON psm.parent_id = p.parent_id
    JOIN users u_p ON p.user_id = u_p.user_id
    JOIN students s ON psm.student_id = s.student_id
    JOIN users u_s ON s.user_id = u_s.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    ORDER BY psm.has_custody_restriction DESC, u_p.full_name ASC
");
$mappings = $mappings_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Parents and Students for Dropdown Linkage
$parents_list = $pdo->query("
    SELECT p.parent_id, u.full_name, p.national_id 
    FROM parents p JOIN users u ON p.user_id = u.user_id 
    ORDER BY u.full_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$students_list = $pdo->query("
    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE s.is_deleted = 0
    ORDER BY student_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_households = count($households);
$total_links = count($mappings);
$multi_child_households = 0;
foreach ($households as $h) {
    if ((int)$h['child_count'] > 1) $multi_child_households++;
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'محرك العائلات والربط الأبوي والأشقاء' : 'Parent & Household Mapping Engine' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'العائلات وربط الأشقاء وأولياء الأمور' : 'Households & Sibling Relationship Engine',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="admissions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-plus me-1 text-success"></i> ' . (is_rtl() ? 'القبول' : 'Admissions') . '</a>',
            '<a href="student_dossier.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card me-1 text-info"></i> ' . (is_rtl() ? 'الملف الشامل' : 'Dossier') . '</a>',
            '<a href="../Finance/discounts.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-percent me-1 text-warning"></i> ' . (is_rtl() ? 'خصم الأخوة' : 'Sibling Discounts') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-people-roof text-primary me-2"></i><?= is_rtl() ? 'محرك العائلات والربط الأبوي والأشقاء' : 'Parent & Household Relationship Engine' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'ربط الأشقاء بحساب عائلي موحد، تحديد المسؤول المالي، وجهة الاتصال الأكاديمية، وإدارة تنبيهات الوصاية والقيود القانونية.' : 'Centralized sibling mapping, billing contact designations, authorized pickup hierarchies, and legal custody restriction alerts.' ?>
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#linkModal">
                    <i class="fa-solid fa-link me-1"></i><?= is_rtl() ? 'ربط طالب بولي أمر / أسرة' : 'Link Student to Household' ?>
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

        <!-- KPI Metrics -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-house-user"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_households ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي الأسر المسجلة' : 'Total Households' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-link"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_links ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'روابط التبعية العائلية' : 'Active Student Links' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-users-line"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $multi_child_households ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'أسر متعددة الأبناء (خصم أخوة)' : 'Multi-Child Families' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= is_rtl() ? 'تغذية مالية متصلة' : 'Finance Synced' ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'مزامنة مع محرك الخصومات' : 'Auto Sibling Feeds' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Household & Student Mapping Roster -->
        <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
            <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-diagram-project me-2 text-primary"></i><?= is_rtl() ? 'خريطة التبعية الأسرية والصلاحيات' : 'Household Relationship Matrix & Access Hierarchy' ?>
                </h6>
                <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($mappings) ?> Linked Relations</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr class="text-muted-foreground small text-uppercase font-bold">
                                <th class="ps-3"><?= is_rtl() ? 'ولي الأمر / الأسرة' : 'Parent / Household' ?></th>
                                <th><?= is_rtl() ? 'الطالب المسجل' : 'Enrolled Student' ?></th>
                                <th><?= is_rtl() ? 'صلة القرابة' : 'Relationship' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'الأدوار والصلاحيات' : 'Contact Roles' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'تنبيهات الحضانة' : 'Legal Custody' ?></th>
                                <th class="text-end pe-3"><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($mappings)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد روابط أسرية مسجلة حالياً.' : 'No household mappings logged yet.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mappings as $m): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($m['parent_name']) ?></div>
                                            <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($m['parent_email'] ?? 'No email') ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($m['student_name']) ?></div>
                                            <div class="small text-muted-foreground font-monospace">
                                                <?= htmlspecialchars($m['admission_no']) ?> · <?= htmlspecialchars($m['class_name'] ?? 'General') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary text-foreground"><?= htmlspecialchars($m['relationship']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex justify-content-center gap-1 flex-wrap">
                                                <?php if ($m['is_primary_payer']): ?>
                                                    <span class="badge bg-success-subtle text-success" title="Primary Billing Fee Payer">
                                                        <i class="fa-solid fa-credit-card me-1"></i><?= is_rtl() ? 'المسؤول المالي' : 'Fee Payer' ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($m['is_primary_academic']): ?>
                                                    <span class="badge bg-primary-subtle text-primary" title="Primary Academic Notice Contact">
                                                        <i class="fa-solid fa-graduation-cap me-1"></i><?= is_rtl() ? 'أكاديمي' : 'Academic' ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($m['is_authorized_pickup']): ?>
                                                    <span class="badge bg-info-subtle text-info" title="Authorized for Campus Pickup">
                                                        <i class="fa-solid fa-person-walking-luggage me-1"></i><?= is_rtl() ? 'استلام' : 'Pickup' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($m['has_custody_restriction']): ?>
                                                <span class="badge bg-danger text-white" title="<?= htmlspecialchars($m['custody_notes'] ?? 'Legal restriction') ?>">
                                                    <i class="fa-solid fa-triangle-exclamation me-1"></i><?= is_rtl() ? 'قيد وصاية قانوني' : 'Custody Alert' ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success-subtle text-success"><?= is_rtl() ? 'معتمد' : 'Standard' ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-3">
                                            <a href="households.php?unlink_parent=<?= $m['parent_id'] ?>&unlink_student=<?= $m['student_id'] ?>" 
                                               class="btn btn-outline-danger btn-sm rounded-lg" 
                                               onclick="return confirm('<?= is_rtl() ? 'هل أنت متأكد من فك ارتباط الطالب بالأسرة؟' : 'Remove this student from household?' ?>');">
                                                <i class="fa-solid fa-link-slash"></i>
                                            </a>
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

    <!-- Modal: Link Student to Parent -->
    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="link_parent_student">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-link me-2 text-primary"></i><?= is_rtl() ? 'ربط طالب بحساب الأسرة وولي الأمر' : 'Link Student to Household & Designate Roles' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اختيار ولي الأمر / حساب الأسرة' : 'Parent / Household Head' ?></label>
                                <select name="parent_id" class="form-select bg-background border-border text-foreground" required>
                                    <option value=""><?= is_rtl() ? '-- اختر ولي الأمر --' : '-- Select Parent Account --' ?></option>
                                    <?php foreach ($parents_list as $p): ?>
                                        <option value="<?= $p['parent_id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (NID: <?= htmlspecialchars($p['national_id'] ?? 'N/A') ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
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
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'صلة القرابة' : 'Relationship Type' ?></label>
                                <select name="relationship" class="form-select bg-background border-border text-foreground">
                                    <option value="Father">Father (الأب)</option>
                                    <option value="Mother">Mother (الأم)</option>
                                    <option value="Legal Guardian">Legal Guardian (الوصي القانوني)</option>
                                    <option value="Sponsor">Sponsor / Benefactor (الكفيل / المتكفل)</option>
                                </select>
                            </div>
                        </div>

                        <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">Access Hierarchy & Contact Responsibilities</h6>
                        <div class="row g-2 mb-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary_payer" value="1" id="payerCheck" checked>
                                    <label class="form-check-label small" for="payerCheck">
                                        <?= is_rtl() ? 'المسؤول المالي الأساسي عن الفواتير' : 'Primary Fee Payer / Billing' ?>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_primary_academic" value="1" id="acadCheck" checked>
                                    <label class="form-check-label small" for="acadCheck">
                                        <?= is_rtl() ? 'المستلم الأساسي للتقارير الأكاديمية' : 'Primary Academic Contact' ?>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_authorized_pickup" value="1" id="pickupCheck" checked>
                                    <label class="form-check-label small" for="pickupCheck">
                                        <?= is_rtl() ? 'مصرح له بالاستلام المدرسي' : 'Authorized for Campus Pickup' ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <h6 class="fw-bold text-danger border-bottom border-border pb-2 mb-3">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>Legal Custody & Access Restrictions
                        </h6>
                        <div class="mb-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="has_custody_restriction" value="1" id="custodyCheck">
                                <label class="form-check-label small text-danger fw-bold" for="custodyCheck">
                                    <?= is_rtl() ? 'توجد أحكام وصاية أو قيود تسليم قانونية على هذا الطرف' : 'Enforce Legal Custody Restriction / Access Alert' ?>
                                </label>
                            </div>
                            <textarea name="custody_notes" class="form-control bg-background border-border text-foreground" rows="2" placeholder="Detail court ruling, restricted contact orders, authorized guardian instructions..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تأكيد الربط العائلي' : 'Save Household Link' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
