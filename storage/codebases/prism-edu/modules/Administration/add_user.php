<?php
require_once __DIR__ . '/../../core/init.php';
// Security guard: Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Fetch existing students for the parent linking dropdown
$students_list = [];
try {
    $stmt = $pdo->query("SELECT user_id, full_name, username FROM users WHERE role = 'student' ORDER BY full_name ASC");
    $students_list = $stmt->fetchAll();
} catch (\PDOException $e) {
    // Handle error quietly
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username'] ?? '');
    $password  = $_POST['password'] ?? '';
    $role      = $_POST['role'] ?? '';

    // Restrict to teacher or admin only
    if (!in_array($role, ['teacher', 'admin'])) {
        $_SESSION['flash_msg'] = "Student and parent accounts must be created via the Student Admission portal.";
        $_SESSION['flash_msg_type'] = "danger";
    } elseif (empty($full_name) || empty($username) || empty($password)) {
        $_SESSION['flash_msg'] = "Please fill in all required fields.";
        $_SESSION['flash_msg_type'] = "danger";
    } elseif (strlen($password) < 6) {
        $_SESSION['flash_msg'] = "Password must be at least 6 characters long.";
        $_SESSION['flash_msg_type'] = "danger";
    } else {
        try {
            // Check if username already exists
            $check = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
            $check->execute([$username]);
            if ($check->fetch()) {
                $_SESSION['flash_msg'] = "Username already exists. Please choose a different one.";
                $_SESSION['flash_msg_type'] = "danger";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                // Insert new staff user (teacher or admin)
                $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password, role, status) VALUES (?, ?, ?, ?, 'Active')");
                $stmt->execute([$full_name, $username, $hashed_password, $role]);

                $_SESSION['flash_msg'] = "New staff user account created successfully!";
                $_SESSION['flash_msg_type'] = "success";
                
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } catch (\PDOException $e) {
            $_SESSION['flash_msg'] = "Error creating user: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create User Account - Algale Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light py-5">

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-7">
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-primary text-white py-3">
                        <h4 class="mb-0 fw-bold"><i class="fa-solid fa-user-plus me-2"></i>Create New User Account</h4>
                    </div>
                    <div class="card-body p-4 p-sm-5">

                        <?php if (!empty($_SESSION['flash_msg'])): ?>
                            <div class="alert alert-<?= $_SESSION['flash_msg_type'] ?? 'info' ?> alert-dismissible fade show" role="alert">
                                <?php if ($_SESSION['flash_msg_type'] == 'success'): ?>
                                    <i class="fa-solid fa-circle-check me-2"></i>
                                <?php elseif ($_SESSION['flash_msg_type'] == 'danger'): ?>
                                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-circle-info me-2"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($_SESSION['flash_msg']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                            <?php unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']); ?>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-uppercase text-secondary">System Role:</label>
                                <select name="role" id="roleSelect" class="form-select fw-bold" required>
                                    <option value="teacher">Teacher</option>
                                    <option value="admin">Administrator</option>
                                </select>
                                <div class="form-text text-muted small mt-1">Note: Student and parent accounts are created automatically via Student Admission.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Full Name</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-user"></i></span>
                                    <input type="text" name="full_name" id="fullNameInput" class="form-control" placeholder="e.g. Ahmed Ali" required>
                                </div>
                            </div>

                            <!-- Parent Linking Section (Dynamic) -->
                            <div class="mb-3" id="parentLinkSection" style="display: none;">
                                <label class="form-label small fw-bold text-warning-emphasis">Link to Student</label>
                                <select name="parent_id" class="form-select border-warning">
                                    <option value="">-- Select Child / Student --</option>
                                    <?php foreach ($students_list as $student): ?>
                                        <option value="<?= $student['user_id'] ?>">
                                            <?= htmlspecialchars($student['full_name']) ?> (Username: <?= htmlspecialchars($student['username']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">Connects this parent account directly to the student's profile.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Username <span class="text-muted fw-normal">(Auto-formatted with prefix)</span></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-at"></i></span>
                                    <input type="text" name="username" id="usernameInput" class="form-control" placeholder="Select a role and type name first" required>
                                </div>
                                <div class="form-text small">Format will automatically become <code id="formatPreview">role.firstname.lastname</code></div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label small fw-bold">Initial Password</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-key"></i></span>
                                    <input type="password" name="password" class="form-control" placeholder="Enter secure temporary password (min 6 chars)" required>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="admin_portal.php" class="btn btn-outline-secondary">
                                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard
                                </a>
                                <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm">
                                    <i class="fa-solid fa-user-check me-1"></i> Create Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const roleSelect = document.getElementById('roleSelect');
        const parentLinkSection = document.getElementById('parentLinkSection');
        const fullNameInput = document.getElementById('fullNameInput');
        const usernameInput = document.getElementById('usernameInput');
        const formatPreview = document.getElementById('formatPreview');

        function updateUsername() {
            const role = roleSelect.value;
            const name = fullNameInput.value.trim().toLowerCase();

            if (!role) {
                formatPreview.textContent = 'role.firstname.lastname';
                return;
            }

            // Clean up name: replace spaces and special chars with dots
            const formattedName = name.replace(/[^a-z0-9]/g, '.').replace(/\.+/g, '.').replace(/^\.|\.$/g, '');

            if (formattedName) {
                usernameInput.value = `${role}.${formattedName}`;
                formatPreview.textContent = `${role}.${formattedName}`;
            } else {
                usernameInput.value = '';
                formatPreview.textContent = `${role}.firstname.lastname`;
            }

            // Show/hide parent section
            if (role === 'parent') {
                parentLinkSection.style.display = 'block';
            } else {
                parentLinkSection.style.display = 'none';
            }
        }

        roleSelect.addEventListener('change', updateUsername);
        fullNameInput.addEventListener('input', updateUsername);
    </script>
</body>
</html>