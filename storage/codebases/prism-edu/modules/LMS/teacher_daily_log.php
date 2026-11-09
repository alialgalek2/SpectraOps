<?php
require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['teacher', 'admin']);

$teacher_id = Auth::id();
$success_msg = '';
$error_msg = '';

// Handle Log Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_log'])) {
    $class_id          = intval($_POST['class_id']);
    $subject_id        = intval($_POST['subject_id']);
    $log_date          = $_POST['log_date'];
    $lesson_topic      = trim($_POST['lesson_topic']);
    $summary_content   = trim($_POST['summary_content']);
    $homework_assigned = trim($_POST['homework_assigned']);
    $due_date          = !empty($_POST['due_date']) ? $_POST['due_date'] : NULL;

    if (!empty($class_id) && !empty($subject_id) && !empty($log_date) && !empty($lesson_topic)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO daily_logs (teacher_id, class_id, subject_id, log_date, lesson_topic, summary_content, homework_assigned, due_date)
                VALUES (:teacher_id, :class_id, :subject_id, :log_date, :lesson_topic, :summary_content, :homework_assigned, :due_date)
            ");
            $stmt->execute([
                ':teacher_id'        => $teacher_id,
                ':class_id'          => $class_id,
                ':subject_id'        => $subject_id,
                ':log_date'          => $log_date,
                ':lesson_topic'      => $lesson_topic,
                ':summary_content'   => $summary_content,
                ':homework_assigned' => $homework_assigned,
                ':due_date'          => $due_date
            ]);
            $success_msg = "Daily lesson log published successfully!";
        } catch (PDOException $e) {
            $error_msg = "Error saving daily log: " . $e->getMessage();
        }
    } else {
        $error_msg = "Please fill in all required fields.";
    }
}

// Fetch classes assigned to teacher or default list
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll();

// Fetch recent logs added by this teacher
$stmt_logs = $pdo->prepare("
    SELECT dl.*, c.class_name, s.subject_name 
    FROM daily_logs dl
    JOIN classes c ON dl.class_id = c.class_id
    JOIN subjects s ON dl.subject_id = s.subject_id
    WHERE dl.teacher_id = :teacher_id
    ORDER BY dl.log_date DESC, dl.created_at DESC
");
$stmt_logs->execute([':teacher_id' => $teacher_id]);
$recent_logs = $stmt_logs->fetchAll();

// Academic Calendar Milestones
$active_milestones = AcademicCalendar::getUpcomingMilestones(2);
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Portal - Daily Lesson & Homework Log</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => 'Daily Lesson & Homework Log',
    'back_url' => 'teacher_dashboard.php',
    'extra_nav_items' => []
]) ?>

<div class="container py-2">

    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error_msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($active_milestones)): ?>
        <div class="alert alert-info border-0 shadow-xs py-2 px-3 mb-3 d-flex align-items-center justify-content-between rounded-3 small">
            <span class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-calendar-check text-primary"></i>
                <span><strong><?= is_rtl() ? 'موعد أكاديمي قادم:' : 'Upcoming Calendar Event:' ?></strong> <?= htmlspecialchars($active_milestones[0]['title']) ?></span>
            </span>
            <span class="<?= htmlspecialchars($active_milestones[0]['countdown_badge']) ?>" style="font-size: 0.65rem;">
                <?= htmlspecialchars($active_milestones[0]['countdown_label']) ?>
            </span>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- New Log Entry Form -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white font-weight-bold">
                    <i class="fa-solid fa-pen-to-square me-2"></i>Publish New Entry
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label font-weight-bold">Date</label>
                            <input type="date" name="log_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Class</label>
                                <select name="class_id" class="form-select" required>
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Subject</label>
                                <select name="subject_id" class="form-select" required>
                                    <option value="">Select Subject</option>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Lesson Topic</label>
                            <input type="text" name="lesson_topic" class="form-control" placeholder="e.g., Intro to Database Queries" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Lesson Summary</label>
                            <textarea name="summary_content" class="form-control" rows="3" placeholder="Brief details of topics taught in class today..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Homework / Assignments</label>
                            <textarea name="homework_assigned" class="form-control" rows="2" placeholder="Exercises or tasks assigned for home..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Homework Due Date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>

                        <button type="submit" name="save_log" class="btn btn-primary w-100">
                            <i class="fa-solid fa-paper-plane me-1"></i> Publish Daily Log
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Published Logs -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-dark text-white font-weight-bold">
                    <i class="fa-solid fa-clock-rotate-left me-2"></i>Recent Published Logs
                </div>
                <div class="card-body p-0">
                    <?php if (count($recent_logs) > 0): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recent_logs as $log): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="badge bg-primary"><?= htmlspecialchars($log['class_name']) ?></span>
                                        <small class="text-muted"><i class="fa-regular fa-calendar me-1"></i><?= $log['log_date'] ?></small>
                                    </div>
                                    <h5 class="mb-1 text-dark"><?= htmlspecialchars($log['lesson_topic']) ?> <small class="text-muted"> (<?= htmlspecialchars($log['subject_name']) ?>)</small></h5>
                                    <p class="mb-2 text-secondary"><?= nl2br(htmlspecialchars($log['summary_content'])) ?></p>
                                    
                                    <?php if (!empty($log['homework_assigned'])): ?>
                                        <div class="p-2 bg-light rounded border-start border-3 border-warning">
                                            <strong><i class="fa-solid fa-pencil text-warning me-1"></i> Homework:</strong> <?= htmlspecialchars($log['homework_assigned']) ?>
                                            <?php if ($log['due_date']): ?>
                                                <span class="badge bg-outline-danger text-danger ms-2">Due: <?= $log['due_date'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fa-solid fa-folder-open fa-2x mb-2"></i>
                            <p class="mb-0">No daily logs published yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/form_auto_save.js"></script>
</body>
</html>