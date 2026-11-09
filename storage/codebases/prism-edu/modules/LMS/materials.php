<?php
/**
 * modules/LMS/materials.php
 * LMS Module - Digital Resource Repository & Learning Material Hub
 * (Core Umbrella Architecture)
 * 
 * Features:
 * 1. Multi-Format Content Distribution: PDF slides, worksheets, external links, video lectures.
 * 2. Repository-Level File Validation: Extensions (.pdf, .docx, .pptx, .mp4, images) and size limits.
 * 3. Release Scheduling & Access Controls: Immediate vs. Scheduled release datetime, Visibility locks (Student, Teacher-Only, Draft).
 * 4. In-line Document & Video Previews directly inside the browser.
 * 5. Full Bilingual RTL/LTR Support & Unified System Theme.
 */

require_once __DIR__ . '/../../core/init.php';
require_once __DIR__ . '/../../licensing/license_helper.php';

$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

Auth::requireLogin();

$user_id   = Auth::id();
$user_role = Auth::role();
$can_edit  = in_array($user_role, ['admin', 'school_admin', 'teacher']);

$msg = '';
$msg_type = 'success';

// Handle Action Submissions (Upload / Create Resource)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'upload_material') {
            $course_id     = (int)($_POST['course_id'] ?? 0);
            $unit_id       = !empty($_POST['unit_id']) ? (int)$_POST['unit_id'] : null;
            $topic_id      = !empty($_POST['topic_id']) ? (int)$_POST['topic_id'] : null;
            $title         = trim($_POST['title'] ?? '');
            $description   = trim($_POST['description'] ?? '');
            $material_type = $_POST['material_type'] ?? 'document';
            $external_url  = trim($_POST['external_url'] ?? '');
            $publish_type  = $_POST['publish_type'] ?? 'immediate';
            $unlock_at     = ($publish_type === 'scheduled' && !empty($_POST['unlock_at'])) ? $_POST['unlock_at'] : null;
            $visibility    = $_POST['visibility'] ?? 'student';

            if ($course_id <= 0 || empty($title)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى تحديد المقرر وعنوان المورد التعليمي.' : 'Please specify the course and resource title.');
            }

            $file_path  = null;
            $file_size  = 0;
            $file_ext   = null;

            // Handle file upload if provided
            if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_extensions = ['pdf', 'docx', 'doc', 'pptx', 'ppt', 'xlsx', 'xls', 'zip', 'png', 'jpg', 'jpeg', 'mp4', 'webm'];
                $max_size_bytes = 25 * 1024 * 1024; // 25 MB

                $orig_name = $_FILES['material_file']['name'];
                $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
                $size = $_FILES['material_file']['size'];

                if (!in_array($ext, $allowed_extensions)) {
                    throw new InvalidArgumentException(is_rtl() ? "نوع الملف غير مسموح. الأنواع المعتمدة: " . implode(', ', $allowed_extensions) : "Disallowed file type. Permitted: " . implode(', ', $allowed_extensions));
                }

                if ($size > $max_size_bytes) {
                    throw new InvalidArgumentException(is_rtl() ? 'حجم الملف يتجاوز الحد الأقصى المسموح به (25 ميجابايت).' : 'File size exceeds maximum permitted repository limit (25 MB).');
                }

                $upload_dir = __DIR__ . '/../../uploads/materials/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                $safe_filename = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($orig_name));
                $target_path = $upload_dir . $safe_filename;

                if (move_uploaded_file($_FILES['material_file']['tmp_name'], $target_path)) {
                    $file_path = 'uploads/materials/' . $safe_filename;
                    $file_size = $size;
                    $file_ext  = $ext;
                }
            }

            // If video embed or web link, validate URL
            if (in_array($material_type, ['web_link', 'video_embed']) && empty($external_url) && empty($file_path)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى إدخال الرابط الخارجي أو رابط الفيديو.' : 'Please provide the external web link or video URL.');
            }

            $ins = $pdo->prepare("
                INSERT INTO course_materials (course_id, unit_id, topic_id, teacher_id, title, description, material_type, file_path, external_url, file_size_bytes, file_ext, publish_type, unlock_at, visibility, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([
                $course_id, $unit_id, $topic_id, $user_id, $title, $description,
                $material_type, $file_path, $external_url, $file_size, $file_ext,
                $publish_type, $unlock_at, $visibility
            ]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تم نشر وتوثيق المورد التعليمي في المستودع بنجاح!' : 'Learning resource uploaded and published successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: materials.php?course_id=" . $course_id);
            exit();
        }

        // Delete Material
        if ($action === 'delete_material') {
            $material_id = (int)($_POST['material_id'] ?? 0);
            $course_id   = (int)($_POST['course_id'] ?? 0);

            if ($material_id > 0) {
                $mat_stmt = $pdo->prepare("SELECT file_path FROM course_materials WHERE material_id = ?");
                $mat_stmt->execute([$material_id]);
                $file_info = $mat_stmt->fetch();

                if ($file_info && !empty($file_info['file_path'])) {
                    $phys_path = __DIR__ . '/../../' . $file_info['file_path'];
                    if (file_exists($phys_path)) {
                        @unlink($phys_path);
                    }
                }

                $del = $pdo->prepare("DELETE FROM course_materials WHERE material_id = ?");
                $del->execute([$material_id]);

                $_SESSION['flash_msg'] = is_rtl() ? 'تم حذف المورد التعليمي من المستودع بنجاح.' : 'Learning material deleted successfully.';
                $_SESSION['flash_type'] = 'info';
            }
            header("Location: materials.php" . ($course_id > 0 ? "?course_id=$course_id" : ""));
            exit();
        }

    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Filters & Query
$filter_course = (int)($_GET['course_id'] ?? 0);
$filter_type   = $_GET['type'] ?? '';
$search_q      = trim($_GET['q'] ?? '');

// Courses List for dropdown
$courses = $pdo->query("
    SELECT c.course_id, c.course_name, c.course_code, cl.class_name 
    FROM courses c 
    JOIN classes cl ON c.class_id = cl.class_id 
    ORDER BY c.created_at DESC
")->fetchAll();

if ($filter_course <= 0 && !empty($courses)) {
    $filter_course = (int)$courses[0]['course_id'];
}

// Fetch Materials Query
$m_sql = "
    SELECT m.*, c.course_name, c.course_code, cu.title AS unit_title, ct.title AS topic_title, u.full_name AS author_name
    FROM course_materials m
    JOIN courses c ON m.course_id = c.course_id
    LEFT JOIN curriculum_units cu ON m.unit_id = cu.unit_id
    LEFT JOIN curriculum_topics ct ON m.topic_id = ct.topic_id
    LEFT JOIN users u ON m.teacher_id = u.user_id
    WHERE 1=1
";
$m_params = [];

if ($filter_course > 0) {
    $m_sql .= " AND m.course_id = ?";
    $m_params[] = $filter_course;
}

if (!empty($filter_type)) {
    $m_sql .= " AND m.material_type = ?";
    $m_params[] = $filter_type;
}

if (!empty($search_q)) {
    $m_sql .= " AND (m.title LIKE ? OR m.description LIKE ?)";
    $m_params[] = "%$search_q%";
    $m_params[] = "%$search_q%";
}

// Access controls for Student / Parent roles:
if ($user_role === 'student' || $user_role === 'parent') {
    $m_sql .= " AND m.visibility = 'student' AND (m.publish_type = 'immediate' OR (m.publish_type = 'scheduled' AND m.unlock_at <= NOW()))";
}

$m_sql .= " ORDER BY m.created_at DESC";
$stmt_mat = $pdo->prepare($m_sql);
$stmt_mat->execute($m_params);
$materials = $stmt_mat->fetchAll();

// Fetch units for current selected course
$current_units = [];
if ($filter_course > 0) {
    $u_stmt = $pdo->prepare("SELECT unit_id, unit_number, title FROM curriculum_units WHERE course_id = ? ORDER BY unit_number ASC");
    $u_stmt->execute([$filter_course]);
    $current_units = $u_stmt->fetchAll();
}

// Counts for stat cards
$total_materials = count($materials);
$slides_count = 0;
$worksheets_count = 0;
$video_count = 0;
foreach ($materials as $m_row) {
    if ($m_row['material_type'] === 'pdf_slide') $slides_count++;
    if ($m_row['material_type'] === 'worksheet') $worksheets_count++;
    if ($m_row['material_type'] === 'video_embed') $video_count++;
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'المستودع الرقمي والمصادر التعليمية - LMS' : 'Learning Material & Digital Repository - LMS' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .material-card {
            transition: all 0.2s ease-in-out;
        }
        .material-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.06);
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'المستودع الرقمي' : 'Digital Repository',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'دليل المقررات' : 'Courses') . '</a>',
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-success"></i> ' . (is_rtl() ? 'الواجبات' : 'Assignments') . '</a>',
        '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Flash Message -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 shadow-sm rounded-xl mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-folder-open text-primary me-2"></i><?= is_rtl() ? 'المستودع الرقمي والمصادر التعليمية' : 'Learning Material & Digital Repository' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'مكتبة رقمية مركزية لشرائح العروض، أوراق العمل، المحاضرات المرئية والروابط الأكاديمية مع جدولة النشر.' : 'Central repository for lecture slides, downloadable worksheets, embedded video lectures, and controlled releases.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#uploadMaterialModal">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= is_rtl() ? 'رفع مورد تعليمي جديد' : 'Upload Learning Resource' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stat Metric Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-boxes-stacked"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $total_materials ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي الموارد المتاحة' : 'Total Resources' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-danger me-3">
                    <i class="fa-solid fa-file-pdf"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $slides_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'شرائح وملفات PDF' : 'PDF Slides & Notes' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-file-lines"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $worksheets_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'أوراق العمل والتمارين' : 'Worksheets & Docs' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-video"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $video_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'المحاضرات المرئية' : 'Video Lectures' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="card border border-border rounded-xl p-3 bg-card shadow-sm mb-4">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-md-4">
                <label class="form-label extra-small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'المقرر الدراسي:' : 'Course Catalog:' ?></label>
                <select name="course_id" class="form-select form-select-sm bg-background text-foreground border-border rounded-lg" onchange="this.form.submit()">
                    <option value="0"><?= is_rtl() ? '-- جميع المقررات --' : '-- All Courses --' ?></option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['course_id'] ?>" <?= $filter_course == $c['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_name']) ?> (<?= htmlspecialchars($c['class_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label extra-small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'نوع المورد:' : 'Resource Type:' ?></label>
                <select name="type" class="form-select form-select-sm bg-background text-foreground border-border rounded-lg" onchange="this.form.submit()">
                    <option value=""><?= is_rtl() ? 'كل الأنواع' : 'All Resource Types' ?></option>
                    <option value="pdf_slide" <?= $filter_type === 'pdf_slide' ? 'selected' : '' ?>><?= is_rtl() ? 'شرائح عرض (PDF)' : 'PDF Lecture Slides' ?></option>
                    <option value="worksheet" <?= $filter_type === 'worksheet' ? 'selected' : '' ?>><?= is_rtl() ? 'ورقة عمل / تمرين' : 'Worksheet / Exercises' ?></option>
                    <option value="video_embed" <?= $filter_type === 'video_embed' ? 'selected' : '' ?>><?= is_rtl() ? 'محاضرة فيديو' : 'Video Lecture' ?></option>
                    <option value="web_link" <?= $filter_type === 'web_link' ? 'selected' : '' ?>><?= is_rtl() ? 'رابط ويب خارجي' : 'External Web Link' ?></option>
                    <option value="document" <?= $filter_type === 'document' ? 'selected' : '' ?>><?= is_rtl() ? 'مستند عام' : 'General Document' ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label extra-small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'البحث بالاسم / الوصف:' : 'Search by Keyword:' ?></label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-background border-border text-muted-foreground"><i class="fa-solid fa-search"></i></span>
                    <input type="text" name="q" class="form-control bg-background text-foreground border-border" placeholder="<?= is_rtl() ? 'اكتب كلمة البحث...' : 'Search resources...' ?>" value="<?= htmlspecialchars($search_q) ?>">
                </div>
            </div>
            <div class="col-md-1 d-flex align-items-end mt-3">
                <a href="materials.php" class="btn btn-secondary btn-sm w-100 rounded-lg" title="Reset">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Materials Grid -->
    <?php if (empty($materials)): ?>
        <div class="card border border-border rounded-xl bg-card shadow-sm p-5 text-center">
            <div class="action-icon-box icon-box-primary mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <h5 class="fw-bold text-foreground mb-1"><?= is_rtl() ? 'لم يتم العثور على مصادر تعليمية' : 'No learning materials found' ?></h5>
            <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'يمكنك رفع شرائح الدروس، أوراق العمل أو مقاطع الفيديو المرتبطة بوحدات المنهج.' : 'Upload slides, worksheets, or video tutorials mapped to curriculum units.' ?></p>
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary btn-sm rounded-xl mx-auto" data-bs-toggle="modal" data-bs-target="#uploadMaterialModal">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= is_rtl() ? 'رفع أول مورد تعليمي' : 'Upload First Resource' ?>
                </button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($materials as $item): ?>
                <?php
                    $type_icon = 'fa-file-lines';
                    $type_badge = 'bg-secondary text-muted-foreground';
                    if ($item['material_type'] === 'pdf_slide') {
                        $type_icon = 'fa-file-pdf text-danger';
                        $type_badge = 'bg-danger-subtle text-danger border border-danger-subtle';
                    } elseif ($item['material_type'] === 'worksheet') {
                        $type_icon = 'fa-file-word text-primary';
                        $type_badge = 'bg-primary-subtle text-primary border border-primary-subtle';
                    } elseif ($item['material_type'] === 'video_embed') {
                        $type_icon = 'fa-video text-info';
                        $type_badge = 'bg-info-subtle text-info border border-info-subtle';
                    } elseif ($item['material_type'] === 'web_link') {
                        $type_icon = 'fa-link text-warning';
                        $type_badge = 'bg-warning-subtle text-warning border border-warning-subtle';
                    }

                    $is_locked = ($item['visibility'] === 'teacher_only');
                    $is_draft  = ($item['visibility'] === 'draft');
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="material-card card border border-border rounded-xl bg-card shadow-sm h-100 p-3 d-flex flex-column justify-content-between">
                        <div>
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <span class="badge <?= $type_badge ?> rounded-pill extra-small">
                                    <i class="fa-solid <?= $type_icon ?> me-1"></i> <?= ucfirst(str_replace('_', ' ', $item['material_type'])) ?>
                                </span>
                                <div class="d-flex align-items-center gap-1">
                                    <?php if ($is_locked): ?>
                                        <span class="badge bg-secondary text-muted-foreground rounded-pill extra-small" title="Teacher-Only Access">
                                            <i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'للمعلم فقط' : 'Teacher Only' ?>
                                        </span>
                                    <?php elseif ($is_draft): ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill extra-small">
                                            <i class="fa-solid fa-pen-ruler me-1"></i><?= is_rtl() ? 'مسودة' : 'Draft' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <h6 class="fw-bold text-foreground mb-1"><?= htmlspecialchars($item['title']) ?></h6>
                            <?php if (!empty($item['description'])): ?>
                                <p class="text-muted-foreground extra-small mb-2" style="max-height: 40px; overflow: hidden; text-overflow: ellipsis;">
                                    <?= htmlspecialchars($item['description']) ?>
                                </p>
                            <?php endif; ?>

                            <div class="extra-small text-muted-foreground mb-3">
                                <?php if (!empty($item['unit_title'])): ?>
                                    <div><i class="fa-solid fa-layer-group text-primary me-1"></i> <?= htmlspecialchars($item['unit_title']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($item['topic_title'])): ?>
                                    <div><i class="fa-solid fa-book-open text-info me-1"></i> <?= htmlspecialchars($item['topic_title']) ?></div>
                                <?php endif; ?>
                                <div><i class="fa-regular fa-clock me-1"></i> <?= date('Y-m-d', strtotime($item['created_at'])) ?> • <?= htmlspecialchars($item['author_name'] ?? 'Faculty') ?></div>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="pt-2 border-top border-border d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-1">
                                <?php if (!empty($item['file_path'])): ?>
                                    <a href="../../<?= htmlspecialchars($item['file_path']) ?>" download class="btn btn-outline-border btn-sm rounded-lg extra-small">
                                        <i class="fa-solid fa-download me-1 text-primary"></i> <?= is_rtl() ? 'تحميل' : 'Download' ?>
                                    </a>
                                    <!-- In-line Preview Modal Button -->
                                    <button type="button" class="btn btn-outline-border btn-sm rounded-lg extra-small" 
                                            onclick="previewDocument('<?= htmlspecialchars($item['title']) ?>', '../../<?= htmlspecialchars($item['file_path']) ?>', '<?= $item['file_ext'] ?>')">
                                        <i class="fa-solid fa-eye me-1 text-info"></i> <?= is_rtl() ? 'معاينة' : 'Preview' ?>
                                    </button>
                                <?php elseif (!empty($item['external_url'])): ?>
                                    <a href="<?= htmlspecialchars($item['external_url']) ?>" target="_blank" class="btn btn-outline-border btn-sm rounded-lg extra-small">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1 text-warning"></i> <?= is_rtl() ? 'فتح الرابط' : 'Open Link' ?>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <?php if ($can_edit): ?>
                                <form method="POST" action="" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذا المورد؟' : 'Are you sure you want to delete this resource?' ?>');">
                                    <input type="hidden" name="action" value="delete_material">
                                    <input type="hidden" name="material_id" value="<?= $item['material_id'] ?>">
                                    <input type="hidden" name="course_id" value="<?= $item['course_id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-lg extra-small" title="Delete">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: In-line Document & Media Preview -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-bottom border-border py-3">
                <h5 class="modal-title fw-bold text-foreground" id="previewModalTitle">
                    <i class="fa-solid fa-file-lines text-primary me-2"></i>Document Preview
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="previewModalBody" style="min-height: 500px; max-height: 80vh; overflow: auto;">
                <!-- Dynamically populated -->
            </div>
            <div class="modal-footer border-top border-border py-2">
                <a id="previewDownloadBtn" href="#" download class="btn btn-primary btn-sm rounded-lg">
                    <i class="fa-solid fa-download me-1"></i> <?= is_rtl() ? 'تحميل الملف' : 'Download File' ?>
                </a>
                <button type="button" class="btn btn-secondary btn-sm rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إغلاق' : 'Close' ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Upload Learning Material -->
<?php if ($can_edit): ?>
<div class="modal fade" id="uploadMaterialModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_material">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground">
                        <i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i><?= is_rtl() ? 'إضافة مورد تعليمي للمستودع' : 'Upload Learning Resource' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المقرر الدراسي:' : 'Target Course:' ?></label>
                            <select name="course_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <?php foreach ($courses as $crs): ?>
                                    <option value="<?= $crs['course_id'] ?>" <?= $filter_course == $crs['course_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($crs['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'نوع المورد:' : 'Material Format:' ?></label>
                            <select name="material_type" class="form-select bg-background text-foreground border-border rounded-lg" required onchange="toggleUploadType(this.value)">
                                <option value="pdf_slide"><?= is_rtl() ? 'شرائح عرض ومحاضرة (PDF Slides)' : 'PDF Lecture Slides' ?></option>
                                <option value="worksheet"><?= is_rtl() ? 'ورقة عمل / تمرين (Worksheet)' : 'Worksheet / Problem Set' ?></option>
                                <option value="video_embed"><?= is_rtl() ? 'رابط محاضرة مرئية (Video Embed)' : 'Embedded Video Lecture' ?></option>
                                <option value="web_link"><?= is_rtl() ? 'رابط ويب خارجي (Web Link)' : 'External Web Link' ?></option>
                                <option value="document"><?= is_rtl() ? 'مستند عام (Document)' : 'General Document' ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'عنوان المورد:' : 'Resource Title:' ?></label>
                        <input type="text" name="title" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g., Chapter 3: Geometry Proofs Worksheet" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'وصف أو توجيهات للطلاب:' : 'Description / Instructions:' ?></label>
                        <textarea name="description" rows="2" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="Brief summary of contents..."></textarea>
                    </div>

                    <!-- File Upload Input -->
                    <div class="mb-3" id="fileUploadContainer">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الملف المرفق (PDF, Word, Slides, MP4 - أقصى حجم 25MB):' : 'Attachment (PDF, DOCX, PPTX, MP4 - Max 25MB):' ?></label>
                        <input type="file" name="material_file" class="form-control bg-background text-foreground border-border rounded-lg">
                    </div>

                    <!-- External URL Input -->
                    <div class="mb-3" id="urlInputContainer" style="display: none;">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رابط الويب أو الفيديو الخارجي:' : 'External Web / Video URL:' ?></label>
                        <input type="url" name="external_url" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="https://youtube.com/watch?v=... or https://example.com/slides">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'مستوى الرؤية والصلاحية:' : 'Visibility Lock:' ?></label>
                            <select name="visibility" class="form-select bg-background text-foreground border-border rounded-lg">
                                <option value="student"><?= is_rtl() ? 'متاح للطلاب وأولياء الأمور' : 'Student-Visible' ?></option>
                                <option value="teacher_only"><?= is_rtl() ? 'للمعلمين فقط (خطط/إجابات)' : 'Teacher-Only' ?></option>
                                <option value="draft"><?= is_rtl() ? 'مسودة غير منشورة' : 'Draft' ?></option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'جدولة النشر:' : 'Publish Schedule:' ?></label>
                            <select name="publish_type" class="form-select bg-background text-foreground border-border rounded-lg" onchange="document.getElementById('unlockDateBox').style.display = (this.value === 'scheduled') ? 'block' : 'none';">
                                <option value="immediate"><?= is_rtl() ? 'نشر فوري' : 'Immediate Publishing' ?></option>
                                <option value="scheduled"><?= is_rtl() ? 'جدولة / نشر مؤجل' : 'Scheduled Release' ?></option>
                            </select>
                        </div>
                        <div class="col-md-4" id="unlockDateBox" style="display: none;">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تاريخ ووقت الفتح:' : 'Unlock Datetime:' ?></label>
                            <input type="datetime-local" name="unlock_at" class="form-control bg-background text-foreground border-border rounded-lg">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <button type="button" class="btn btn-secondary rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-lg px-3"><?= is_rtl() ? 'حفظ وتوثيق' : 'Upload Resource' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleUploadType(type) {
    const fileBox = document.getElementById('fileUploadContainer');
    const urlBox  = document.getElementById('urlInputContainer');
    if (type === 'video_embed' || type === 'web_link') {
        urlBox.style.display = 'block';
    } else {
        urlBox.style.display = 'none';
        fileBox.style.display = 'block';
    }
}

function previewDocument(title, url, ext) {
    document.getElementById('previewModalTitle').innerText = title;
    document.getElementById('previewDownloadBtn').href = url;
    const body = document.getElementById('previewModalBody');
    body.innerHTML = '';

    ext = (ext || '').toLowerCase();
    if (ext === 'pdf') {
        body.innerHTML = `<iframe src="${url}" style="width:100%; height:75vh; border:none;"></iframe>`;
    } else if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) {
        body.innerHTML = `<div class="text-center p-4"><img src="${url}" class="img-fluid rounded shadow-sm" style="max-height:75vh;"></div>`;
    } else if (['mp4', 'webm'].includes(ext)) {
        body.innerHTML = `<div class="p-3 text-center"><video src="${url}" controls style="max-width:100%; max-height:70vh;"></video></div>`;
    } else {
        body.innerHTML = `
            <div class="p-5 text-center text-muted-foreground">
                <i class="fa-solid fa-file-lines fa-3x mb-3 text-primary"></i>
                <h5>${title}</h5>
                <p class="small">In-line preview is not available for .${ext} files. Please download to view.</p>
                <a href="${url}" download class="btn btn-primary rounded-lg">Download File</a>
            </div>
        `;
    }

    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>
</body>
</html>
