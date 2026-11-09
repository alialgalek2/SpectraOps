<?php
/**
 * modules/HR/add_employee.php
 * Form handling employee creation & conditional document uploads
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in as admin, HR, or finance staff
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'hr', 'finance'])) {
    if (!Auth::check()) {
        header("Location: ../../login.php");
        exit();
    }
}

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Employee Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_employee') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $category         = trim($_POST['category'] ?? 'Teacher');
    $employment_type  = trim($_POST['employment_type'] ?? 'Full-time');
    $phone            = trim($_POST['phone'] ?? '');
    $email            = trim($_POST['email'] ?? '');
    $address          = trim($_POST['address'] ?? '');
    $base_salary      = floatval($_POST['base_salary'] ?? 0.00);
    $grant_access     = isset($_POST['grant_access']) ? true : false;
    $portal_password  = trim($_POST['portal_password'] ?? 'Staff123!');
    $job_title        = trim($_POST['job_title'] ?? $category);
    $national_id      = trim($_POST['national_id'] ?? '');
    $payment_method   = trim($_POST['payment_method'] ?? 'Cash');

    if (!empty($full_name) && !empty($category)) {
        try {
            $pdo->beginTransaction();

            $linked_user_id = null;

            // 1. If system access is requested, create a portal user account
            if ($grant_access) {
                // Determine username from email or name
                if (!empty($email)) {
                    $portal_username = strtolower(explode('@', $email)[0]);
                } else {
                    $parts = explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9 ]/', '', $full_name)));
                    $portal_username = $parts[0] . '.' . ($parts[1] ?? rand(100, 999));
                }

                // Check username collision
                $chkUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
                $chkUser->execute([$portal_username]);
                if ($chkUser->fetch()) {
                    $portal_username .= rand(10, 99);
                }

                // Determine role and role_id based on category
                $target_role = 'staff';
                $role_id = 2;

                if ($category === 'Teacher') {
                    $target_role = 'teacher';
                    $role_id = 2;
                } elseif ($category === 'Administrative') {
                    $target_role = 'admin';
                    $role_id = 1;
                } elseif ($category === 'HR') {
                    $target_role = 'hr';
                    $role_id = 13;
                }

                $hashed_pass = password_hash($portal_password, PASSWORD_BCRYPT);

                $userIns = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, email, phone, role, role_id, status, must_change_password, is_deleted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 0, 0)
                ");
                $userIns->execute([$portal_username, $hashed_pass, $full_name, $email, $phone, $target_role, $role_id]);
                $linked_user_id = $pdo->lastInsertId();
            }

            // Generate unique employee code (e.g., EMP-304)
            $countTotal = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() + 101;
            $emp_code = "EMP-" . str_pad($countTotal, 3, '0', STR_PAD_LEFT);
            while ($pdo->query("SELECT employee_id FROM employees WHERE employee_code = '$emp_code'")->fetch()) {
                $countTotal++;
                $emp_code = "EMP-" . str_pad($countTotal, 3, '0', STR_PAD_LEFT);
            }

            // 2. Insert into Employees Master Table
            $empStmt = $pdo->prepare("
                INSERT INTO employees (
                    employee_code, user_id, full_name, national_id, category, job_title,
                    employment_type, phone, email, address, base_salary, payment_method, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())
            ");
            $empStmt->execute([
                $emp_code, $linked_user_id, $full_name, $national_id, $category, $job_title,
                $employment_type, $phone, $email, $address, $base_salary, $payment_method
            ]);
            $employee_id = $pdo->lastInsertId();

            // 3. Handle Document Uploads (CV, License, Insurance, IDs, Certificates)
            $uploadDir = __DIR__ . '/../../uploads/hr_documents/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            if (isset($_FILES['emp_document']) && $_FILES['emp_document']['error'] === UPLOAD_ERR_OK) {
                $doc_type = trim($_POST['document_type'] ?? 'General Document');
                $doc_title = trim($_POST['document_title'] ?? $doc_type);
                $fileExt = pathinfo($_FILES['emp_document']['name'], PATHINFO_EXTENSION);
                $fileName = 'emp_' . $employee_id . '_' . time() . '.' . $fileExt;
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($_FILES['emp_document']['tmp_name'], $targetPath)) {
                    $size_str = round($_FILES['emp_document']['size'] / 1024, 1) . ' KB';
                    $doc_rel_path = 'uploads/hr_documents/' . $fileName;
                    $doc_expiry = !empty($_POST['document_expiry']) ? $_POST['document_expiry'] : null;

                    $docStmt = $pdo->prepare("
                        INSERT INTO employee_documents (employee_id, document_title, document_type, file_path, file_size, expiry_date, upload_date, uploaded_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $docStmt->execute([$employee_id, $doc_title, $doc_type, $doc_rel_path, $size_str, $doc_expiry]);
                }
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = "Personnel record for <strong>" . htmlspecialchars($full_name) . "</strong> ($emp_code) created successfully! (Category: $category)";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: hr_dashboard.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_msg'] = "Error registering personnel: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = "danger";
        }
    } else {
        $_SESSION['flash_msg'] = "Full name and staff category are required fields.";
        $_SESSION['flash_msg_type'] = "danger";
    }
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; Personnel Management - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .portal-box {
            background: var(--accent);
            border: 2px solid var(--primary);
            border-radius: var(--radius-xl);
        }
    </style>
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'شؤون الموظفين والكوادر' : 'HR Personnel Sub-System',
    'back_url' => 'hr_dashboard.php',
    'extra_nav_items' => [
        '<a href="hr_dashboard.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-arrow-left me-1"></i> ' . (is_rtl() ? 'سجل الموظفين' : 'Staff Directory') . '</a>',
        '<a href="payroll_ledger.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> ' . __('staff_payroll') . '</a>'
    ]
]) ?>

<div class="container py-4" style="max-width: 950px;">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Welcome Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-user-plus text-emerald-300 me-1"></i> Personnel Onboarding
                    </span>
                </div>
                <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-users-gear me-2"></i>HR &amp; Staff Registration</h2>
                <p class="mb-0 text-white-50 small">Manage all school staff including teachers, administrators, drivers, security, and cleaning crew with document archiving and optional portal access.</p>
            </div>
            <a href="hr_dashboard.php" class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs">
                <i class="fa-solid fa-table-list me-1"></i> View Directory
            </a>
        </div>
    </div>

    <div class="card border-border rounded-xl p-4 p-md-5 bg-card shadow-sm">
        <h4 class="fw-bold font-heading mb-3 text-foreground border-bottom border-border pb-2">
            <i class="fa-solid fa-user-plus me-2 text-primary"></i>Register New Staff Member
        </h4>
        
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_employee">

            <h5 class="text-primary fw-bold fs-6 text-uppercase mb-3 mt-3">
                <i class="fa-solid fa-id-card me-1"></i> 1. General Personnel Details
            </h5>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Full Legal Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" placeholder="E.g., John Smith" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Staff Category <span class="text-danger">*</span></label>
                    <select name="category" id="staffCategory" class="form-select fw-bold" required onchange="adaptCategoryRules(this.value)">
                        <option value="Teacher">Teacher (Academic)</option>
                        <option value="Administrative">Administrative Officer</option>
                        <option value="Driver">Driver (Transportation)</option>
                        <option value="Security">Security Guard</option>
                        <option value="Cleaner">Cleaner / Custodian</option>
                        <option value="Support">General Support Staff</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Employment Type</label>
                    <select name="employment_type" class="form-select">
                        <option value="Full-time">Full-time</option>
                        <option value="Part-time">Part-time</option>
                        <option value="Contract">Contract</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">National ID / Passport No:</label>
                    <input type="text" name="national_id" class="form-control" placeholder="E.g., NID-84920194">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Job Title / Designation:</label>
                    <input type="text" name="job_title" id="jobTitleField" class="form-control" placeholder="E.g., School Bus Driver (Route 4)">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Payment Method:</label>
                    <select name="payment_method" class="form-select">
                        <option value="Cash">Cash at Vault</option>
                        <option value="Bank Transfer">Bank Wire Transfer</option>
                        <option value="Cheque">Cheque</option>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Phone Number:</label>
                    <input type="text" name="phone" class="form-control" placeholder="+249 912 ...">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Email Address:</label>
                    <input type="email" name="email" class="form-control" placeholder="staff@school.edu">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Base Monthly Salary ($):</label>
                    <input type="number" step="0.01" name="base_salary" class="form-control fw-bold" placeholder="850.00" value="850.00">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-bold small text-uppercase text-secondary">Residential Address:</label>
                <input type="text" name="address" class="form-control" placeholder="Street, City, Region">
            </div>

            <!-- Portal Access Section (Mainly for Teachers/Admin) -->
            <div id="portalAccessSection" class="portal-box p-4 mb-4">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="grant_access" id="grantAccessCheck" value="1" onchange="togglePasswordPrompt(this.checked)">
                    <label class="form-check-label fw-bold text-dark fs-6" for="grantAccessCheck">
                        <i class="fa-solid fa-key text-primary me-1"></i> Grant System Portal Login Access (Create user account credentials)
                    </label>
                </div>
                <p class="text-muted small mb-0">
                    Drivers, security, and cleaners typically do <strong>not</strong> need software portal accounts. Unchecked profiles exist strictly in HR for payroll and document archiving.
                </p>

                <div id="passwordFieldBox" class="mt-3 pt-3 border-top" style="display: none;">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase">Initial Password</label>
                            <input type="password" name="portal_password" class="form-control" value="Staff123!" placeholder="Minimum 6 characters">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <small class="text-muted">Username will be automatically assigned from email or name format.</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compliance & Verification Documents -->
            <h5 class="text-success fw-bold fs-6 text-uppercase mb-2">
                <i class="fa-solid fa-folder-open me-1"></i> 2. Compliance &amp; Verification Documents
            </h5>
            <div id="docHintBox" class="alert alert-info py-2 small mb-3">
                <i class="fa-solid fa-circle-info me-1"></i> Recommended verification files will adjust based on selected staff category.
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Document Title:</label>
                    <input type="text" name="document_title" id="docTitleInput" class="form-control" placeholder="e.g. Commercial Heavy Driver License">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Document Type:</label>
                    <select name="document_type" id="docTypeSelect" class="form-select">
                        <option value="National ID / Passport">National ID / Passport / Residence Card</option>
                        <option value="CV / Resume">CV / Resume</option>
                        <option value="Academic Certificate">Academic Certificate / Degree</option>
                        <option value="Driver License">Driver's License</option>
                        <option value="Vehicle Insurance">Vehicle Insurance Papers</option>
                        <option value="Background / Medical Check">Background Check / Medical Clearance</option>
                        <option value="Employment Contract">Employment Contract</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Expiry Date (Optional):</label>
                    <input type="date" name="document_expiry" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Upload Document File:</label>
                    <input type="file" name="emp_document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
                    <div class="form-text small text-muted">Upload scanned copies or photos (PDF, JPG, PNG, DOCX, Max 10MB).</div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-3 border-top">
                <a href="hr_dashboard.php" class="btn btn-secondary px-4 fw-bold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Cancel &amp; Back
                </a>
                <button type="submit" class="btn btn-primary fw-bold px-5 py-2 shadow">
                    <i class="fa-solid fa-user-check me-2"></i> Save Staff Profile &amp; Archive
                </button>
            </div>
        </form>
    </div>

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePasswordPrompt(checked) {
    const box = document.getElementById('passwordFieldBox');
    if (box) box.style.display = checked ? 'block' : 'none';
}

function adaptCategoryRules(category) {
    const checkbox = document.getElementById('grantAccessCheck');
    const jobTitle = document.getElementById('jobTitleField');
    const docHint = document.getElementById('docHintBox');
    const docType = document.getElementById('docTypeSelect');
    const docTitle = document.getElementById('docTitleInput');

    if (category === 'Teacher') {
        checkbox.checked = true;
        togglePasswordPrompt(true);
        if (!jobTitle.value) jobTitle.value = 'Senior Subject Teacher';
        docHint.innerHTML = '<i class="fa-solid fa-chalkboard-user text-primary me-1"></i> <strong>Teacher Requirements:</strong> Academic Degree, Teaching License, CV.';
        docType.value = 'Academic Certificate';
        if (!docTitle.value) docTitle.value = 'Bachelor Degree Certificate';
    } else if (category === 'Driver') {
        checkbox.checked = false;
        togglePasswordPrompt(false);
        if (!jobTitle.value) jobTitle.value = 'School Bus Driver';
        docHint.innerHTML = '<i class="fa-solid fa-bus text-warning me-1"></i> <strong>Driver Requirements:</strong> Heavy Commercial Driver License, Vehicle Insurance, National ID.';
        docType.value = 'Driver License';
        if (!docTitle.value) docTitle.value = 'Commercial Driver License';
    } else if (category === 'Cleaner') {
        checkbox.checked = false;
        togglePasswordPrompt(false);
        if (!jobTitle.value) jobTitle.value = 'Campus Hygiene Specialist';
        docHint.innerHTML = '<i class="fa-solid fa-broom text-success me-1"></i> <strong>Cleaner Requirements:</strong> National ID / Residence Card, Medical Clearance & Health Card.';
        docType.value = 'Background / Medical Check';
        if (!docTitle.value) docTitle.value = 'Health & Medical Clearance';
    } else if (category === 'Security') {
        checkbox.checked = false;
        togglePasswordPrompt(false);
        if (!jobTitle.value) jobTitle.value = 'Campus Patrol & Security Guard';
        docHint.innerHTML = '<i class="fa-solid fa-shield text-info me-1"></i> <strong>Security Requirements:</strong> National ID, Police Background Check, Safety Training Certificate.';
        docType.value = 'Background / Medical Check';
        if (!docTitle.value) docTitle.value = 'Police Clearance Certificate';
    } else {
        checkbox.checked = (category === 'Administrative');
        togglePasswordPrompt(checkbox.checked);
        if (!jobTitle.value) jobTitle.value = 'Staff Member';
        docHint.innerHTML = '<i class="fa-solid fa-file-contract text-secondary me-1"></i> <strong>Staff Requirements:</strong> Employment Contract, National ID.';
        docType.value = 'National ID / Passport';
        if (!docTitle.value) docTitle.value = 'National ID Copy';
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('staffCategory');
    if (sel) adaptCategoryRules(sel.value);
});
</script>
</body>
</html>
