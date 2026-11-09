<?php
/**
 * LMS Module - Parent-Teacher Tracked Communication Center
 * Replaces informal chats with official, tracked messages and read receipts.
 */

require_once __DIR__ . '/../../core/init.php';

// Access Control: Any authenticated user (Admin, Teacher, Parent, Student)
Auth::requireLogin();

$user_id   = Auth::id();
$user_role = Auth::role();
$full_name = Auth::fullName();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Send Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    $receiver_id  = intval($_POST['receiver_id'] ?? 0);
    $student_id   = !empty($_POST['student_id']) ? intval($_POST['student_id']) : null;
    $subject      = trim($_POST['subject'] ?? '');
    $body         = trim($_POST['message'] ?? '');
    $inquiry_type = $_POST['inquiry_type'] ?? 'General';
    $parent_msg_id = !empty($_POST['parent_message_id']) ? intval($_POST['parent_message_id']) : null;

    if ($receiver_id > 0 && !empty($subject) && !empty($body)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, student_id, subject, message, inquiry_type, parent_message_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $receiver_id, $student_id, $subject, $body, $inquiry_type, $parent_msg_id]);

            // Dispatch inter-module event
            EventDispatcher::dispatch('message.sent', [
                'sender_id'   => $user_id,
                'receiver_id' => $receiver_id,
                'subject'     => $subject
            ]);

            $_SESSION['flash_msg'] = "Official message sent successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: messages.php?tab=sent");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error sending message: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please select a recipient and enter both a subject and message.";
        $msg_type = "danger";
    }
}

