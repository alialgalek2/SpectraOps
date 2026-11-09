<?php
/**
 * LMS Module - Classroom Announcements & Noticeboard Stream
 * Features: Multi-audience broadcasts, class-specific notices, file attachments, and urgent push alerts.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireLogin();

$role = Auth::role();
$user_id = Auth::id();
$full_name = Auth::fullName();
$can_post = in_array($role, ['admin', 'teacher', 'superadmin']);

$msg = "";
$msg_type = "info";

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_post) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'post_notice') {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $target_role = $_POST['target_role'] ?? 'All';
            $class_id = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;
            $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;

            if (empty($title) || empty($content)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى كتابة عنوان الإعلان ومحتوى الرسالة.' : 'Please enter the announcement title and message content.');
            }

            $file_path = null;
            // Handle optional file attachment
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $allowed_extensions = ['pdf', 'docx', 'doc', 'pptx', 'ppt', 'png', 'jpg', 'jpeg', 'zip'];
                $max_size = 10 * 1024 * 1024; // 10MB

                $file_name = $_FILES['attachment']['name'];
                $file_tmp  = $_FILES['attachment']['tmp_name'];
                $file_size = $_FILES['attachment']['size'];
                $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_extensions)) {
                    throw new InvalidArgumentException(is_rtl() ? "نوع الملف غير مدعوم ($file_ext)." : "File extension not permitted ($file_ext).");
                }

                if ($file_size > $max_size) {
                    throw new InvalidArgumentException(is_rtl() ? "حجم الملف يتجاوز الحد الأقصى (10 ميجابايت)." : "File size exceeds 10MB limit.");
                }

                $target_dir = __DIR__ . '/../../uploads/announcements/';
                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                $safe_basename = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file_name);
                $destination = $target_dir . $safe_basename;

                if (move_uploaded_file($file_tmp, $destination)) {
                    $file_path = 'uploads/announcements/' . $safe_basename;
                } else {
                    throw new RuntimeException(is_rtl() ? 'فشل تحميل المرفق إلى الخادم.' : 'Failed to save attached file.');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO announcements (title, message, target_role, class_id, file_path, is_urgent, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$title, $content, $target_role, $class_id, $file_path, $is_urgent, $user_id]);

            $msg = is_rtl() ? "تم بث الإعلان بنجاح." : "Announcement broadcasted successfully.";
            $msg_type = "success";
        } elseif ($action === 'delete_notice') {
            $notice_id = (int)($_POST['notice_id'] ?? 0);
            if ($notice_id > 0) {
                // Admin can delete any; Teacher can delete their own
                if ($role === 'admin' || $role === 'superadmin') {
                    $stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ?");
                    $stmt->execute([$notice_id]);
                } else {
                    $stmt = $pdo->prepare("DELETE FROM announcements WHERE announcement_id = ? AND created_by = ?");
                    $stmt->execute([$notice_id, $user_id]);
                }
                $msg = is_rtl() ? "تم حذف الإعلان." : "Announcement removed successfully.";
                $msg_type = "success";
            }
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Classes for targeting filter & modal
$classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();

// Filter parameters
$filter_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : null;
$filter_audience = trim($_GET['audience'] ?? '');
$search_query = trim($_GET['q'] ?? '');

$sql = "
    SELECT a.*, u.full_name as author_name, u.role as author_role, cl.class_name
    FROM announcements a
    LEFT JOIN users u ON a.created_by = u.user_id
    LEFT JOIN classes cl ON a.class_id = cl.class_id
    WHERE 1=1
";
$params = [];

if ($filter_class !== null) {
    $sql .= " AND a.class_id = ?";
    $params[] = $filter_class;
}

if (!empty($filter_audience)) {
    $sql .= " AND (a.target_role = ? OR a.target_role = 'All')";
    $params[] = $filter_audience;
}

if (!empty($search_query)) {
    $sql .= " AND (a.title LIKE ? OR a.message LIKE ?)";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

// Non-admin roles see relevant notices
if ($role === 'student') {
    $sql .= " AND (a.target_role IN ('All', 'student'))";
} elseif ($role === 'parent') {
    $sql .= " AND (a.target_role IN ('All', 'parent'))";
} elseif ($role === 'teacher') {
    $sql .= " AND (a.target_role IN ('All', 'teacher', 'student'))";
}

$sql .= " ORDER BY a.is_urgent DESC, a.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$announcements = $stmt->fetchAll();

// Calculate summary counts
$total_count = count($announcements);
$urgent_count = 0;
$class_specific_count = 0;
$school_wide_count = 0;

foreach ($announcements as $a) {
    if (!empty($a['is_urgent'])) $urgent_count++;
    if (!empty($a['class_id'])) $class_specific_count++;
    else $school_wide_count++;
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'لوحة الإعلانات وتنبيهات الفصول - LMS' : 'Class Stream & Announcements - LMS' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .notice-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .notice-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
        }
        .notice-card.urgent {
            border-left: 5px solid var(--danger, #dc3545);
        }
        [dir="rtl"] .notice-card.urgent {
            border-left: 1px solid var(--border);
            border-right: 5px solid var(--danger, #dc3545);
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'لوحة الإعلانات' : 'Class Stream & Notices',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'المناهج' : 'Courses') . '</a>',
        '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-info"></i> ' . (is_rtl() ? 'المستودع الرقمي' : 'Materials') . '</a>',
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-success"></i> ' . (is_rtl() ? 'الواجبات' : 'Assignments') . '</a>',
        '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Flash Alert -->
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
                <i class="fa-solid fa-bullhorn text-warning me-2"></i><?= is_rtl() ? 'لوحة الإعلانات والتنبيهات المدرسية' : 'Class Stream & Announcements' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'تنبيهات فورية، رسائل الفصول، والتحديثات المدرسية مع المرفقات وإشعارات الطوارئ.' : 'Daily agenda updates, class stream announcements, file attachments, and critical push alerts.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_post): ?>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newNoticeModal">
                    <i class="fa-solid fa-pen-nib me-1"></i> <?= is_rtl() ? 'نشر إعلان جديد' : 'Broadcast Announcement' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overview Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $total_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي الإعلانات' : 'Total Bulletins' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-danger me-3">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $urgent_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'تنبيهات عاجلة' : 'Urgent Alerts' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $class_specific_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إعلانات الفصول' : 'Class Notices' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $school_wide_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إعلانات عامة' : 'School-Wide' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-12 col-md-4">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-background border-border text-muted-foreground"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" name="q" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'البحث في الإعلانات...' : 'Search bulletins & keywords...' ?>" value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="class_id" class="form-select form-select-sm bg-background border-border text-foreground">
                    <option value=""><?= is_rtl() ? 'جميع الفصول / عام' : 'All Classes / School-Wide' ?></option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['class_id'] ?>" <?= $filter_class === (int)$c['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="audience" class="form-select form-select-sm bg-background border-border text-foreground">
                    <option value=""><?= is_rtl() ? 'كافة الفئات المستهدفة' : 'All Audiences' ?></option>
                    <option value="All" <?= $filter_audience === 'All' ? 'selected' : '' ?>><?= is_rtl() ? 'الجميع (عام)' : 'Everyone (General)' ?></option>
                    <option value="student" <?= $filter_audience === 'student' ? 'selected' : '' ?>><?= is_rtl() ? 'الطلاب فقط' : 'Students Only' ?></option>
                    <option value="parent" <?= $filter_audience === 'parent' ? 'selected' : '' ?>><?= is_rtl() ? 'أولياء الأمور' : 'Parents Only' ?></option>
                    <option value="teacher" <?= $filter_audience === 'teacher' ? 'selected' : '' ?>><?= is_rtl() ? 'المعلمون فقط' : 'Teachers Only' ?></option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-lg flex-fill">
                    <i class="fa-solid fa-filter me-1"></i><?= is_rtl() ? 'تصفية' : 'Filter' ?>
                </button>
                <a href="announcements.php" class="btn btn-outline-border btn-sm rounded-lg">
                    <i class="fa-solid fa-rotate-left"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Announcement Stream -->
    <div class="row g-3">
        <?php if (empty($announcements)): ?>
            <div class="col-12 text-center py-5">
                <div class="action-icon-box icon-box-muted mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.6rem;">
                    <i class="fa-solid fa-bullhorn text-muted-foreground"></i>
                </div>
                <h6 class="fw-bold text-foreground"><?= is_rtl() ? 'لا توجد إعلانات مطابقة' : 'No announcements found' ?></h6>
                <p class="text-muted-foreground small"><?= is_rtl() ? 'لم يتم نشر أية إعلانات أو تعاميم تلبي معايير البحث الحالية.' : 'No announcements or circulars have been broadcast matching current filters.' ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($announcements as $a): ?>
                <div class="col-12">
                    <div class="notice-card p-4 <?= !empty($a['is_urgent']) ? 'urgent' : '' ?> shadow-sm">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($a['is_urgent'])): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill">
                                        <i class="fa-solid fa-bolt-lightning me-1 animate-pulse"></i><?= is_rtl() ? 'تنبيه عاجل' : 'Urgent Alert' ?>
                                    </span>
                                <?php endif; ?>
                                <h5 class="fw-bold font-heading text-foreground mb-0">
                                    <?= htmlspecialchars($a['title']) ?>
                                </h5>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?php if (!empty($a['class_name'])): ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                        <i class="fa-solid fa-chalkboard me-1"></i><?= htmlspecialchars($a['class_name']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">
                                        <i class="fa-solid fa-globe me-1"></i><?= is_rtl() ? 'عام (المدرسة ككل)' : 'School-Wide' ?>
                                    </span>
                                <?php endif; ?>

                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
                                    <i class="fa-solid fa-users me-1"></i><?= ucfirst(htmlspecialchars($a['target_role'])) ?>
                                </span>

                                <?php if ($can_post && ($role === 'admin' || $role === 'superadmin' || $a['created_by'] == $user_id)): ?>
                                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذا الإعلان؟' : 'Are you sure you want to remove this announcement?' ?>');">
                                        <input type="hidden" name="action" value="delete_notice">
                                        <input type="hidden" name="notice_id" value="<?= $a['announcement_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill py-0 px-2" title="<?= is_rtl() ? 'حذف' : 'Delete' ?>">
                                            <i class="fa-solid fa-trash-can fa-xs"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="text-foreground small mb-3 lh-base" style="white-space: pre-line;">
                            <?= htmlspecialchars($a['message']) ?>
                        </div>

                        <!-- File Attachment if present -->
                        <?php if (!empty($a['file_path'])): ?>
                            <div class="mb-3">
                                <a href="../../<?= htmlspecialchars($a['file_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm rounded-lg d-inline-flex align-items-center gap-2">
                                    <i class="fa-solid fa-paperclip"></i>
                                    <span><?= is_rtl() ? 'عرض / تحميل المرفق' : 'Download Attached File' ?></span>
                                    <i class="fa-solid fa-arrow-up-right-from-square fa-xs"></i>
                                </a>
                            </div>
                        <?php endif; ?>

                        <!-- Card Footer Meta -->
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 pt-2 border-top border-border text-muted-foreground small">
                            <div class="d-flex align-items-center gap-2">
                                <div class="action-icon-box icon-box-muted" style="width: 24px; height: 24px; font-size: 0.75rem;">
                                    <i class="fa-solid fa-user-pen"></i>
                                </div>
                                <span><?= htmlspecialchars($a['author_name'] ?? 'School Administration') ?></span>
                                <span class="badge bg-background border border-border text-muted-foreground"><?= ucfirst($a['author_role'] ?? 'Staff') ?></span>
                            </div>
                            <div>
                                <i class="fa-regular fa-clock me-1"></i>
                                <?= date('M d, Y - h:i A', strtotime($a['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Broadcast Announcement Modal -->
<?php if ($can_post): ?>
<div class="modal fade" id="newNoticeModal" tabindex="-1" aria-labelledby="newNoticeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-foreground" id="newNoticeModalLabel">
                    <i class="fa-solid fa-pen-nib text-primary me-2"></i><?= is_rtl() ? 'بث إعلان / تعميم مدرسي جديد' : 'Broadcast School Announcement' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="post_notice">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'عنوان الإعلان *' : 'Notice Title *' ?></label>
                            <input type="text" name="title" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'مثال: جدول اختبارات منتصف الفصل الدراسي' : 'E.g., Midterm Examination Schedule Release' ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفئة المستهدفة *' : 'Target Audience *' ?></label>
                            <select name="target_role" class="form-select bg-background border-border text-foreground" required>
                                <option value="All"><?= is_rtl() ? 'الجميع (المدرسة بالكامل)' : 'Everyone (All Users)' ?></option>
                                <option value="student"><?= is_rtl() ? 'الطلاب فقط' : 'Students Only' ?></option>
                                <option value="parent"><?= is_rtl() ? 'أولياء الأمور فقط' : 'Parents Only' ?></option>
                                <option value="teacher"><?= is_rtl() ? 'هيئة التدريس فقط' : 'Teachers / Staff Only' ?></option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي المستهدف (اختياري)' : 'Target Class / Section (Optional)' ?></label>
                            <select name="class_id" class="form-select bg-background border-border text-foreground">
                                <option value=""><?= is_rtl() ? 'عام (غير مخصص لفصل بعينه)' : 'School-Wide (Not Class-Specific)' ?></option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['class_id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small text-muted-foreground"><?= is_rtl() ? 'اتركه فارغاً لنشر الإعلان على مستوى المدرسة.' : 'Leave blank to broadcast to the entire school grade spectrum.' ?></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'إرفاق ملف / تعميم (اختياري)' : 'Attach Document / Circular (Optional)' ?></label>
                            <input type="file" name="attachment" class="form-control bg-background border-border text-foreground" accept=".pdf,.docx,.doc,.pptx,.ppt,.png,.jpg,.jpeg,.zip">
                            <div class="form-text small text-muted-foreground"><?= is_rtl() ? 'الحد الأقصى 10 ميجابايت (PDF, DOCX, صور).' : 'Max 10MB (PDF, DOCX, Images).' ?></div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تفاصيل الإعلان / نص الرسالة *' : 'Notice Content / Full Message *' ?></label>
                            <textarea name="content" rows="4" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'اكتب تفاصيل التحديث أو التوجيهات هنا...' : 'Write detailed announcement points, agenda, or directives here...' ?>" required></textarea>
                        </div>

                        <div class="col-12">
                            <div class="form-check form-switch p-3 bg-background border border-border rounded-xl">
                                <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="urgentSwitch" name="is_urgent" value="1">
                                <label class="form-check-label fw-bold text-danger" for="urgentSwitch">
                                    <i class="fa-solid fa-bolt-lightning me-1"></i> <?= is_rtl() ? 'تحديد كتنبيه عاجل (تثبيت وإبراز فوري في البوابة)' : 'Mark as Urgent Notice (High Priority Pin & Portal Alert)' ?>
                                </label>
                                <div class="small text-muted-foreground ms-4"><?= is_rtl() ? 'سيظهر الإعلان بشريط أحمر مميز في أعلى قائمة التنبيهات للطلاب وأولياء الأمور.' : 'Displays with prominent red branding and pin priority across student and parent portal streams.' ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-xl px-4">
                        <i class="fa-solid fa-paper-plane me-1"></i> <?= is_rtl() ? 'نشر الإعلان' : 'Broadcast Now' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>