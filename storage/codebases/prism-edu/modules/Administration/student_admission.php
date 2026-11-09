<?php
/**
 * Administration Module - Student Admission
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in as admin or school_admin
Auth::requireRole(['admin', 'school_admin']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Student Admission Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Student Data
    $first_name  = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name   = trim($_POST['last_name']);
    $dob         = $_POST['dob'];
    $gender      = $_POST['gender'];
    $nationality = trim($_POST['nationality']);
    $religion    = trim($_POST['religion'] ?? '');
    $class_id    = intval($_POST['class_id']);
    
    // 2. Guardian / Father Data
    $g_relationship = trim($_POST['guardian_relationship']);
    $g_first_name   = trim($_POST['guardian_first_name']);
    $g_last_name    = trim($_POST['guardian_last_name']);
    $g_phone        = trim($_POST['guardian_phone']);
    $g_email        = trim($_POST['guardian_email']);
    $g_occupation   = trim($_POST['guardian_occupation']);
    $g_address      = trim($_POST['guardian_address']);
    $g_password     = $_POST['guardian_password'] ?? 'Parent123!';

    // 3. Mother Data
    $m_first_name = trim($_POST['mother_first_name'] ?? '');
    $m_last_name  = trim($_POST['mother_last_name'] ?? '');
    $m_address    = trim($_POST['mother_address'] ?? '');
    $m_phone      = trim($_POST['mother_phone'] ?? '');
    $m_email      = trim($_POST['mother_email'] ?? '');

    $student_full_name = trim("$first_name $middle_name $last_name");
    $guardian_full_name = trim("$g_first_name $g_last_name");

    if (!empty($first_name) && !empty($last_name) && !empty($class_id) && !empty($g_email)) {
        try {
            $pdo->beginTransaction();

            // A. Auto-generate Student Email
            $nameSlug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $first_name . $last_name));
            $uniqueSuffix = substr(uniqid(), -4);
            $student_email = "student." . $nameSlug . "." . $uniqueSuffix . "@school.edu";
            $student_password_plain = 'Student123!';

            // B. File Uploads Handling
            $uploadDir = BASE_PATH . '/uploads/admissions/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }

            if (!function_exists('uploadAdmissionFile')) {
                /**
                 * @param string $fileKey
                 * @param string $targetDir
                 * @param string $prefix
                 * @return string
                 */
                function uploadAdmissionFile(string $fileKey, string $targetDir, string $prefix): string {
                    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                        $fileExt = pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION);
                        $fileName = $prefix . '_' . time() . '_' . mt_rand(100,999) . '.' . $fileExt;
                        $targetPath = $targetDir . $fileName;
                        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $targetPath)) {
                            return 'uploads/admissions/' . $fileName;
                        }
                    }
                    return '';
                }
            }

            $birth_cert_path = uploadAdmissionFile('birth_certificate', $uploadDir, 'birth_cert');
            $student_photo_path = uploadAdmissionFile('student_photo', $uploadDir, 'student_photo');
            
            if (empty($birth_cert_path) || empty($student_photo_path)) {
                throw new Exception("Student Birth Certificate and Student Photo are mandatory.");
            }

            $prev_cert_path  = uploadAdmissionFile('previous_certificate', $uploadDir, 'prev_cert');
            $other_doc_path  = uploadAdmissionFile('other_document', $uploadDir, 'other_doc');

            // C. Create Student User Account
            $stUser = $pdo->prepare("INSERT INTO users (full_name, email, password, role, role_id, status) VALUES (?, ?, ?, 'student', 4, 'Active')");
            $stUser->execute([$student_full_name, $student_email, password_hash($student_password_plain, PASSWORD_DEFAULT)]);
            $student_user_id = $pdo->lastInsertId();

            // Guardian Account check
            $pCheck = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $pCheck->execute([$g_email]);
            $existing_parent = $pCheck->fetch();

            if ($existing_parent) {
                $parent_user_id = $existing_parent['user_id'];
            } else {
                $pUser = $pdo->prepare("INSERT INTO users (full_name, email, password, role, role_id, status) VALUES (?, ?, ?, 'parent', 5, 'Active')");
                $pUser->execute([$guardian_full_name, $g_email, password_hash($g_password, PASSWORD_DEFAULT)]);
                $parent_user_id = $pdo->lastInsertId();
            }

            // D. Insert into Students Table
            $studentStmt = $pdo->prepare("INSERT INTO students (
                user_id, parent_id, class_id, first_name, middle_name, last_name, dob, gender, nationality, religion, 
                guardian_relationship, guardian_first_name, guardian_last_name, guardian_phone, guardian_email, guardian_occupation, guardian_address,
                mother_first_name, mother_last_name, mother_address, mother_phone, mother_email,
                birth_certificate, student_photo, previous_certificate, other_document
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $studentStmt->execute([
                $student_user_id, $parent_user_id, $class_id, $first_name, $middle_name, $last_name, $dob, $gender, $nationality, $religion,
                $g_relationship, $g_first_name, $g_last_name, $g_phone, $g_email, $g_occupation, $g_address,
                $m_first_name, $m_last_name, $m_address, $m_phone, $m_email,
                $birth_cert_path, $student_photo_path, $prev_cert_path, $other_doc_path
            ]);

            $student_id = $pdo->lastInsertId();

            // Sync parent student map
            if ($parent_user_id) {
                $mapStmt = $pdo->prepare("INSERT INTO parent_student_map (parent_id, parent_user_id, student_id, relationship) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE relationship = VALUES(relationship)");
                $mapStmt->execute([$parent_user_id, $parent_user_id, $student_id, $g_relationship ?: 'Guardian']);
            }

            // Dispatch Inter-Module Event (Cross-talk)
            EventDispatcher::dispatch('student.admitted', [
                'student_id' => $student_id,
                'user_id'    => $student_user_id,
                'class_id'   => $class_id,
                'full_name'  => $student_full_name
            ]);

            $pdo->commit();
            
            $_SESSION['flash_msg'] = "Student admitted successfully! <br>"
                                   . "<strong>Student System ID:</strong> STU-" . str_pad($student_id, 4, '0', STR_PAD_LEFT) . "<br>"
                                   . "<hr class='my-2'>"
                                   . "<strong><i class='fa-solid fa-child'></i> Student Portal Credentials:</strong><br>"
                                   . "• Email / Login: <code>" . htmlspecialchars($student_email) . "</code><br>"
                                   . "• Default Password: <code>" . htmlspecialchars($student_password_plain) . "</code><br>"
                                   . "<hr class='my-2'>"
                                   . "<strong><i class='fa-solid fa-user-shield'></i> Parent / Guardian Credentials:</strong><br>"
                                   . "• Email / Login: <code>" . htmlspecialchars($g_email) . "</code><br>"
                                   . "• Password: <code>" . htmlspecialchars($g_password) . "</code>";
            $_SESSION['flash_msg_type'] = "success";
            
            header("Location: student_admission.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_msg'] = "Admission Error: " . htmlspecialchars($e->getMessage());
            $_SESSION['flash_msg_type'] = "danger";
        }
    } else {
        $_SESSION['flash_msg'] = "Please fill in all mandatory fields (Student Name, Class, and Guardian Email).";
        $_SESSION['flash_msg_type'] = "danger";
    }
}

$classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('student_admission') ?> - School Management System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); color: white; }
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
    </style>
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('student_admission'),
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="index.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sliders me-1 text-primary"></i> ' . __('admin_control_center') . '</a>'
    ]
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <div class="d-flex align-items-start">
                <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> fa-2x me-3"></i>
                <div>
                    <h5 class="alert-heading fw-bold mb-1"><?= $msg_type === 'success' ? 'Admission Notification' : 'Notice' ?></h5>
                    <div class="mb-0"><?= $msg ?></div>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="p-4 mb-4 hero-banner rounded-3 shadow-sm">
        <h2 class="fw-bold mb-1"><i class="fa-solid fa-user-graduate me-2"></i><?= __('student_admission') ?></h2>
        <p class="mb-0 opacity-75"><?= __('student_admission_sub') ?></p>
    </div>

    <div class="card card-custom p-4 bg-white">
        <form method="POST" action="" enctype="multipart/form-data">
            
            <!-- SECTION 1: Student Biographical Info -->
            <h5 class="fw-bold text-success mb-3"><i class="fa-solid fa-child me-2"></i>1. Student Biographical Information</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">First Name:</label>
                    <input type="text" name="first_name" class="form-control" placeholder="E.g., Michael" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Middle Name:</label>
                    <input type="text" name="middle_name" class="form-control" placeholder="E.g., David">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Last Name:</label>
                    <input type="text" name="last_name" class="form-control" placeholder="E.g., Doe" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Birth Date:</label>
                    <input type="date" name="dob" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Gender:</label>
                    <select name="gender" class="form-select fw-bold" required>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Nationality:</label>
                    <input type="text" name="nationality" class="form-control" placeholder="E.g., Sudanese" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Religion (Optional):</label>
                    <input type="text" name="religion" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Assign Class:</label>
                    <select name="class_id" class="form-select fw-bold" required>
                        <option value="">-- <?= __('select_class') ?> --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['class_id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr class="text-muted my-4">

            <!-- SECTION 2: Guardian / Father Information -->
            <h5 class="fw-bold text-primary mb-3"><i class="fa-solid fa-user-shield me-2"></i>2. Guardian / Father Information</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Relationship to Student:</label>
                    <select name="guardian_relationship" class="form-select fw-bold" required>
                        <option value="Father">Father</option>
                        <option value="Uncle">Uncle</option>
                        <option value="Grandfather">Grandfather</option>
                        <option value="Guardian">Legal Guardian</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">First Name:</label>
                    <input type="text" name="guardian_first_name" class="form-control" placeholder="E.g., Robert" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Last Name:</label>
                    <input type="text" name="guardian_last_name" class="form-control" placeholder="E.g., Doe" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Phone Number:</label>
                    <input type="text" name="guardian_phone" class="form-control" placeholder="+249..." required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Email (Login ID):</label>
                    <input type="email" name="guardian_email" class="form-control" placeholder="parent@gmail.com" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Occupation:</label>
                    <input type="text" name="guardian_occupation" class="form-control" placeholder="E.g., Engineer">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Address Line:</label>
                    <input type="text" name="guardian_address" class="form-control" placeholder="Street address, City">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Account Password:</label>
                    <input type="password" name="guardian_password" class="form-control" placeholder="Default: Parent123!">
                </div>
            </div>

            <hr class="text-muted my-4">

            <!-- SECTION 3: Mother Information -->
            <h5 class="fw-bold text-info mb-3"><i class="fa-solid fa-user-nurse me-2"></i>3. Student Mother Information</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Mother First Name:</label>
                    <input type="text" name="mother_first_name" class="form-control" placeholder="E.g., Sarah">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Mother Last Name:</label>
                    <input type="text" name="mother_last_name" class="form-control" placeholder="E.g., Doe">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Phone Number:</label>
                    <input type="text" name="mother_phone" class="form-control" placeholder="+249...">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Email Address:</label>
                    <input type="email" name="mother_email" class="form-control" placeholder="mother@gmail.com">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Address Line:</label>
                    <input type="text" name="mother_address" class="form-control" placeholder="If different from guardian">
                </div>
            </div>

            <hr class="text-muted my-4">

            <!-- SECTION 4: Uploaded Documents -->
            <h5 class="fw-bold text-warning text-dark mb-3"><i class="fa-solid fa-folder-open me-2"></i>4. Supporting Documents &amp; Certificates</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Student Birth Certificate <span class="text-danger">*</span>:</label>
                    <input type="file" name="birth_certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                    <div class="form-text small text-muted">Required (PDF, JPG, PNG)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Student Passport Photo <span class="text-danger">*</span>:</label>
                    <input type="file" name="student_photo" class="form-control" accept=".jpg,.jpeg,.png" required>
                    <div class="form-text small text-muted">Required for student ID / portal profile</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Previous Transcripts / Report Cards:</label>
                    <input type="file" name="previous_certificate" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text small text-muted">Optional</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Other Supporting Document:</label>
                    <input type="file" name="other_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                    <div class="form-text small text-muted">Optional (Medical records, transfer letter, etc.)</div>
                </div>
            </div>

            <button type="submit" class="btn btn-success fw-bold px-4 py-2 mt-2">
                <i class="fa-solid fa-user-check me-2"></i> Complete Admission &amp; Create Profiles
            </button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
