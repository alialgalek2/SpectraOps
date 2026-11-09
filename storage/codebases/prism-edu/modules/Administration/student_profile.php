<?php
/**
 * Administration Module - Student 360 Profile
 * Centralized records: Biographical, Academic History, Medical Notes & Parent Linkage
 */

require_once __DIR__ . '/../../core/init.php';

// Access control: Admin, Teacher, or the Student/Parent themselves
Auth::requireLogin();

$user_role = Auth::role();
$current_user_id = Auth::id();

$student_id = intval($_GET['id'] ?? 0);

// If student is logged in, restrict to their own record
if ($user_role === 'student') {
    $st = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
    $st->execute([$current_user_id]);
    $student_id = intval($st->fetchColumn() ?: 0);
}

if ($student_id <= 0) {
    // Pick the first student if not specified and admin
    $student_id = intval($pdo->query("SELECT student_id FROM students ORDER BY student_id ASC LIMIT 1")->fetchColumn() ?: 0);
}

if ($student_id <= 0) {
    die("<div class='container my-5 alert alert-warning'>No student records found in database. Please admit a student first.</div>");
}

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Medical Notes / Profile Updates (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_medical_profile' && $user_role === 'admin') {
    $blood_group             = trim($_POST['blood_group'] ?? '');
    $allergies               = trim($_POST['allergies'] ?? '');
    $medical_conditions      = trim($_POST['medical_conditions'] ?? '');
    $medications             = trim($_POST['medications'] ?? '');
    $emergency_contact_name  = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $previous_school         = trim($_POST['previous_school'] ?? '');
    $immunization_status     = trim($_POST['immunization_status'] ?? '');
    $rfid_card_id            = trim($_POST['rfid_card_id'] ?? '');

    try {
        $stmt = $pdo->prepare("
            UPDATE students SET 
                blood_group = ?, 
                allergies = ?, 
                medical_conditions = ?, 
                medications = ?, 
                emergency_contact_name = ?, 
                emergency_contact_phone = ?, 
                previous_school = ?, 
                immunization_status = ?,
                rfid_card_id = ?
            WHERE student_id = ?
        ");
        $stmt->execute([
            $blood_group, $allergies, $medical_conditions, $medications,
            $emergency_contact_name, $emergency_contact_phone,
            $previous_school, $immunization_status, $rfid_card_id,
            $student_id
        ]);
        $_SESSION['flash_msg'] = "Student medical and academic profile updated successfully!";
        $_SESSION['flash_msg_type'] = "success";
        header("Location: student_profile.php?id=" . $student_id);
        exit();
    } catch (\PDOException $e) {
        $msg = "Error updating profile: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Student Comprehensive 360 Record
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.email as student_email, u.username, c.class_name,
           pu.full_name as parent_name, pu.email as parent_email
    FROM students s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN users pu ON (s.guardian_email IS NOT NULL AND s.guardian_email != '' AND s.guardian_email = pu.email)
    WHERE s.student_id = ?
    LIMIT 1
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

if (!$student) {
    die("<div class='container my-5 alert alert-danger'>Student record not found.</div>");
}

// Fetch Academic Grades History
$gradesStmt = $pdo->prepare("
    SELECT g.*, 
           COALESCE(g.ca_score, 0) as ca_score_val,
           COALESCE(g.exam_score, 0) as exam_score_val,
           COALESCE(g.total_score, COALESCE(g.ca_score,0) + COALESCE(g.exam_score,0), 0) as total_score_val,
           sub.subject_name, sub.subject_code 
    FROM grades g
    JOIN subjects sub ON g.subject_id = sub.subject_id
    WHERE g.student_id = ?
    ORDER BY g.created_at DESC
");
$gradesStmt->execute([$student_id]);
$grades = $gradesStmt->fetchAll();

// Fetch Attendance Statistics
$attStmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days,
        SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) as excused_days
    FROM student_attendance
    WHERE student_id = ?
");
$attStmt->execute([$student_id]);
$attStats = $attStmt->fetch();

$totalDays = $attStats['total_days'] ?: 0;
$attRate = $totalDays > 0 ? round(($attStats['present_days'] / $totalDays) * 100, 1) : 100;

// Fetch Invoices / Fee Summary
$invStmt = $pdo->prepare("
    SELECT * FROM fee_invoices 
    WHERE student_id = ? 
    ORDER BY created_at DESC
");
$invStmt->execute([$student_id]);
$invoices = $invStmt->fetchAll();

// All students list for quick selector
$allStudents = $pdo->query("
    SELECT s.student_id, u.full_name, c.class_name 
    FROM students s 
    JOIN users u ON s.user_id = u.user_id 
    LEFT JOIN classes c ON s.class_id = c.class_id
    ORDER BY u.full_name ASC
")->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($student['full_name']) ?> - <?= __('student_profile_360') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .avatar-box {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.8);
            object-fit: cover;
            background: white;
        }
        .nav-tabs .nav-link {
            font-weight: 600;
            color: var(--muted-foreground);
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: var(--card);
            border-bottom-color: var(--card);
        }
        .info-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted-foreground);
            font-weight: 700;
        }
        .info-value {
            font-size: 0.95rem;
            color: var(--foreground);
            font-weight: 600;
        }
        .medical-badge {
            font-size: 0.85rem;
            padding: 6px 14px;
            border-radius: var(--radius-full);
        }
    </style>
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => __('student_profile_360'),
    'back_url' => 'index.php'
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Student Switcher (Admin/Teacher) -->
    <?php if ($user_role === 'admin' || $user_role === 'teacher'): ?>
        <div class="card border-0 shadow-sm rounded-4 p-3 mb-4 bg-white">
            <div class="row align-items-center g-2">
                <div class="col-md-3">
                    <label class="info-label mb-0"><i class="fa-solid fa-users me-1 text-primary"></i> Select Student:</label>
                </div>
                <div class="col-md-9">
                    <select class="form-select fw-bold" onchange="if(this.value) location.href='student_profile.php?id=' + this.value;">
                        <?php foreach ($allStudents as $st): ?>
                            <option value="<?= $st['student_id'] ?>" <?= $st['student_id'] == $student_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['full_name']) ?> (<?= htmlspecialchars($st['class_name'] ?? 'Unassigned') ?>) - ID #<?= $st['student_id'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Profile Hero Card -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex align-items-center flex-wrap gap-4">
            <div>
                <?php if (!empty($student['student_photo']) && file_exists(BASE_PATH . '/' . $student['student_photo'])): ?>
                    <img src="../../<?= htmlspecialchars($student['student_photo']) ?>" alt="Photo" class="avatar-box shadow">
                <?php else: ?>
                    <div class="avatar-box d-flex align-items-center justify-content-center shadow text-primary fs-1 bg-white">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                <?php endif; ?>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                    <h2 class="fw-bold mb-0 text-white font-heading"><?= htmlspecialchars($student['full_name']) ?></h2>
                    <span class="badge bg-white bg-opacity-20 text-white rounded-full font-medium px-3 py-1"><?= htmlspecialchars($student['enrollment_status'] ?? 'Active') ?></span>
                    <?php if (!empty($student['blood_group'])): ?>
                        <span class="badge bg-danger px-3 py-1 rounded-full"><i class="fa-solid fa-heart-pulse me-1"></i> Blood: <?= htmlspecialchars($student['blood_group']) ?></span>
                    <?php endif; ?>
                </div>
                <p class="text-white-50 mb-2 small">
                    <i class="fa-solid fa-id-badge me-1"></i> Admission ID: <strong><?= htmlspecialchars($student['admission_no'] ?: 'STU-' . str_pad($student['student_id'], 4, '0', STR_PAD_LEFT)) ?></strong> | 
                    <i class="fa-solid fa-chalkboard me-1 ms-2"></i> Class: <strong><?= htmlspecialchars($student['class_name'] ?? 'Grade 1') ?></strong> |
                    <i class="fa-solid fa-envelope me-1 ms-2"></i> <?= htmlspecialchars($student['student_email'] ?? 'student@school.edu') ?>
                </p>
                <div class="d-flex gap-2 flex-wrap mt-3">
                    <a href="../Grading/transcript.php?student_id=<?= $student_id ?>" class="btn btn-warning btn-sm fw-bold rounded-lg shadow-xs">
                        <i class="fa-solid fa-scroll me-1"></i> <?= __('academic_transcript') ?>
                    </a>
                    <a href="../LMS/messages.php?student_id=<?= $student_id ?>" class="btn btn-outline-light btn-sm fw-bold rounded-lg">
                        <i class="fa-solid fa-comments me-1"></i> <?= __('parent_teacher_messages') ?>
                    </a>
                    <?php if ($user_role === 'admin'): ?>
                        <button class="btn btn-light btn-sm fw-bold text-primary rounded-lg shadow-xs" data-bs-toggle="modal" data-bs-target="#editMedicalModal">
                            <i class="fa-solid fa-notes-medical me-1 text-danger"></i> Edit Medical / Academic Notes
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 360 Tab Navigation -->
    <ul class="nav nav-tabs mb-4 px-2" id="profileTab" role="tablist">
        <li class="nav-item">
            <button class="nav-link active" id="bio-tab" data-bs-toggle="tab" data-bs-target="#bio" type="button">
                <i class="fa-solid fa-user me-1 text-primary"></i> 1. Biographical &amp; Identity
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="medical-tab" data-bs-toggle="tab" data-bs-target="#medical" type="button">
                <i class="fa-solid fa-heart-pulse me-1 text-danger"></i> 2. Medical Notes &amp; Health
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="academic-tab" data-bs-toggle="tab" data-bs-target="#academic" type="button">
                <i class="fa-solid fa-book-open me-1 text-success"></i> 3. Academic History &amp; Grades
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="attendance-tab" data-bs-toggle="tab" data-bs-target="#attendance" type="button">
                <i class="fa-solid fa-calendar-check me-1 text-info"></i> 4. Attendance (<?= $attRate ?>%)
            </button>
        </li>
        <li class="nav-item">
            <button class="nav-link" id="parent-tab" data-bs-toggle="tab" data-bs-target="#parent" type="button">
                <i class="fa-solid fa-people-roof me-1 text-warning"></i> 5. Family &amp; Guardians
            </button>
        </li>
    </ul>

    <div class="tab-content" id="profileTabContent">
        <!-- 1. BIOGRAPHICAL -->
        <div class="tab-pane fade show active" id="bio">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white">
                <h5 class="fw-bold mb-4 text-primary"><i class="fa-solid fa-id-card me-2"></i>Biographical Information</h5>
                <div class="row g-4">
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label"><?= __('full_name') ?></div>
                        <div class="info-value"><?= htmlspecialchars($student['full_name']) ?></div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label">Date of Birth</div>
                        <div class="info-value"><?= htmlspecialchars($student['dob'] ?? '—') ?></div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label">Gender</div>
                        <div class="info-value"><?= htmlspecialchars($student['gender'] ?? '—') ?></div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label">Nationality</div>
                        <div class="info-value"><?= htmlspecialchars($student['nationality'] ?? 'Sudanese') ?></div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label">RFID Badge / Card UID</div>
                        <div class="info-value"><code><?= htmlspecialchars($student['rfid_card_id'] ?: 'RFID-' . str_pad($student_id, 6, '0', STR_PAD_LEFT)) ?></code></div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="info-label">Current Enrolled Class</div>
                        <div class="info-value"><span class="badge bg-primary fs-6"><?= htmlspecialchars($student['class_name'] ?? 'Class') ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. MEDICAL NOTES -->
        <div class="tab-pane fade" id="medical">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-danger"><i class="fa-solid fa-notes-medical me-2"></i>Medical Records &amp; Allergies</h5>
                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger px-3 py-2">
                        <i class="fa-solid fa-shield-halved me-1"></i> Confidential Health Record
                    </span>
                </div>
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-danger border-4">
                            <div class="info-label"><?= __('blood_group') ?></div>
                            <div class="fs-4 fw-bold text-danger"><?= htmlspecialchars($student['blood_group'] ?: 'O+ (Default)') ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-warning border-4">
                            <div class="info-label"><?= __('immunization_status') ?></div>
                            <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($student['immunization_status'] ?: 'Up to date') ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 bg-light rounded-3 border-start border-info border-4">
                            <div class="info-label"><?= __('emergency_contact') ?></div>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($student['emergency_contact_name'] ?: ($student['guardian_first_name'] . ' ' . $student['guardian_last_name'])) ?></div>
                            <small class="text-muted"><i class="fa-solid fa-phone me-1"></i> <?= htmlspecialchars($student['emergency_contact_phone'] ?: $student['guardian_phone']) ?></small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 border-danger">
                            <div class="info-label text-danger mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= __('allergies') ?></div>
                            <p class="mb-0 text-dark"><?= nl2br(htmlspecialchars($student['allergies'] ?: 'No known allergies reported.')) ?></p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 border-secondary">
                            <div class="info-label text-dark mb-2"><i class="fa-solid fa-stethoscope me-1"></i> <?= __('medical_conditions') ?> &amp; <?= __('medications') ?></div>
                            <p class="mb-1 text-dark"><strong>Conditions:</strong> <?= nl2br(htmlspecialchars($student['medical_conditions'] ?: 'None reported.')) ?></p>
                            <p class="mb-0 text-muted"><strong>Prescriptions/Medications:</strong> <?= nl2br(htmlspecialchars($student['medications'] ?: 'None.')) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. ACADEMIC HISTORY -->
        <div class="tab-pane fade" id="academic">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-success"><i class="fa-solid fa-graduation-cap me-2"></i>Academic History &amp; Gradebook</h5>
                    <a href="../Grading/transcript.php?student_id=<?= $student_id ?>" class="btn btn-outline-success btn-sm fw-bold">
                        <i class="fa-solid fa-print me-1"></i> Print Official Transcript
                    </a>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="info-label"><?= __('previous_school') ?></div>
                            <div class="info-value"><?= htmlspecialchars($student['previous_school'] ?: 'Direct Entry / Foundation Kindergarten') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 bg-light rounded-3">
                            <div class="info-label">Prior Academic Record / Notes</div>
                            <div class="info-value"><?= htmlspecialchars($student['previous_academic_record'] ?: 'Standard progression without transfer impediments.') ?></div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-table me-2"></i>Recorded Subject Marks</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Subject</th>
                                <th>Code</th>
                                <th>Coursework (40)</th>
                                <th>Final Exam (60)</th>
                                <th>Total (100)</th>
                                <th>Grade</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($grades)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">No examination scores recorded yet for this student.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($grades as $g): ?>
                                    <?php $tot = floatval($g['total_score_val'] ?? 0); ?>
                                    <tr>
                                        <td class="fw-bold"><?= htmlspecialchars($g['subject_name']) ?></td>
                                        <td><code><?= htmlspecialchars($g['subject_code']) ?></code></td>
                                        <td><?= $g['ca_score_val'] ?? 0 ?></td>
                                        <td><?= $g['exam_score_val'] ?? 0 ?></td>
                                        <td class="fw-bold text-primary"><?= $tot ?> / 100</td>
                                        <td>
                                            <span class="badge <?= $tot >= 75 ? 'bg-success' : ($tot >= 50 ? 'bg-info' : 'bg-danger') ?>">
                                                <?= !empty($g['grade_letter']) && $g['grade_letter'] !== 'Pending' ? $g['grade_letter'] : ($tot >= 85 ? 'A' : ($tot >= 75 ? 'B' : ($tot >= 60 ? 'C' : ($tot >= 50 ? 'D' : 'F')))) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 4. ATTENDANCE TRACKING -->
        <div class="tab-pane fade" id="attendance">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0 text-info"><i class="fa-solid fa-clock-rotate-left me-2"></i>Attendance Statistics</h5>
                    <a href="../LMS/attendance.php?class_id=<?= $student['class_id'] ?>" class="btn btn-outline-info btn-sm fw-bold">
                        <i class="fa-solid fa-clipboard-user me-1"></i> Class Roll-Call
                    </a>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-3 col-6">
                        <div class="p-3 bg-light rounded-3 text-center border-bottom border-primary border-4">
                            <div class="info-label">Overall Rate</div>
                            <h3 class="fw-bold text-primary mb-0"><?= $attRate ?>%</h3>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="p-3 bg-light rounded-3 text-center border-bottom border-success border-4">
                            <div class="info-label"><?= __('present') ?></div>
                            <h3 class="fw-bold text-success mb-0"><?= $attStats['present_days'] ?: 0 ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="p-3 bg-light rounded-3 text-center border-bottom border-danger border-4">
                            <div class="info-label"><?= __('absent') ?></div>
                            <h3 class="fw-bold text-danger mb-0"><?= $attStats['absent_days'] ?: 0 ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="p-3 bg-light rounded-3 text-center border-bottom border-warning border-4">
                            <div class="info-label"><?= __('late') ?> / <?= __('excused') ?></div>
                            <h3 class="fw-bold text-warning mb-0"><?= ($attStats['late_days'] + $attStats['excused_days']) ?: 0 ?></h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 5. FAMILY & GUARDIANS -->
        <div class="tab-pane fade" id="parent">
            <div class="card border-0 shadow-sm rounded-4 p-4 bg-white mb-4">
                <h5 class="fw-bold mb-4 text-warning text-dark"><i class="fa-solid fa-people-roof me-2"></i>Guardian &amp; Parental Contact</h5>
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="card p-3 border-0 bg-light rounded-3 h-100">
                            <h6 class="fw-bold text-primary mb-3"><i class="fa-solid fa-user-shield me-2"></i>Primary Guardian (<?= htmlspecialchars($student['guardian_relationship'] ?: 'Father') ?>)</h6>
                            <div class="info-label">Name</div>
                            <div class="info-value mb-2"><?= htmlspecialchars($student['guardian_first_name'] . ' ' . $student['guardian_last_name']) ?></div>
                            
                            <div class="info-label">Phone Number</div>
                            <div class="info-value mb-2"><i class="fa-solid fa-phone me-1 text-success"></i> <?= htmlspecialchars($student['guardian_phone'] ?: '+249 912 000 000') ?></div>
                            
                            <div class="info-label">Email / Login ID</div>
                            <div class="info-value mb-2"><i class="fa-solid fa-envelope me-1 text-primary"></i> <?= htmlspecialchars($student['guardian_email'] ?: 'parent@school.edu') ?></div>

                            <div class="info-label">Address</div>
                            <div class="info-value"><?= htmlspecialchars($student['guardian_address'] ?: 'Khartoum, Sudan') ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 border-0 bg-light rounded-3 h-100">
                            <h6 class="fw-bold text-info mb-3"><i class="fa-solid fa-user-nurse me-2"></i>Mother Information</h6>
                            <div class="info-label">Mother Name</div>
                            <div class="info-value mb-2"><?= htmlspecialchars(($student['mother_first_name'] ?? '') . ' ' . ($student['mother_last_name'] ?? '')) ?: '—' ?></div>
                            
                            <div class="info-label">Mother Contact</div>
                            <div class="info-value mb-2"><i class="fa-solid fa-phone me-1 text-success"></i> <?= htmlspecialchars($student['mother_phone'] ?? '—') ?></div>

                            <div class="info-label">Mother Email</div>
                            <div class="info-value"><?= htmlspecialchars($student['mother_email'] ?? '—') ?></div>
                        </div>
                    </div>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<!-- Edit Medical Notes Modal -->
<div class="modal fade" id="editMedicalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_medical_profile">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-notes-medical me-2 text-danger"></i>Update Medical &amp; Academic Background</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold"><?= __('blood_group') ?></label>
                            <select name="blood_group" class="form-select fw-bold">
                                <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                    <option value="<?= $bg ?>" <?= ($student['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold"><?= __('immunization_status') ?></label>
                            <input type="text" name="immunization_status" class="form-control" value="<?= htmlspecialchars($student['immunization_status'] ?? 'Up to date') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">RFID Card ID</label>
                            <input type="text" name="rfid_card_id" class="form-control" value="<?= htmlspecialchars($student['rfid_card_id'] ?? '') ?>" placeholder="Scan or type RFID">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= __('emergency_contact') ?> Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" value="<?= htmlspecialchars($student['emergency_contact_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= __('emergency_contact') ?> Phone</label>
                            <input type="text" name="emergency_contact_phone" class="form-control" value="<?= htmlspecialchars($student['emergency_contact_phone'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold text-danger"><?= __('allergies') ?></label>
                            <textarea name="allergies" class="form-control" rows="2" placeholder="e.g. Peanuts, Penicillin, Asthma"><?= htmlspecialchars($student['allergies'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= __('medical_conditions') ?></label>
                            <textarea name="medical_conditions" class="form-control" rows="2"><?= htmlspecialchars($student['medical_conditions'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold"><?= __('medications') ?></label>
                            <textarea name="medications" class="form-control" rows="2"><?= htmlspecialchars($student['medications'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold"><?= __('previous_school') ?></label>
                            <input type="text" name="previous_school" class="form-control" value="<?= htmlspecialchars($student['previous_school'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary fw-bold"><?= __('save_changes') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
