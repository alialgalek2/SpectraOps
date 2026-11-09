<?php
/**
 * Administration Module - Admissions & Student Onboarding Intake Engine
 * Pipeline: Inquiry -> Application Intake -> Document Check -> Placement Test -> Auto-ID STU-YYYY-XXXX
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$msg = "";
$msg_type = "info";

// Handle New Application Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_application') {
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $dob          = trim($_POST['dob'] ?? '');
    $gender       = in_array($_POST['gender'] ?? '', ['Male', 'Female']) ? $_POST['gender'] : 'Male';
    $grade_apply  = intval($_POST['grade_applying_for'] ?? 1);
    $parent_name  = trim($_POST['parent_name'] ?? '');
    $parent_phone = trim($_POST['parent_phone'] ?? '');
    $parent_email = trim($_POST['parent_email'] ?? '');
    $score        = !empty($_POST['placement_test_score']) ? floatval($_POST['placement_test_score']) : null;
    $status       = $_POST['status'] ?? 'Application Received';
    $notes        = trim($_POST['reviewer_notes'] ?? '');

    if (!empty($first_name) && !empty($last_name) && !empty($parent_name) && !empty($parent_phone)) {
        try {
            // Generate Application Number: APP-YYYY-XXXX
            $year = date('Y');
            $count = (int)$pdo->query("SELECT COUNT(*) FROM student_applications WHERE application_no LIKE 'APP-$year%'")->fetchColumn() + 1;
            $app_no = sprintf("APP-%s-%04d", $year, $count);

            $stmt = $pdo->prepare("
                INSERT INTO student_applications 
                (application_no, first_name, last_name, dob, gender, grade_applying_for, parent_name, parent_phone, parent_email, placement_test_score, status, reviewer_notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$app_no, $first_name, $last_name, $dob, $gender, $grade_apply, $parent_name, $parent_phone, $parent_email, $score, $status, $notes]);
            $msg = (is_rtl() ? "تم تسجيل طلب الالتحاق بنجاح! رقم الطلب: " : "Admissions application submitted! App No: ") . $app_no;
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error submitting application: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please fill in all mandatory application fields.";
        $msg_type = "danger";
    }
}

// Handle Status Update / Document Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $app_id   = intval($_POST['application_id'] ?? 0);
    $status   = $_POST['status'] ?? 'Application Received';
    $score    = !empty($_POST['placement_test_score']) ? floatval($_POST['placement_test_score']) : null;
    $notes    = trim($_POST['reviewer_notes'] ?? '');

    if ($app_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE student_applications SET status = ?, placement_test_score = ?, reviewer_notes = ? WHERE application_id = ?");
            $stmt->execute([$status, $score, $notes, $app_id]);
            $msg = is_rtl() ? "تم تحديث حالة طلب الالتحاق ونتائج التقييم بنجاح!" : "Application status & evaluation updated successfully!";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error updating status: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle 1-Click Enrollment Conversion (Approved -> Active Student)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'convert_to_student') {
    $app_id   = intval($_POST['application_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);

    if ($app_id > 0 && $class_id > 0) {
        try {
            // Check class capacity limit
            $cls = $pdo->prepare("
                SELECT c.class_name, c.capacity_limit, 
                       (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.is_deleted = 0) as current_enrolled
                FROM classes c WHERE c.class_id = ?
            ");
            $cls->execute([$class_id]);
            $cData = $cls->fetch(PDO::FETCH_ASSOC);

            if ($cData && $cData['current_enrolled'] >= $cData['capacity_limit']) {
                $msg = (is_rtl() ? "تعذر القبول: الفصل {$cData['class_name']} مكتمل السعة ({$cData['current_enrolled']} / {$cData['capacity_limit']}). يرجى اختيار شعبة أخرى." 
                                 : "Intake Blocked: Class {$cData['class_name']} has reached max capacity ({$cData['current_enrolled']} / {$cData['capacity_limit']}). Please select another section.");
                $msg_type = "danger";
            } else {
                // Fetch application data
                $appStmt = $pdo->prepare("SELECT * FROM student_applications WHERE application_id = ?");
                $appStmt->execute([$app_id]);
                $app = $appStmt->fetch(PDO::FETCH_ASSOC);

                if ($app && $app['status'] !== 'Enrolled') {
                    // Generate Unique Student Identification Number: STU-YYYY-XXXX
                    $year = date('Y');
                    $sCount = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE admission_no LIKE 'STU-$year%'")->fetchColumn() + 1;
                    $admission_no = sprintf("STU-%s-%04d", $year, $sCount);

                    // Create user account for student
                    $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $app['first_name'] . '.' . $app['last_name'])) . rand(100, 999);
                    $full_name = trim($app['first_name'] . ' ' . $app['last_name']);
                    $temp_pass = password_hash('Student@2026', PASSWORD_DEFAULT);

                    $userStmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, role_id, status) VALUES (?, ?, ?, ?, 'student', 4, 'Active')");
                    $userStmt->execute([$username, $temp_pass, $full_name, $app['parent_email']]);
                    $new_user_id = (int)$pdo->lastInsertId();

                    // Create student record
                    $stuStmt = $pdo->prepare("
                        INSERT INTO students 
                        (user_id, admission_no, class_id, first_name, last_name, gender, dob, nationality, enrollment_status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                    ");
                    $stuStmt->execute([
                        $new_user_id, $admission_no, $class_id, $app['first_name'], $app['last_name'], 
                        $app['gender'], $app['dob'], $app['nationality'] ?? 'Egyptian'
                    ]);
                    $new_student_id = (int)$pdo->lastInsertId();

                    // Update application record
                    $upApp = $pdo->prepare("UPDATE student_applications SET status = 'Enrolled', enrolled_student_id = ? WHERE application_id = ?");
                    $upApp->execute([$new_student_id, $app_id]);

                    // Log audit trail
                    try {
                        $audit = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_affected, record_id, details) VALUES (?, 'STUDENT_ENROLLED', 'students', ?, ?)");
                        $audit->execute([Auth::id(), $new_student_id, "Enrolled candidate from {$app['application_no']} with Student ID $admission_no in Class ID $class_id"]);
                    } catch (\Throwable $e) {}

                    $msg = (is_rtl() ? "تم تحويل المتقدم رسمياً إلى طالب نشط بنجاح! رقم القيد الجديد: " : "Applicant successfully enrolled! Unique Student ID: ") . $admission_no;
                    $msg_type = "success";
                }
            }
        } catch (\Throwable $e) {
            $msg = "Conversion Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch Classes for dropdown
$classes = $pdo->query("
    SELECT c.class_id, c.class_name, c.capacity_limit,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.is_deleted = 0) as current_enrolled
    FROM classes c
    ORDER BY c.grade_level ASC, c.class_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Applications with Status Counts
$filter_status = $_GET['status'] ?? 'all';
$query = "SELECT * FROM student_applications";
$params = [];
if ($filter_status !== 'all') {
    $query .= " WHERE status = ?";
    $params[] = $filter_status;
}
$query .= " ORDER BY application_id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_apps = (int)$pdo->query("SELECT COUNT(*) FROM student_applications")->fetchColumn();
$verified_apps = (int)$pdo->query("SELECT COUNT(*) FROM student_applications WHERE status = 'Documents Verified'")->fetchColumn();
$approved_apps = (int)$pdo->query("SELECT COUNT(*) FROM student_applications WHERE status = 'Approved'")->fetchColumn();
$enrolled_apps = (int)$pdo->query("SELECT COUNT(*) FROM student_applications WHERE status = 'Enrolled'")->fetchColumn();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'بوابة القبول والتسجيل المدرسي' : 'Admissions & Student Intake' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'بوابة القبول والتسجيل' : 'Admissions & Student Intake',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-primary"></i> ' . (is_rtl() ? 'الهيكل الأكاديمي' : 'Hierarchy') . '</a>',
            '<a href="student_dossier.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card me-1 text-info"></i> ' . (is_rtl() ? 'الملف الشامل' : 'Dossier') . '</a>',
            '<a href="households.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-people-roof me-1 text-success"></i> ' . (is_rtl() ? 'العائلات' : 'Households') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header Banner -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-user-plus text-success me-2"></i><?= is_rtl() ? 'إدارة القبول ومسار استيعاب الطلاب الجدد' : 'Admissions & Student Intake Pipeline' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'إدارة طلبات الالتحاق، التحقق من المستندات، تسجيل اختبارات القبول، وتوليد أرقام القيد الرسمية (STU-YYYY-XXXX) بنقرة واحدة.' : 'Process inquiries, document verification, placement tests, and execute 1-click active enrollment with auto-generated IDs.' ?>
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#newAppModal">
                    <i class="fa-solid fa-plus me-1"></i><?= is_rtl() ? 'تقديم طلب التحاق جديد' : 'New Intake Application' ?>
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

        <!-- KPI Pipeline Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-file-invoice"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_apps ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي طلبات الالتحاق' : 'Total Inquiries / Apps' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $verified_apps ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'مستندات معتمدة' : 'Documents Verified' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $approved_apps ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'مقبولون للالتحاق' : 'Approved for Intake' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $enrolled_apps ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'تم تقييدهم رسمياً' : 'Converted & Enrolled' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="small fw-bold text-muted-foreground me-2"><?= is_rtl() ? 'تصفية حسب الحالة:' : 'Filter Stage:' ?></span>
                    <?php
                    $statuses = [
                        'all'                   => is_rtl() ? 'الكل' : 'All Applications',
                        'Application Received'  => is_rtl() ? 'مستلم' : 'Received',
                        'Documents Verified'    => is_rtl() ? 'مستندات معتمدة' : 'Verified Docs',
                        'Assessment Scheduled'  => is_rtl() ? 'تقييم مجدول' : 'Assessment',
                        'Approved'              => is_rtl() ? 'معتمد للقبول' : 'Approved',
                        'Enrolled'              => is_rtl() ? 'مقيد كطالب' : 'Enrolled',
                        'Rejected'              => is_rtl() ? 'مرفوض' : 'Rejected'
                    ];
                    foreach ($statuses as $stKey => $stLabel):
                        $active = ($filter_status === $stKey);
                    ?>
                        <a href="admissions.php?status=<?= urlencode($stKey) ?>" class="btn btn-sm <?= $active ? 'btn-primary' : 'btn-outline-border' ?> rounded-pill px-3">
                            <?= $stLabel ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Applications Table -->
        <div class="card bg-card border border-border rounded-xl shadow-sm">
            <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                <h6 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-list-check me-2 text-primary"></i><?= is_rtl() ? 'سجل طلبات الالتحاق المباشرة' : 'Intake Application Registry' ?>
                </h6>
                <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($applications) ?> Records</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-secondary">
                            <tr class="text-muted-foreground small text-uppercase font-bold">
                                <th class="ps-3"><?= is_rtl() ? 'رقم الطلب واسم المتقدم' : 'App # & Candidate' ?></th>
                                <th><?= is_rtl() ? 'الصف والنوع' : 'Target Grade & Gender' ?></th>
                                <th><?= is_rtl() ? 'بيانات ولي الأمر والتواصل' : 'Parent / Guardian' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'درجة اختبار القبول' : 'Placement Score' ?></th>
                                <th class="text-center"><?= is_rtl() ? 'مرحلة الطلب' : 'Intake Status' ?></th>
                                <th class="text-end pe-3"><?= is_rtl() ? 'إجراءات التسجيل' : 'Actions / Conversion' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($applications)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد طلبات مسجلة في هذه الفئة.' : 'No admissions applications found matching this status.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($applications as $app): 
                                    $st = $app['status'];
                                    $badge_class = 'bg-secondary';
                                    if ($st === 'Approved') $badge_class = 'bg-warning-subtle text-warning';
                                    elseif ($st === 'Enrolled') $badge_class = 'bg-success-subtle text-success';
                                    elseif ($st === 'Documents Verified') $badge_class = 'bg-info-subtle text-info';
                                    elseif ($st === 'Rejected') $badge_class = 'bg-danger-subtle text-danger';
                                ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></div>
                                            <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($app['application_no']) ?></div>
                                            <div class="small text-muted-foreground" style="font-size: 0.72rem;">DOB: <?= htmlspecialchars($app['dob']) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold text-foreground">Grade <?= $app['grade_applying_for'] ?></div>
                                            <span class="badge bg-secondary text-foreground"><?= htmlspecialchars($app['gender']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small fw-bold text-foreground"><?= htmlspecialchars($app['parent_name']) ?></div>
                                            <div class="small text-muted-foreground font-monospace"><i class="fa-solid fa-phone me-1 text-primary"></i><?= htmlspecialchars($app['parent_phone']) ?></div>
                                            <?php if (!empty($app['parent_email'])): ?>
                                                <div class="small text-muted-foreground" style="font-size: 0.75rem;"><?= htmlspecialchars($app['parent_email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($app['placement_test_score'] !== null): ?>
                                                <span class="fw-bold fs-6 font-monospace <?= (float)$app['placement_test_score'] >= 50 ? 'text-success' : 'text-danger' ?>">
                                                    <?= number_format($app['placement_test_score'], 1) ?>%
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted-foreground small">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $badge_class ?> px-2.5 py-1.5 rounded-pill">
                                                <?= htmlspecialchars($app['status']) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div class="d-inline-flex gap-1">
                                                <!-- Review / Status Update Modal trigger -->
                                                <button type="button" class="btn btn-outline-border btn-sm rounded-lg" 
                                                        data-bs-toggle="modal" data-bs-target="#reviewModal"
                                                        onclick="populateReviewModal(<?= htmlspecialchars(json_encode($app)) ?>)">
                                                    <i class="fa-solid fa-sliders"></i>
                                                </button>

                                                <!-- 1-Click Conversion to Student Button -->
                                                <?php if ($app['status'] === 'Approved'): ?>
                                                    <button type="button" class="btn btn-success btn-sm rounded-lg" 
                                                            data-bs-toggle="modal" data-bs-target="#enrollModal"
                                                            onclick="populateEnrollModal(<?= htmlspecialchars(json_encode($app)) ?>)">
                                                        <i class="fa-solid fa-user-check me-1"></i><?= is_rtl() ? 'تقييد كطالب' : 'Enroll Now' ?>
                                                    </button>
                                                <?php elseif ($app['status'] === 'Enrolled'): ?>
                                                    <a href="student_dossier.php?student_id=<?= $app['enrolled_student_id'] ?>" class="btn btn-outline-success btn-sm rounded-lg" title="View Dossier">
                                                        <i class="fa-solid fa-id-card"></i>
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
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: New Application -->
    <div class="modal fade" id="newAppModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="create_application">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-file-signature me-2 text-primary"></i><?= is_rtl() ? 'تقديم طلب التحاق جديد' : 'New Admissions Intake Application' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">1. Candidate Information</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الاسم الأول' : 'First Name' ?></label>
                                <input type="text" name="first_name" class="form-control bg-background border-border text-foreground" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم العائلة' : 'Last Name' ?></label>
                                <input type="text" name="last_name" class="form-control bg-background border-border text-foreground" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تاريخ الميلاد' : 'Date of Birth' ?></label>
                                <input type="date" name="dob" class="form-control bg-background border-border text-foreground" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'النوع' : 'Gender' ?></label>
                                <select name="gender" class="form-select bg-background border-border text-foreground">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الصف المستهدف' : 'Grade Level' ?></label>
                                <input type="number" name="grade_applying_for" class="form-control bg-background border-border text-foreground" value="1" min="0" max="12" required>
                            </div>
                        </div>

                        <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">2. Parent / Guardian Details</h6>
                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم ولي الأمر' : 'Guardian Name' ?></label>
                                <input type="text" name="parent_name" class="form-control bg-background border-border text-foreground" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'هاتف التواصل' : 'Guardian Phone' ?></label>
                                <input type="text" name="parent_phone" class="form-control bg-background border-border text-foreground" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'البريد الإلكتروني' : 'Guardian Email' ?></label>
                                <input type="email" name="parent_email" class="form-control bg-background border-border text-foreground">
                            </div>
                        </div>

                        <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">3. Initial Evaluation & Notes</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'حالة الطلب الأولية' : 'Initial Status' ?></label>
                                <select name="status" class="form-select bg-background border-border text-foreground">
                                    <option value="Application Received">Application Received</option>
                                    <option value="Documents Verified">Documents Verified</option>
                                    <option value="Assessment Scheduled">Assessment Scheduled</option>
                                    <option value="Approved">Approved</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'درجة اختبار تحديد المستوى (%)' : 'Placement Test Score (%)' ?></label>
                                <input type="number" step="0.1" name="placement_test_score" class="form-control bg-background border-border text-foreground" placeholder="e.g. 85.0">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'ملاحظات مسؤول القبول' : 'Admissions Officer Notes' ?></label>
                                <textarea name="reviewer_notes" class="form-control bg-background border-border text-foreground" rows="2" placeholder="Interview remarks, documents checked..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تسجيل الطلب' : 'Submit Application' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Review / Update Status -->
    <div class="modal fade" id="reviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="application_id" id="review_app_id" value="0">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-sliders me-2 text-primary"></i><?= is_rtl() ? 'تحديث حالة طلب الالتحاق' : 'Review Application & Scores' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="p-2 border border-border rounded-lg bg-background mb-3">
                            <span class="small text-muted-foreground">Candidate:</span>
                            <strong id="review_candidate_name" class="text-foreground"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'حالة الطلب' : 'Application Stage' ?></label>
                            <select name="status" id="review_status" class="form-select bg-background border-border text-foreground">
                                <option value="Inquiry">Inquiry</option>
                                <option value="Application Received">Application Received</option>
                                <option value="Documents Verified">Documents Verified</option>
                                <option value="Assessment Scheduled">Assessment Scheduled</option>
                                <option value="Approved">Approved</option>
                                <option value="Rejected">Rejected</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'درجة اختبار تحديد المستوى (%)' : 'Placement Test Score (%)' ?></label>
                            <input type="number" step="0.1" name="placement_test_score" id="review_score" class="form-control bg-background border-border text-foreground">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'ملاحظات الفحص والتدقيق' : 'Reviewer Evaluation Notes' ?></label>
                            <textarea name="reviewer_notes" id="review_notes" class="form-control bg-background border-border text-foreground" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><?= is_rtl() ? 'حفظ التحديث' : 'Save Review' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: 1-Click Enrollment -->
    <div class="modal fade" id="enrollModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="convert_to_student">
                    <input type="hidden" name="application_id" id="enroll_app_id" value="0">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-user-check me-2 text-success"></i><?= is_rtl() ? 'التقييد الرسمي كطالب نشط' : 'Convert & Enroll Active Student' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info py-2 small mb-3">
                            <i class="fa-solid fa-info-circle me-1"></i>
                            <?= is_rtl() ? 'سيتم إنشاء رقم قيد فريد (STU-YYYY-XXXX) وحساب مستخدم رسمي وتسكين الطالب في الشعبة المحددة.' : 'A unique Student ID (STU-YYYY-XXXX) and active user account will be generated automatically.' ?>
                        </div>
                        <div class="mb-3">
                            <label class="small text-muted-foreground"><?= is_rtl() ? 'اسم الطالب المعتمد:' : 'Candidate to Enroll:' ?></label>
                            <div class="fw-bold fs-6 text-foreground" id="enroll_candidate_name"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تسكين الطالب في الشعبة / الفصل الدراسي:' : 'Assign Classroom Section:' ?></label>
                            <select name="class_id" class="form-select bg-background border-border text-foreground" required>
                                <?php foreach ($classes as $c): 
                                    $is_full = ($c['current_enrolled'] >= $c['capacity_limit']);
                                ?>
                                    <option value="<?= $c['class_id'] ?>" <?= $is_full ? 'disabled class="text-danger"' : '' ?>>
                                        <?= htmlspecialchars($c['class_name']) ?> (<?= $c['current_enrolled'] ?> / <?= $c['capacity_limit'] ?> seats) <?= $is_full ? '— FULL' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-success fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تأكيد القيد واستخراج الرقم' : 'Generate ID & Finalize Enrollment' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function populateReviewModal(data) {
            document.getElementById('review_app_id').value = data.application_id;
            document.getElementById('review_candidate_name').innerText = data.first_name + ' ' + data.last_name + ' (' + data.application_no + ')';
            document.getElementById('review_status').value = data.status;
            document.getElementById('review_score').value = data.placement_test_score || '';
            document.getElementById('review_notes').value = data.reviewer_notes || '';
        }

        function populateEnrollModal(data) {
            document.getElementById('enroll_app_id').value = data.application_id;
            document.getElementById('enroll_candidate_name').innerText = data.first_name + ' ' + data.last_name + ' (' + data.application_no + ')';
        }
    </script>
</body>
</html>
