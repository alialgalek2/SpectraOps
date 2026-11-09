<?php
/**
 * Administration Module - Academic Hierarchy & Educational Stages Engine
 * Manages Stages -> Grades -> Sections/Classes and Section Capacity Limits
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$msg = "";
$msg_type = "info";

// Handle Add / Edit Educational Stage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_stage') {
    $stage_id    = intval($_POST['stage_id'] ?? 0);
    $stage_name  = trim($_POST['stage_name'] ?? '');
    $min_grade   = intval($_POST['min_grade'] ?? 1);
    $max_grade   = intval($_POST['max_grade'] ?? 12);
    $description = trim($_POST['description'] ?? '');

    if (!empty($stage_name)) {
        try {
            if ($stage_id > 0) {
                $stmt = $pdo->prepare("UPDATE educational_stages SET stage_name = ?, min_grade = ?, max_grade = ?, description = ? WHERE stage_id = ?");
                $stmt->execute([$stage_name, $min_grade, $max_grade, $description, $stage_id]);
                $msg = is_rtl() ? "تم تحديث المرحلة التعليمية بنجاح!" : "Educational stage updated successfully!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO educational_stages (stage_name, min_grade, max_grade, description) VALUES (?, ?, ?, ?)");
                $stmt->execute([$stage_name, $min_grade, $max_grade, $description]);
                $msg = is_rtl() ? "تمت إضافة المرحلة التعليمية بنجاح!" : "New educational stage created successfully!";
            }
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error saving stage: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle Add / Update Class Section with Capacity Limits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_class') {
    $class_id       = intval($_POST['class_id'] ?? 0);
    $class_name     = trim($_POST['class_name'] ?? '');
    $grade_level    = intval($_POST['grade_level'] ?? 1);
    $stage_id       = intval($_POST['stage_id'] ?? 1);
    $capacity_limit = intval($_POST['capacity_limit'] ?? 35);
    $campus_id      = intval($_POST['campus_id'] ?? 1);

    if (!empty($class_name)) {
        try {
            if ($class_id > 0) {
                $stmt = $pdo->prepare("UPDATE classes SET class_name = ?, grade_level = ?, stage_id = ?, capacity_limit = ?, campus_id = ? WHERE class_id = ?");
                $stmt->execute([$class_name, $grade_level, $stage_id, $capacity_limit, $campus_id, $class_id]);
                $msg = is_rtl() ? "تم تعديل الفصل والسعة الاستيعابية بنجاح!" : "Class section & intake capacity updated successfully!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO classes (class_name, grade_level, stage_id, capacity_limit, campus_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$class_name, $grade_level, $stage_id, $capacity_limit, $campus_id]);
                $msg = is_rtl() ? "تم إنشاء الفصل الدراسي وتحديد السعة بنجاح!" : "Class section created with capacity limit!";
            }
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error saving class: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch Stages
$stages = $pdo->query("SELECT * FROM educational_stages ORDER BY min_grade ASC, stage_id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Campuses
$campuses = $pdo->query("SELECT * FROM campuses ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Classes with Enrollment Stats
$classes_stmt = $pdo->query("
    SELECT c.*, es.stage_name, cp.campus_name,
           (SELECT COUNT(*) FROM students s WHERE s.class_id = c.class_id AND s.is_deleted = 0) as enrolled_count
    FROM classes c
    LEFT JOIN educational_stages es ON c.stage_id = es.stage_id
    LEFT JOIN campuses cp ON c.campus_id = cp.campus_id
    ORDER BY c.grade_level ASC, c.class_name ASC
");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_capacity = 0;
$total_enrolled = 0;
foreach ($classes as $cls) {
    $total_capacity += (int)$cls['capacity_limit'];
    $total_enrolled += (int)$cls['enrolled_count'];
}
$utilization_rate = $total_capacity > 0 ? round(($total_enrolled / $total_capacity) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'الهيكل الأكاديمي والمراحل التعليمية' : 'Academic Hierarchy & Class Capacities' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'الهيكل الأكاديمي والسعة الاستيعابية' : 'Academic Hierarchy & Class Capacities',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="institution_settings.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-school me-1 text-primary"></i> ' . (is_rtl() ? 'المؤسسة' : 'Institution') . '</a>',
            '<a href="academic_calendar.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-days me-1 text-purple"></i> ' . (is_rtl() ? 'التقويم' : 'Calendar') . '</a>',
            '<a href="admissions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-plus me-1 text-success"></i> ' . (is_rtl() ? 'القبول' : 'Admissions') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-sitemap text-primary me-2"></i><?= is_rtl() ? 'محرك الهيكل الأكاديمي وسعة الفصول' : 'Academic Structural Hierarchy & Capacity Engine' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'تنظيم شجرة المراحل التعليمية (رياض أطفال ➔ ابتدائي ➔ إعدادي ➔ ثانوي) وضبط السعة الاستيعابية لكل فصل لمنع تجاوز كثافة التسجيل.' : 'Organize structural stages (KG ➔ Primary ➔ Secondary ➔ High School) and govern intake capacity limits per classroom section.' ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-border btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#stageModal">
                    <i class="fa-solid fa-layer-group me-1 text-purple"></i><?= is_rtl() ? 'إضافة مرحلة تعليمية' : 'Add Stage' ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#classModal">
                    <i class="fa-solid fa-plus me-1"></i><?= is_rtl() ? 'إضافة فصل / شعبة' : 'Add Class Section' ?>
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

        <!-- KPI Metrics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= count($stages) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'المراحل التعليمية' : 'Educational Stages' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= count($classes) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'الشعب والفصول' : 'Class Sections' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($total_enrolled) ?> / <?= number_format($total_capacity) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'المسجلون / السعة الكلية' : 'Enrolled / Capacity' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $utilization_rate ?>%</div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'معدل إشغال المقاعد' : 'Intake Utilization' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Left Column: Educational Stages Tree -->
            <div class="col-lg-4">
                <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                    <div class="card-header bg-card border-bottom border-border py-3">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-graduation-cap me-2 text-purple"></i><?= is_rtl() ? 'المراحل التعليمية الرسمية' : 'Educational Stages' ?>
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <div class="d-flex flex-column gap-3">
                            <?php foreach ($stages as $stg): ?>
                                <div class="p-3 border border-border rounded-xl bg-background shadow-xs">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 text-foreground"><?= htmlspecialchars($stg['stage_name']) ?></h6>
                                        <span class="badge bg-purple-subtle text-purple font-monospace">
                                            Grades <?= $stg['min_grade'] ?> - <?= $stg['max_grade'] ?>
                                        </span>
                                    </div>
                                    <p class="small text-muted-foreground mb-2"><?= htmlspecialchars($stg['description'] ?? 'Stage curriculum pathway') ?></p>
                                    <div class="small text-muted-foreground d-flex justify-content-between border-top border-border pt-2">
                                        <span>Stage ID #<?= $stg['stage_id'] ?></span>
                                        <span class="text-primary fw-bold">Active Framework</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Classes and Section Intake Capacity Table -->
            <div class="col-lg-8">
                <div class="card bg-card border border-border rounded-xl shadow-sm">
                    <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-door-open me-2 text-primary"></i><?= is_rtl() ? 'شعب الفصول وضوابط السعة القصوى' : 'Classes, Sections & Intake Capacity Limits' ?>
                        </h6>
                        <span class="badge bg-primary-subtle text-primary rounded-pill"><?= count($classes) ?> Sections</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-secondary">
                                    <tr class="text-muted-foreground small text-uppercase font-bold">
                                        <th class="ps-3"><?= is_rtl() ? 'اسم الفصل / الشعبة' : 'Section Name' ?></th>
                                        <th><?= is_rtl() ? 'المرحلة والصف' : 'Stage & Grade' ?></th>
                                        <th><?= is_rtl() ? 'الفرع' : 'Campus' ?></th>
                                        <th class="text-center" style="width: 180px;"><?= is_rtl() ? 'الإشغال / السعة' : 'Intake / Max Cap' ?></th>
                                        <th class="text-center"><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                                        <th class="text-end pe-3"><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($classes)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد فصول مضافة بعد.' : 'No class sections registered.' ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($classes as $c): 
                                            $cap = (int)$c['capacity_limit'];
                                            $enr = (int)$c['enrolled_count'];
                                            $pct = $cap > 0 ? min(100, round(($enr / $cap) * 100)) : 0;
                                            $is_full = ($enr >= $cap);
                                        ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="fw-bold text-foreground"><?= htmlspecialchars($c['class_name']) ?></div>
                                                    <div class="small text-muted-foreground font-monospace">Section ID #<?= $c['class_id'] ?></div>
                                                </td>
                                                <td>
                                                    <div class="small fw-semibold text-foreground"><?= htmlspecialchars($c['stage_name'] ?? 'General Stage') ?></div>
                                                    <div class="small text-muted-foreground">Grade Level <?= $c['grade_level'] ?></div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary text-foreground"><?= htmlspecialchars($c['campus_name'] ?? 'Main Campus') ?></span>
                                                </td>
                                                <td>
                                                    <div class="d-flex justify-content-between small mb-1">
                                                        <span class="fw-bold font-monospace"><?= $enr ?> / <?= $cap ?></span>
                                                        <span class="text-muted-foreground"><?= $pct ?>%</span>
                                                    </div>
                                                    <div class="progress" style="height: 6px;">
                                                        <div class="progress-bar <?= $is_full ? 'bg-danger' : ($pct > 80 ? 'bg-warning' : 'bg-success') ?>" style="width: <?= $pct ?>%"></div>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($is_full): ?>
                                                        <span class="badge bg-danger-subtle text-danger"><i class="fa-solid fa-ban me-1"></i><?= is_rtl() ? 'مكتمل' : 'At Capacity' ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'متاح للتسجيل' : 'Seats Open' ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <button type="button" class="btn btn-outline-border btn-sm rounded-lg" 
                                                            data-bs-toggle="modal" data-bs-target="#classModal"
                                                            onclick="populateClassModal(<?= htmlspecialchars(json_encode($c)) ?>)">
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
            </div>
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: Add / Edit Stage -->
    <div class="modal fade" id="stageModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="save_stage">
                    <input type="hidden" name="stage_id" value="0">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-layer-group me-2 text-purple"></i><?= is_rtl() ? 'المرحلة التعليمية' : 'Educational Stage' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم المرحلة' : 'Stage Name' ?></label>
                            <input type="text" name="stage_name" class="form-control bg-background border-border text-foreground" placeholder="e.g. Primary / Elementary" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'من صف' : 'Min Grade' ?></label>
                                <input type="number" name="min_grade" class="form-control bg-background border-border text-foreground" value="1" min="0" max="12" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'إلى صف' : 'Max Grade' ?></label>
                                <input type="number" name="max_grade" class="form-control bg-background border-border text-foreground" value="6" min="0" max="12" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الوصف ومسار المنهاج' : 'Description & Pathway' ?></label>
                            <textarea name="description" class="form-control bg-background border-border text-foreground" rows="3" placeholder="Curriculum focus, graduation criteria..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><?= is_rtl() ? 'حفظ المرحلة' : 'Save Stage' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal: Add / Edit Class Section -->
    <div class="modal fade" id="classModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="save_class">
                    <input type="hidden" name="class_id" id="modal_class_id" value="0">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-door-open me-2 text-primary"></i><span id="modal_class_title"><?= is_rtl() ? 'إضافة فصل دراسي' : 'Add Class Section' ?></span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم الفصل / الشعبة' : 'Section Name' ?></label>
                            <input type="text" name="class_name" id="modal_class_name" class="form-control bg-background border-border text-foreground" placeholder="e.g. Grade 5-A" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المرحلة التعليمية' : 'Educational Stage' ?></label>
                                <select name="stage_id" id="modal_stage_id" class="form-select bg-background border-border text-foreground">
                                    <?php foreach ($stages as $stg): ?>
                                        <option value="<?= $stg['stage_id'] ?>"><?= htmlspecialchars($stg['stage_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الصف الدراسي' : 'Grade Level' ?></label>
                                <input type="number" name="grade_level" id="modal_grade_level" class="form-control bg-background border-border text-foreground" value="1" min="0" max="12" required>
                            </div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'السعة الاستيعابية القصوى' : 'Intake Capacity Limit' ?></label>
                                <input type="number" name="capacity_limit" id="modal_capacity_limit" class="form-control bg-background border-border text-foreground" value="35" min="5" max="100" required>
                                <div class="form-text text-muted-foreground" style="font-size: 0.72rem;"><?= is_rtl() ? 'يمنع قبول الطلاب عند الامتلاء' : 'Hard stop for admissions' ?></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفرع المدرسي' : 'Campus Branch' ?></label>
                                <select name="campus_id" id="modal_campus_id" class="form-select bg-background border-border text-foreground">
                                    <?php foreach ($campuses as $cp): ?>
                                        <option value="<?= $cp['campus_id'] ?>"><?= htmlspecialchars($cp['campus_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'حفظ الفصل' : 'Save Section' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function populateClassModal(data) {
            document.getElementById('modal_class_id').value = data.class_id;
            document.getElementById('modal_class_name').value = data.class_name;
            document.getElementById('modal_stage_id').value = data.stage_id;
            document.getElementById('modal_grade_level').value = data.grade_level;
            document.getElementById('modal_capacity_limit').value = data.capacity_limit;
            document.getElementById('modal_campus_id').value = data.campus_id || 1;
            document.getElementById('modal_class_title').innerText = '<?= is_rtl() ? "تعديل الفصل الدراسي" : "Edit Class Section" ?>';
        }
    </script>
</body>
</html>
