<?php
/**
 * modules/LMS/discussions.php
 * LMS Module - Moderated Topic Discussion Boards
 * (Core Umbrella Architecture)
 * 
 * Features:
 * 1. Structured discussion threads bound to courses & curriculum lesson topics.
 * 2. Peer questions, academic debates, and teacher mentorship.
 * 3. Teacher moderation: Pin important answers, lock threads, hide/delete inappropriate replies.
 * 4. Full Bilingual RTL/LTR Support & Unified System Theme.
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
$full_name = Auth::fullName();
$is_teacher_or_admin = in_array($user_role, ['admin', 'school_admin', 'teacher']);

$msg = '';
$msg_type = 'success';

// Handle Action Submissions (Post Thread, Reply, Moderate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Create Discussion Thread (Teacher / Admin)
        if ($action === 'create_thread' && $is_teacher_or_admin) {
            $course_id = (int)($_POST['course_id'] ?? 0);
            $topic_id  = !empty($_POST['topic_id']) ? (int)$_POST['topic_id'] : null;
            $title     = trim($_POST['title'] ?? '');
            $prompt    = trim($_POST['prompt'] ?? '');
            $is_pinned = isset($_POST['is_pinned']) ? 1 : 0;

            if ($course_id <= 0 || empty($title) || empty($prompt)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى إدخال عنوان وموضوع حلقة النقاش.' : 'Please enter the discussion title and prompt.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO course_discussions (course_id, topic_id, teacher_id, title, prompt, is_pinned, is_locked, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
            ");
            $stmt->execute([$course_id, $topic_id, $user_id, $title, $prompt, $is_pinned]);
            $new_disc_id = $pdo->lastInsertId();

            $_SESSION['flash_msg'] = is_rtl() ? 'تم فتح حلقة النقاش الأكاديمية بنجاح!' : 'Academic discussion thread opened successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: discussions.php?discussion_id=" . $new_disc_id);
            exit();
        }

        // 2. Post Reply (Students, Teachers, Admins)
        if ($action === 'post_reply') {
            $discussion_id = (int)($_POST['discussion_id'] ?? 0);
            $reply_text    = trim($_POST['reply_text'] ?? '');

            if ($discussion_id <= 0 || empty($reply_text)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى كتابة نص الرد للمشاركة.' : 'Please enter your reply text to participate.');
            }

            // Check if thread is locked
            $lock_check = $pdo->prepare("SELECT is_locked FROM course_discussions WHERE discussion_id = ?");
            $lock_check->execute([$discussion_id]);
            $is_locked = (bool)$lock_check->fetchColumn();

            if ($is_locked && !$is_teacher_or_admin) {
                throw new InvalidArgumentException(is_rtl() ? 'حلقة النقاش هذه مغلقة حالياً من قبل المعلم.' : 'This discussion thread has been locked by the instructor.');
            }

            $ins = $pdo->prepare("
                INSERT INTO course_discussion_replies (discussion_id, user_id, reply_text, is_pinned_answer, is_hidden, created_at)
                VALUES (?, ?, ?, 0, 0, NOW())
            ");
            $ins->execute([$discussion_id, $user_id, $reply_text]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تم نشر مشاركتك في حلقة النقاش!' : 'Your response was posted to the discussion!';
            $_SESSION['flash_type'] = 'success';
            header("Location: discussions.php?discussion_id=" . $discussion_id);
            exit();
        }

        // 3. Moderation Actions (Teachers / Admins)
        if ($is_teacher_or_admin) {
            // Pin/Unpin Best Answer
            if ($action === 'toggle_pin_reply') {
                $reply_id      = (int)($_POST['reply_id'] ?? 0);
                $discussion_id = (int)($_POST['discussion_id'] ?? 0);
                $curr_pinned   = (int)($_POST['curr_pinned'] ?? 0);
                $new_pin       = ($curr_pinned == 1) ? 0 : 1;

                $pdo->prepare("UPDATE course_discussion_replies SET is_pinned_answer = ? WHERE reply_id = ?")->execute([$new_pin, $reply_id]);
                $_SESSION['flash_msg'] = is_rtl() ? 'تم تحديث تثبيت الإجابة المتميزة!' : 'Answer pin status updated!';
                $_SESSION['flash_type'] = 'info';
                header("Location: discussions.php?discussion_id=" . $discussion_id);
                exit();
            }

            // Lock / Unlock Thread
            if ($action === 'toggle_lock_thread') {
                $discussion_id = (int)($_POST['discussion_id'] ?? 0);
                $curr_locked   = (int)($_POST['curr_locked'] ?? 0);
                $new_lock      = ($curr_locked == 1) ? 0 : 1;

                $pdo->prepare("UPDATE course_discussions SET is_locked = ? WHERE discussion_id = ?")->execute([$new_lock, $discussion_id]);
                $_SESSION['flash_msg'] = $new_lock ? (is_rtl() ? 'تم إغلاق حلقة النقاش.' : 'Discussion thread locked.') : (is_rtl() ? 'تمت إعادة فتح حلقة النقاش.' : 'Discussion thread reopened.');
                $_SESSION['flash_type'] = 'info';
                header("Location: discussions.php?discussion_id=" . $discussion_id);
                exit();
            }

            // Delete Reply
            if ($action === 'delete_reply') {
                $reply_id      = (int)($_POST['reply_id'] ?? 0);
                $discussion_id = (int)($_POST['discussion_id'] ?? 0);

                $pdo->prepare("DELETE FROM course_discussion_replies WHERE reply_id = ?")->execute([$reply_id]);
                $_SESSION['flash_msg'] = is_rtl() ? 'تم حذف الرد بنجاح.' : 'Reply removed successfully.';
                $_SESSION['flash_type'] = 'info';
                header("Location: discussions.php?discussion_id=" . $discussion_id);
                exit();
            }
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

// Fetch Courses for filtering
$courses = $pdo->query("SELECT course_id, course_name, course_code FROM courses ORDER BY created_at DESC")->fetchAll();

// Fetch Discussion Threads
$filter_course = (int)($_GET['course_id'] ?? 0);
$search_q      = trim($_GET['q'] ?? '');

$d_sql = "
    SELECT cd.*, c.course_name, c.course_code, ct.title AS topic_title, u.full_name AS author_name, u.role AS author_role,
           (SELECT COUNT(*) FROM course_discussion_replies cdr WHERE cdr.discussion_id = cd.discussion_id AND cdr.is_hidden = 0) AS replies_count
    FROM course_discussions cd
    JOIN courses c ON cd.course_id = c.course_id
    LEFT JOIN curriculum_topics ct ON cd.topic_id = ct.topic_id
    LEFT JOIN users u ON cd.teacher_id = u.user_id
    WHERE 1=1
";
$d_params = [];

if ($filter_course > 0) {
    $d_sql .= " AND cd.course_id = ?";
    $d_params[] = $filter_course;
}
if (!empty($search_q)) {
    $d_sql .= " AND (cd.title LIKE ? OR cd.prompt LIKE ?)";
    $d_params[] = "%$search_q%";
    $d_params[] = "%$search_q%";
}

$d_sql .= " ORDER BY cd.is_pinned DESC, cd.created_at DESC";
$d_stmt = $pdo->prepare($d_sql);
$d_stmt->execute($d_params);
$threads = $d_stmt->fetchAll();

// Selected Thread for Viewing
$active_thread_id = (int)($_GET['discussion_id'] ?? ($threads[0]['discussion_id'] ?? 0));
$current_thread = null;
$replies = [];

if ($active_thread_id > 0) {
    foreach ($threads as $t) {
        if ($t['discussion_id'] == $active_thread_id) {
            $current_thread = $t;
            break;
        }
    }
    if (!$current_thread) {
        $single_stmt = $pdo->prepare("
            SELECT cd.*, c.course_name, c.course_code, ct.title AS topic_title, u.full_name AS author_name, u.role AS author_role
            FROM course_discussions cd
            JOIN courses c ON cd.course_id = c.course_id
            LEFT JOIN curriculum_topics ct ON cd.topic_id = ct.topic_id
            LEFT JOIN users u ON cd.teacher_id = u.user_id
            WHERE cd.discussion_id = ?
        ");
        $single_stmt->execute([$active_thread_id]);
        $current_thread = $single_stmt->fetch();
    }

    if ($current_thread) {
        $rep_stmt = $pdo->prepare("
            SELECT r.*, u.full_name, u.role
            FROM course_discussion_replies r
            JOIN users u ON r.user_id = u.user_id
            WHERE r.discussion_id = ? AND r.is_hidden = 0
            ORDER BY r.is_pinned_answer DESC, r.created_at ASC
        ");
        $rep_stmt->execute([$current_thread['discussion_id']]);
        $replies = $rep_stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'حلقات النقاش الأكاديمية - LMS' : 'Topic Discussion Boards - LMS' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .pinned-reply {
            border: 2px solid #10b981 !important;
            background-color: rgba(16, 185, 129, 0.05) !important;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'حلقات النقاش' : 'Discussion Boards',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'دليل المقررات' : 'Courses') . '</a>',
        '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-info"></i> ' . (is_rtl() ? 'المستودع الرقمي' : 'Materials') . '</a>',
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-success"></i> ' . (is_rtl() ? 'الواجبات' : 'Assignments') . '</a>'
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
                <i class="fa-solid fa-comments text-purple me-2"></i><?= is_rtl() ? 'حلقات النقاش الأكاديمية والتبادل الفكري' : 'Moderated Topic Discussion Boards' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'مساحة للحوار التفاعلي حول موضوعات الدروس، طرح التساؤلات، وتثبيت الإجابات النموذجية بإشراف المعلمين.' : 'Structured peer discussions linked to curriculum topics with instructor moderation controls.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($is_teacher_or_admin): ?>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newThreadModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> <?= is_rtl() ? 'فتح حلقة نقاش جديدة' : 'New Discussion Thread' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Discussion Layout -->
    <div class="row g-4">
        <!-- Sidebar: Thread Navigator -->
        <div class="col-lg-4 col-md-5">
            <div class="card border border-border rounded-xl bg-card shadow-sm mb-4">
                <div class="card-header bg-card border-bottom border-border py-3">
                    <form method="GET" action="" class="mb-0">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-background border-border text-muted-foreground"><i class="fa-solid fa-search"></i></span>
                            <input type="text" name="q" class="form-control bg-background text-foreground border-border" placeholder="<?= is_rtl() ? 'بحث في حلقات النقاش...' : 'Search threads...' ?>" value="<?= htmlspecialchars($search_q) ?>">
                        </div>
                    </form>
                </div>
                <div class="list-group list-group-flush rounded-bottom-xl" style="max-height: 70vh; overflow-y: auto;">
                    <?php if (empty($threads)): ?>
                        <div class="p-4 text-center text-muted-foreground small">
                            <?= is_rtl() ? 'لا توجد حلقات نقاش مفتوحة حالياً.' : 'No active discussion threads found.' ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($threads as $th): ?>
                            <?php $is_act = ($current_thread && $current_thread['discussion_id'] == $th['discussion_id']); ?>
                            <a href="discussions.php?discussion_id=<?= $th['discussion_id'] ?>" 
                               class="list-group-item list-group-item-action py-3 <?= $is_act ? 'active bg-primary-subtle text-primary border-primary-subtle' : 'text-foreground' ?>">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="badge bg-secondary text-muted-foreground rounded-pill extra-small">
                                        <?= htmlspecialchars($th['course_code'] ?? 'CRS') ?>
                                    </span>
                                    <div class="d-flex align-items-center gap-1">
                                        <?php if ($th['is_pinned']): ?>
                                            <i class="fa-solid fa-thumbtack text-warning extra-small" title="Pinned Thread"></i>
                                        <?php endif; ?>
                                        <?php if ($th['is_locked']): ?>
                                            <i class="fa-solid fa-lock text-muted-foreground extra-small" title="Locked Thread"></i>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fw-bold small mb-1"><?= htmlspecialchars($th['title']) ?></div>
                                <div class="d-flex justify-content-between align-items-center extra-small text-muted-foreground">
                                    <span><i class="fa-solid fa-user me-1"></i><?= htmlspecialchars($th['author_name'] ?? 'Faculty') ?></span>
                                    <span><i class="fa-regular fa-comment-dots me-1"></i><?= (int)$th['replies_count'] ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Workspace: Active Thread & Conversation -->
        <div class="col-lg-8 col-md-7">
            <?php if (!$current_thread): ?>
                <div class="card border border-border rounded-xl bg-card shadow-sm p-5 text-center">
                    <div class="action-icon-box icon-box-purple mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="fa-solid fa-comments"></i>
                    </div>
                    <h5 class="fw-bold text-foreground mb-1"><?= is_rtl() ? 'اختر حلقة نقاش للبدء' : 'Select a discussion thread to view conversation' ?></h5>
                    <p class="text-muted-foreground small"><?= is_rtl() ? 'يمكنك المشاركة بالردود الأكاديمية ومراجعة الإجابات النموذجية المثبتة.' : 'Participate in peer discussions and check pinned answers.' ?></p>
                </div>
            <?php else: ?>
                <!-- Main Discussion Prompt Card -->
                <div class="card border border-border rounded-xl bg-card shadow-sm mb-4 p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                                    <?= htmlspecialchars($current_thread['course_name']) ?>
                                </span>
                                <?php if (!empty($current_thread['topic_title'])): ?>
                                    <span class="badge bg-secondary text-muted-foreground rounded-pill px-3 py-1">
                                        <i class="fa-solid fa-book-open me-1"></i><?= htmlspecialchars($current_thread['topic_title']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($current_thread['is_locked']): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3 py-1">
                                        <i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'مغلقة' : 'Locked' ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h4 class="fw-bold text-foreground mb-1"><?= htmlspecialchars($current_thread['title']) ?></h4>
                            <div class="text-muted-foreground extra-small">
                                <i class="fa-solid fa-user-tie text-primary me-1"></i> <?= htmlspecialchars($current_thread['author_name'] ?? 'Faculty') ?> • 
                                <i class="fa-regular fa-clock me-1"></i> <?= date('Y-m-d H:i', strtotime($current_thread['created_at'])) ?>
                            </div>
                        </div>

                        <!-- Teacher Moderation Tools -->
                        <?php if ($is_teacher_or_admin): ?>
                            <div class="d-flex align-items-center gap-1">
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="toggle_lock_thread">
                                    <input type="hidden" name="discussion_id" value="<?= $current_thread['discussion_id'] ?>">
                                    <input type="hidden" name="curr_locked" value="<?= $current_thread['is_locked'] ?>">
                                    <button type="submit" class="btn btn-outline-border btn-sm rounded-lg extra-small">
                                        <i class="fa-solid <?= $current_thread['is_locked'] ? 'fa-lock-open text-success' : 'fa-lock text-warning' ?> me-1"></i>
                                        <?= $current_thread['is_locked'] ? (is_rtl() ? 'فتح النقاش' : 'Unlock') : (is_rtl() ? 'إغلاق النقاش' : 'Lock Thread') ?>
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="p-3 bg-background border border-border rounded-xl text-foreground mb-0">
                        <?= nl2br(htmlspecialchars($current_thread['prompt'])) ?>
                    </div>
                </div>

                <!-- Responses Section -->
                <h5 class="fw-bold text-foreground mb-3 d-flex align-items-center justify-content-between">
                    <span><i class="fa-solid fa-reply-all text-primary me-2"></i><?= is_rtl() ? 'الردود والمشاركات' : 'Discussion Responses' ?> (<?= count($replies) ?>)</span>
                </h5>

                <div class="d-flex flex-column gap-3 mb-4">
                    <?php if (empty($replies)): ?>
                        <div class="card border border-border rounded-xl bg-card shadow-sm p-4 text-center text-muted-foreground small">
                            <?= is_rtl() ? 'لا توجد ردود بعد. كن أول من يشارك في هذا النقاش!' : 'No responses posted yet. Be the first to share your thoughts!' ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($replies as $rep): ?>
                            <?php $is_pin = ($rep['is_pinned_answer'] == 1); ?>
                            <div class="card border border-border rounded-xl bg-card shadow-sm p-3 <?= $is_pin ? 'pinned-reply' : '' ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-badge rounded-circle bg-primary-subtle text-primary border border-primary-subtle d-flex align-items-center justify-content-center fw-bold" style="width: 34px; height: 34px; font-size: 0.8rem;">
                                            <?= strtoupper(substr($rep['full_name'], 0, 2)) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold small text-foreground">
                                                <?= htmlspecialchars($rep['full_name']) ?>
                                                <span class="badge bg-secondary text-muted-foreground rounded-pill extra-small ms-1"><?= ucfirst($rep['role']) ?></span>
                                                <?php if ($is_pin): ?>
                                                    <span class="badge bg-success text-white rounded-pill extra-small ms-1">
                                                        <i class="fa-solid fa-check-circle me-1"></i><?= is_rtl() ? 'إجابة متميزة مثبتة' : 'Pinned Best Answer' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="extra-small text-muted-foreground"><?= date('Y-m-d H:i', strtotime($rep['created_at'])) ?></div>
                                        </div>
                                    </div>

                                    <!-- Moderation Actions -->
                                    <?php if ($is_teacher_or_admin): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_pin_reply">
                                                <input type="hidden" name="discussion_id" value="<?= $current_thread['discussion_id'] ?>">
                                                <input type="hidden" name="reply_id" value="<?= $rep['reply_id'] ?>">
                                                <input type="hidden" name="curr_pinned" value="<?= $rep['is_pinned_answer'] ?>">
                                                <button type="submit" class="btn btn-outline-border btn-sm rounded-lg extra-small" title="<?= $is_pin ? 'Unpin' : 'Pin as Best Answer' ?>">
                                                    <i class="fa-solid fa-thumbtack <?= $is_pin ? 'text-success' : 'text-muted-foreground' ?>"></i>
                                                </button>
                                            </form>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذا الرد؟' : 'Delete this reply?' ?>');">
                                                <input type="hidden" name="action" value="delete_reply">
                                                <input type="hidden" name="discussion_id" value="<?= $current_thread['discussion_id'] ?>">
                                                <input type="hidden" name="reply_id" value="<?= $rep['reply_id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-sm rounded-lg extra-small" title="Delete">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="small text-foreground pt-1">
                                    <?= nl2br(htmlspecialchars($rep['reply_text'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Post Reply Box -->
                <?php if (!$current_thread['is_locked'] || $is_teacher_or_admin): ?>
                    <div class="card border border-border rounded-xl bg-card shadow-sm p-4">
                        <h6 class="fw-bold text-foreground mb-2">
                            <i class="fa-solid fa-paper-plane text-primary me-2"></i><?= is_rtl() ? 'إضافة رد أو استفسار' : 'Post Your Reply' ?>
                        </h6>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="post_reply">
                            <input type="hidden" name="discussion_id" value="<?= $current_thread['discussion_id'] ?>">
                            <div class="mb-3">
                                <textarea name="reply_text" rows="3" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="<?= is_rtl() ? 'اكتب رأيك أو سؤالك الأكاديمي هنا...' : 'Write your academic answer or inquiry...' ?>" required></textarea>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary rounded-xl px-4">
                                    <i class="fa-solid fa-paper-plane me-1"></i> <?= is_rtl() ? 'إرسال الرد' : 'Submit Reply' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="alert alert-secondary border border-border rounded-xl text-center text-muted-foreground small py-3">
                        <i class="fa-solid fa-lock me-2"></i><?= is_rtl() ? 'حلقة النقاش هذه مغلقة ولا تقبل ردوداً جديدة حالياً.' : 'This discussion thread is locked and no longer accepts new replies.' ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: New Discussion Thread -->
<?php if ($is_teacher_or_admin): ?>
<div class="modal fade" id="newThreadModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_thread">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground">
                        <i class="fa-solid fa-comments text-purple me-2"></i><?= is_rtl() ? 'فتح حلقة نقاش أكاديمية جديدة' : 'New Discussion Thread' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المقرر الدراسي:' : 'Course Catalog:' ?></label>
                        <select name="course_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                            <?php foreach ($courses as $crs): ?>
                                <option value="<?= $crs['course_id'] ?>"><?= htmlspecialchars($crs['course_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'عنوان موضوع النقاش:' : 'Discussion Title:' ?></label>
                        <input type="text" name="title" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g. Solving Quadratic Equations with Complex Roots" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'السؤال / التوجيه الأكاديمي للطلاب:' : 'Discussion Prompt / Question:' ?></label>
                        <textarea name="prompt" rows="4" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="Formulate the prompt or inquiry students should deliberate on..." required></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_pinned" id="checkPin" value="1">
                        <label class="form-check-label small fw-bold text-muted-foreground" for="checkPin">
                            <?= is_rtl() ? 'تثبيت الموضوع في أعلى القائمة (Pinned Thread)' : 'Pin thread to top of discussion list' ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <button type="button" class="btn btn-secondary rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-lg px-3"><?= is_rtl() ? 'نشر حلقة النقاش' : 'Publish Thread' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