// Mark Message as Read if opening detail view
$view_id = intval($_GET['view'] ?? 0);
$viewMessage = null;
if ($view_id > 0) {
    $vStmt = $pdo->prepare("
        SELECT m.*, su.full_name as sender_name, su.role as sender_role,
               ru.full_name as receiver_name, s.admission_no, st_u.full_name as student_name
        FROM messages m
        JOIN users su ON m.sender_id = su.user_id
        JOIN users ru ON m.receiver_id = ru.user_id
        LEFT JOIN students s ON m.student_id = s.student_id
        LEFT JOIN users st_u ON s.user_id = st_u.user_id
        WHERE m.message_id = ? AND (m.receiver_id = ? OR m.sender_id = ?)
        LIMIT 1
    ");
    $vStmt->execute([$view_id, $user_id, $user_id]);
    $viewMessage = $vStmt->fetch();

    if ($viewMessage && $viewMessage['receiver_id'] == $user_id && !$viewMessage['is_read']) {
        $upd = $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW() WHERE message_id = ?");
        $upd->execute([$view_id]);
    }
}

// Fetch Inbox Messages
$inboxStmt = $pdo->prepare("
    SELECT m.*, su.full_name as sender_name, su.role as sender_role, st_u.full_name as student_name
    FROM messages m
    JOIN users su ON m.sender_id = su.user_id
    LEFT JOIN students s ON m.student_id = s.student_id
    LEFT JOIN users st_u ON s.user_id = st_u.user_id
    WHERE m.receiver_id = ?
    ORDER BY m.created_at DESC
");
$inboxStmt->execute([$user_id]);
$inbox = $inboxStmt->fetchAll();

// Fetch Sent Messages
$sentStmt = $pdo->prepare("
    SELECT m.*, ru.full_name as receiver_name, ru.role as receiver_role, st_u.full_name as student_name
    FROM messages m
    JOIN users ru ON m.receiver_id = ru.user_id
    LEFT JOIN students s ON m.student_id = s.student_id
    LEFT JOIN users st_u ON s.user_id = st_u.user_id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC
");
$sentStmt->execute([$user_id]);
$sent = $sentStmt->fetchAll();

// Potential Recipients Dropdown based on Role
$recipients = [];
if ($user_role === 'parent') {
    // Teachers and Admins
    $recipients = $pdo->query("SELECT user_id, full_name, role FROM users WHERE role IN ('teacher', 'admin') AND status = 'Active' ORDER BY full_name ASC")->fetchAll();
} elseif ($user_role === 'teacher') {
    // Parents and Admins
    $recipients = $pdo->query("SELECT user_id, full_name, role FROM users WHERE role IN ('parent', 'admin') AND status = 'Active' ORDER BY full_name ASC")->fetchAll();
} else {
    // Admin can message anyone
    $recipients = $pdo->query("SELECT user_id, full_name, role FROM users WHERE user_id != {$user_id} AND status = 'Active' ORDER BY full_name ASC")->fetchAll();
}

$active_tab = $_GET['tab'] ?? 'inbox';
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('parent_teacher_messages') ?> - School Management</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-msg {
            background: linear-gradient(135deg, #0984e3 0%, #6c5ce7 100%);
            color: white;
            border-radius: 16px;
        }
        .msg-unread {
            background-color: #f0f7ff !important;
            font-weight: 700;
        }
        .inquiry-badge {
            font-size: 0.75rem;
            text-transform: uppercase;
        }
    </style>
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('parent_teacher_messages'),
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="p-4 p-md-5 mb-4 hero-msg shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-envelope-open-text me-2"></i><?= __('parent_teacher_messages') ?></h2>
                <p class="mb-0 opacity-75"><?= __('parent_teacher_messages_sub') ?></p>
            </div>
            <button class="btn btn-warning btn-sm fw-bold px-3 py-2" data-bs-toggle="modal" data-bs-target="#composeModal">
                <i class="fa-solid fa-pen-to-square me-1"></i> <?= __('compose_message') ?>
            </button>
        </div>
    </div>

    <!-- Message Detail View (If Opened) -->
    <?php if ($viewMessage): ?>
        <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
            <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                <div>
                    <span class="badge bg-primary inquiry-badge mb-1"><?= $viewMessage['inquiry_type'] ?> Inquiry</span>
                    <h4 class="fw-bold text-dark mb-0"><?= htmlspecialchars($viewMessage['subject']) ?></h4>
                </div>
                <a href="messages.php?tab=<?= $active_tab ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?= __('back') ?>
                </a>
            </div>
            <div class="d-flex justify-content-between align-items-center text-muted small mb-4">
                <div>
                    <strong>From:</strong> <?= htmlspecialchars($viewMessage['sender_name']) ?> (<span class="badge bg-secondary"><?= ucfirst($viewMessage['sender_role']) ?></span>)
                    <strong class="ms-3">To:</strong> <?= htmlspecialchars($viewMessage['receiver_name']) ?>
                    <?php if (!empty($viewMessage['student_name'])): ?>
                        <strong class="ms-3">Regarding:</strong> <?= htmlspecialchars($viewMessage['student_name']) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <i class="fa-regular fa-clock me-1"></i> <?= date('M d, Y h:i A', strtotime($viewMessage['created_at'])) ?>
                    <?php if ($viewMessage['is_read']): ?>
                        <span class="badge bg-success bg-opacity-10 text-success ms-2"><i class="fa-solid fa-check-double me-1"></i> Read</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="p-4 bg-light rounded-3 mb-4 text-dark" style="font-size: 1.05rem; line-height: 1.6;">
                <?= nl2br(htmlspecialchars($viewMessage['message'])) ?>
            </div>

            <!-- Reply Button -->
            <button class="btn btn-primary fw-bold" onclick="replyMessage(<?= $viewMessage['sender_id'] ?>, '<?= addslashes($viewMessage['sender_name']) ?>', 'Re: <?= addslashes($viewMessage['subject']) ?>', <?= $viewMessage['message_id'] ?>)">
                <i class="fa-solid fa-reply me-1"></i> Reply to <?= htmlspecialchars($viewMessage['sender_name']) ?>
            </button>
        </div>
    <?php endif; ?>

    <!-- Tabs Navigation -->
    <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
        <ul class="nav nav-pills mb-4" id="msgTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'inbox' ? 'active' : '' ?> fw-bold" href="?tab=inbox">
                    <i class="fa-solid fa-inbox me-1"></i> <?= __('inbox') ?> (<?= count($inbox) ?>)
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $active_tab === 'sent' ? 'active' : '' ?> fw-bold" href="?tab=sent">
                    <i class="fa-solid fa-paper-plane me-1"></i> <?= __('sent_messages') ?> (<?= count($sent) ?>)
                </a>
            </li>
        </ul>

        <?php if ($active_tab === 'inbox'): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Status</th>
                            <th>Sender</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inbox)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">Your inbox is currently empty.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inbox as $m): ?>
                                <tr class="<?= !$m['is_read'] ? 'msg-unread' : '' ?>">
                                    <td>
                                        <?php if (!$m['is_read']): ?>
                                            <span class="badge bg-primary rounded-pill">New</span>
                                        <?php else: ?>
                                            <i class="fa-solid fa-envelope-open text-muted"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($m['sender_name']) ?> (<span class="text-muted small"><?= ucfirst($m['sender_role']) ?></span>)</td>
                                    <td>
                                        <a href="?tab=inbox&view=<?= $m['message_id'] ?>" class="text-dark text-decoration-none fw-bold">
                                            <?= htmlspecialchars($m['subject']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary inquiry-badge"><?= $m['inquiry_type'] ?></span>
                                    </td>
                                    <td class="text-muted small"><?= date('M d, Y', strtotime($m['created_at'])) ?></td>
                                    <td>
                                        <a href="?tab=inbox&view=<?= $m['message_id'] ?>" class="btn btn-outline-primary btn-sm">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($active_tab === 'sent'): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Category</th>
                            <th>Read Status</th>
                            <th>Date Sent</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sent)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No sent messages.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sent as $m): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($m['receiver_name']) ?> (<span class="text-muted small"><?= ucfirst($m['receiver_role']) ?></span>)</td>
                                    <td>
                                        <a href="?tab=sent&view=<?= $m['message_id'] ?>" class="text-dark text-decoration-none fw-bold">
                                            <?= htmlspecialchars($m['subject']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary inquiry-badge"><?= $m['inquiry_type'] ?></span>
                                    </td>
                                    <td>
                                        <?php if ($m['is_read']): ?>
                                            <span class="badge bg-success bg-opacity-10 text-success"><i class="fa-solid fa-check-double me-1"></i> Read on <?= date('M d, h:i A', strtotime($m['read_at'])) ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning bg-opacity-10 text-dark"><i class="fa-solid fa-clock me-1"></i> Unread</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?= date('M d, Y', strtotime($m['created_at'])) ?></td>
                                    <td>
                                        <a href="?tab=sent&view=<?= $m['message_id'] ?>" class="btn btn-outline-secondary btn-sm">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Compose Official Message Modal -->
<div class="modal fade" id="composeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="parent_message_id" id="replyParentId" value="">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-envelope-circle-check me-2 text-info"></i><?= __('compose_message') ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Select Recipient <span class="text-danger">*</span></label>
                            <select name="receiver_id" id="composeRecipient" class="form-select fw-bold" required>
                                <option value="">-- Choose Recipient --</option>
                                <?php foreach ($recipients as $r): ?>
                                    <option value="<?= $r['user_id'] ?>">
                                        <?= htmlspecialchars($r['full_name']) ?> (<?= ucfirst($r['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= __('inquiry_type') ?></label>
                            <select name="inquiry_type" class="form-select fw-bold">
                                <option value="Academic">Academic Progress &amp; Grades</option>
                                <option value="Behavioral">Behavioral &amp; Discipline</option>
                                <option value="Attendance">Attendance &amp; Leave Request</option>
                                <option value="Fees">Tuition &amp; Fees</option>
                                <option value="General" selected>General Notice / Inquiry</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                            <input type="text" name="subject" id="composeSubject" class="form-control fw-bold" placeholder="e.g., Question regarding Homework / Grade performance" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Official Message Body <span class="text-danger">*</span></label>
                            <textarea name="message" id="composeMessage" class="form-control" rows="5" placeholder="Type your formal message here..." required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="fa-solid fa-paper-plane me-1"></i> Send Tracked Message</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function replyMessage(senderId, senderName, subject, parentId) {
    document.getElementById('composeRecipient').value = senderId;
    document.getElementById('composeSubject').value = subject;
    document.getElementById('replyParentId').value = parentId;
    var composeModal = new bootstrap.Modal(document.getElementById('composeModal'));
    composeModal.show();
}
</script>
</body>
</html>
