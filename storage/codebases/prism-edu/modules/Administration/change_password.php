<?php
require_once __DIR__ . '/../../core/init.php';

// Security guard: Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$success = "";
$error = "";
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');

// Fetch user list if the current user is an admin
$all_users = [];
if ($is_admin) {
    try {
        $stmt = $pdo->query("SELECT user_id, full_name, username, role FROM users ORDER BY full_name ASC");
        $all_users = $stmt->fetchAll();
    } catch (\PDOException $e) {
        $error = "Failed to load user list: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_user_id   = $_POST['target_user_id'] ?? $_SESSION['user_id'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        try {
            // If admin is changing someone else's password, current password is NOT required
            if ($is_admin && $target_user_id != $_SESSION['user_id']) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $updateStmt->execute([$hashed_password, $target_user_id]);

                // Fetch target user name for the success message
                $nameStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ? LIMIT 1");
                $nameStmt->execute([$target_user_id]);
                $target_info = $nameStmt->fetch();

                $success = "Password successfully reset for " . ($target_info['full_name'] ?? 'selected user') . "!";
            } else {
                // Changing own password (requires current password verification)
                if (empty($current_password)) {
                    $error = "Please enter your current password.";
                } else {
                    $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($current_password, $user['password'])) {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                        $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                        $updateStmt->execute([$hashed_password, $_SESSION['user_id']]);

                        $success = "Your password has been successfully updated!";
                    } else {
                        $error = "Incorrect current password.";
                    }
                }
            }
        } catch (\PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}

// Determine correct back button link based on role
$dashboard_page = '../../dashboard.php';
if (isset($_SESSION['role'])) {
    switch (strtolower($_SESSION['role'])) {
        case 'teacher':   $dashboard_page = '../LMS/teacher_dashboard.php'; break;
        case 'student':   $dashboard_page = '../LMS/student_portal.php';    break;
        case 'parent':    $dashboard_page = '../LMS/parent_portal.php';     break;
        default:          $dashboard_page = '../../dashboard.php';          break;
    }
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('change_password') ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center py-5" style="min-height: 100vh;">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-sm-5">
                        <div class="text-center mb-4">
                            <i class="fa-solid fa-key text-primary fs-1 mb-2"></i>
                            <h4 class="fw-bold"><?= $is_admin ? 'Password Management' : 'Change Password' ?></h4>
                            <p class="text-muted small"><?= $is_admin ? 'Update your own password or reset a user account password' : 'Secure your account with a new password' ?></p>
                        </div>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="passwordForm">
                            <?php if ($is_admin): ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Select User Account</label>
                                    <select name="target_user_id" id="target_user_id" class="form-select">
                                        <option value="<?= $_SESSION['user_id'] ?>">-- Change My Own Password --</option>
                                        <?php foreach ($all_users as $u): ?>
                                            <option value="<?= $u['user_id'] ?>">
                                                <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['username']) ?> - <?= ucfirst($u['role']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3" id="currentPasswordSection">
                                <label class="form-label small fw-bold">Current Password <span class="text-muted fw-normal">(Required only for your own account)</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" name="current_password" class="form-control" placeholder="Enter current password">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-key"></i></span>
                                    <input type="password" name="new_password" class="form-control" placeholder="Enter new password (min 6 chars)" required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold">Confirm New Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-key"></i></span>
                                    <input type="password" name="confirm_password" class="form-control" placeholder="Confirm new password" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm mb-3">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Update Password
                            </button>

                            <a href="<?= $dashboard_page ?>" class="btn btn-outline-secondary w-100 btn-sm">
                                <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                            </a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($is_admin): ?>
    <script>
        // Optional UI enhancement: hide/show current password field if selecting another user
        const targetSelect = document.getElementById('target_user_id');
        const currentPassSection = document.getElementById('currentPasswordSection');
        const currentPassInput = document.querySelector('input[name="current_password"]');

        function toggleCurrentPassword() {
            const currentUserId = "<?= $_SESSION['user_id'] ?>";
            if (targetSelect.value !== currentUserId) {
                currentPassSection.style.display = 'none';
                currentPassInput.removeAttribute('required');
                currentPassInput.value = '';
            } else {
                currentPassSection.style.display = 'block';
            }
        }

        targetSelect.addEventListener('change', toggleCurrentPassword);
        toggleCurrentPassword(); // Run on load
    </script>
    <?php endif; ?>
</body>
</html>