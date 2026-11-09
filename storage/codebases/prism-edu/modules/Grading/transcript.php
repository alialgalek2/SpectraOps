<?php
/**
 * Grading Module - Official Academic Transcript & Cumulative GPA
 * Formatted for single-page A4 printing
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher', 'student', 'parent']);

$user_role = Auth::role();
$user_id   = Auth::id();

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;

// Lock student to their own profile if logged in as student
if ($user_role === 'student') {
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stu = $stmt->fetch();
    if ($stu) $student_id = $stu['student_id'];
}

// Fallback to first student if not specified
if ($student_id <= 0) {
    $first = $pdo->query("SELECT student_id FROM students WHERE is_deleted = 0 ORDER BY student_id ASC LIMIT 1")->fetch();
    if ($first) $student_id = intval($first['student_id']);
}

// Fetch Student Profile
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.email as student_email, c.class_name 
    FROM students s 
    LEFT JOIN users u ON s.user_id = u.user_id 
    LEFT JOIN classes c ON s.class_id = c.class_id 
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Fetch School Profile Settings
$settings_stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
$settings = [];
if ($settings_stmt) {
    while ($row = $settings_stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$school_name    = $settings['school_name'] ?? 'ALGALE INTERNATIONAL ACADEMY';
$school_address = $settings['school_address'] ?? 'P.O. Box 1024, Educational District';
$school_phone   = $settings['school_phone'] ?? '+249 912 345 678';
$school_logo    = $settings['school_logo'] ?? '';

// Fetch All Examination & Coursework Marks
$gradesStmt = $pdo->prepare("
    SELECT g.*, 
           COALESCE(g.ca_score, 0) as ca_score_val,
           COALESCE(g.exam_score, 0) as exam_score_val,
           COALESCE(g.total_score, COALESCE(g.ca_score,0) + COALESCE(g.exam_score,0), 0) as total_score_val,
           sub.subject_name, sub.subject_code 
    FROM grades g
    JOIN subjects sub ON g.subject_id = sub.subject_id
    WHERE g.student_id = ?
    ORDER BY g.created_at ASC
");
$gradesStmt->execute([$student_id]);
$grades = $gradesStmt->fetchAll();

// If grades table empty for student, fallback to exam_results
if (empty($grades)) {
    $erStmt = $pdo->prepare("
        SELECT er.*, 
               0 as ca_score_val,
               er.marks_obtained as exam_score_val,
               er.marks_obtained as total_score_val,
               er.grade as grade_letter,
               sub.subject_name, sub.subject_code
        FROM exam_results er
        JOIN subjects sub ON er.subject_id = sub.subject_id
        WHERE er.student_id = ?
        ORDER BY sub.subject_name ASC
    ");
    $erStmt->execute([$student_id]);
    $grades = $erStmt->fetchAll();
}

/**
 * GPA Calculation Helper
 * @param float|int $score
 * @return float
 */
function get_gpa_points(float|int $score): float {
    if ($score >= 90) return 4.0;
    if ($score >= 85) return 3.75;
    if ($score >= 80) return 3.5;
    if ($score >= 75) return 3.0;
    if ($score >= 70) return 2.5;
    if ($score >= 65) return 2.0;
    if ($score >= 60) return 1.5;
    if ($score >= 50) return 1.0;
    return 0.0;
}

/**
 * Letter Grade Calculation Helper
 * @param float|int $score
 * @return string
 */
function get_letter_grade(float|int $score): string {
    if ($score >= 90) return 'A+';
    if ($score >= 85) return 'A';
    if ($score >= 80) return 'B+';
    if ($score >= 75) return 'B';
    if ($score >= 70) return 'C+';
    if ($score >= 65) return 'C';
    if ($score >= 50) return 'D';
    return 'F';
}

$totalCourses = count($grades);
$totalPoints = 0;
$totalScore = 0;
$totalCredits = 0;

foreach ($grades as $g) {
    $tot = floatval($g['total_score_val'] ?? 0);
    $totalScore += $tot;
    $credits = 3.0; // Standard 3 credit hours per subject
    $totalCredits += $credits;
    $totalPoints += (get_gpa_points($tot) * $credits);
}

$cgpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0.00;
$overallPercent = $totalCourses > 0 ? round($totalScore / $totalCourses, 1) : 0;

// Academic Standing
$standing = "Good Standing";
if ($cgpa >= 3.75) $standing = "First Class Honours (Dean's List)";
elseif ($cgpa >= 3.5) $standing = "Second Class Honours (Upper Division)";
elseif ($cgpa < 2.0 && $totalCourses > 0) $standing = "Academic Probation";

// All students list for dropdown
$allStudents = [];
if (in_array($user_role, ['admin', 'teacher', 'finance'])) {
    $allStudents = $pdo->query("
        SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name 
        FROM students s 
        LEFT JOIN users u ON s.user_id = u.user_id 
        LEFT JOIN classes c ON s.class_id = c.class_id
        WHERE s.is_deleted = 0
        ORDER BY student_name ASC
    ")->fetchAll();
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Transcript - <?= htmlspecialchars($student['full_name'] ?? 'Student') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: var(--secondary, #525659);
            font-family: 'Plus Jakarta Sans', 'Cairo', system-ui, -apple-system, sans-serif;
            color: var(--foreground, #111);
        }

        /* Screen View: A4 Container simulation */
        .transcript-paper {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 14mm 16mm;
            border-radius: 4px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            position: relative;
            box-sizing: border-box;
        }

        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 4.5rem;
            font-weight: 900;
            color: rgba(0, 0, 0, 0.03);
            pointer-events: none;
            text-transform: uppercase;
            letter-spacing: 8px;
            z-index: 0;
            white-space: nowrap;
        }

        .content-layer {
            position: relative;
            z-index: 1;
        }

        /* Compact Typography & Tables */
        .transcript-header h2 { font-size: 1.35rem; letter-spacing: 0.5px; }
        .transcript-header p { font-size: 0.8rem; }
        .transcript-header .title-badge { font-size: 0.9rem; letter-spacing: 1px; }

        .info-grid {
            border: 1.5px solid #333;
            border-radius: 6px;
            background: #fafafa;
            padding: 8px 12px;
            margin-bottom: 12px;
        }
        .info-grid .info-item {
            font-size: 0.8rem;
            line-height: 1.3;
        }
        .info-grid .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: bold;
            color: #666;
        }

        .grades-table {
            border: 1.5px solid #333 !important;
            font-size: 0.8rem;
            margin-bottom: 10px;
        }
        .grades-table th {
            background-color: #212529 !important;
            color: #fff !important;
            font-size: 0.75rem;
            padding: 5px 6px !important;
            text-transform: uppercase;
        }
        .grades-table td {
            padding: 4px 6px !important;
            vertical-align: middle;
            border-color: #bbb;
        }

        .summary-card {
            border: 1.5px solid #0d6efd;
            border-radius: 6px;
            background: #f8fbff;
            padding: 8px 12px;
            margin-bottom: 14px;
        }
        .summary-card h4 { font-size: 1.25rem; }

        .signature-section {
            margin-top: 14px;
            padding-top: 10px;
            border-top: 1.5px solid #333;
        }
        .signature-line {
            width: 140px;
            margin: 0 auto;
            border-bottom: 1.5px solid #222;
            height: 30px;
        }

        /* Strict A4 Print Media Query */
        @page {
            size: A4 portrait;
            margin: 0;
        }
        @media print {
            html, body {
                width: 210mm !important;
                height: 297mm !important;
                background: white !important;
                margin: 0 !important;
                padding: 0 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print {
                display: none !important;
            }
            .transcript-paper {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 10mm 12mm !important;
                width: 210mm !important;
                height: 297mm !important;
                max-height: 297mm !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
            }
            .watermark {
                font-size: 3.5rem !important;
            }
        }
    </style>
</head>
<body class="bg-background">

<!-- Top Navigation Bar (Unified Dashboard Theme) -->
<?php
$extra_nav_items = [];

$extra_nav_items[] = '<a href="report_cards.php?student_id=' . $student_id . '" class="btn btn-outline-border btn-sm fw-semibold"><i class="fa-solid fa-file-lines me-1 text-primary"></i> ' . __('report_cards') . '</a>';

if (!empty($allStudents)) {
    $stu_opts = '';
    foreach ($allStudents as $st) {
        $sel = ($st['student_id'] == $student_id) ? 'selected' : '';
        $stu_opts .= '<option value="' . $st['student_id'] . '" ' . $sel . '>' . htmlspecialchars($st['student_name']) . ' (' . htmlspecialchars($st['class_name'] ?? 'General') . ')</option>';
    }
    $extra_nav_items[] = '
    <div class="d-flex align-items-center gap-2 m-0">
        <label class="small text-nowrap fw-semibold text-muted-foreground mb-0 d-none d-lg-inline">' . (is_rtl() ? 'اختر الطالب:' : 'Select Student:') . '</label>
        <select class="form-select form-select-sm fw-semibold border-border bg-card text-foreground" style="min-width: 220px; max-width: 280px;" onchange="if(this.value) location.href=\'transcript.php?student_id=\' + this.value;">
            ' . $stu_opts . '
        </select>
    </div>';
}

$extra_nav_items[] = '<button class="btn btn-primary btn-sm fw-semibold px-3 shadow-xs" onclick="window.print();"><i class="fa-solid fa-print me-1"></i> ' . (is_rtl() ? 'طباعة السجل الأكاديمي' : 'Print Transcript') . '</button>';
?>

<?= render_universal_navbar([
    'active_title' => __('academic_transcript'),
    'back_url' => 'index.php',
    'header_class' => 'no-print',
    'extra_nav_items' => $extra_nav_items
]) ?>

<!-- A4 Page Container -->
<div class="transcript-paper">
    <div class="watermark">OFFICIAL TRANSCRIPT</div>

    <div class="content-layer">
        <!-- 1. School Header -->
        <div class="text-center transcript-header border-bottom border-dark pb-2 mb-2">
            <div class="d-flex justify-content-center align-items-center gap-3 mb-1">
                <?php 
                $transcript_logo_url = get_school_logo_url($school_logo);
                if (!empty($transcript_logo_url)): ?>
                    <img src="<?= htmlspecialchars($transcript_logo_url) ?>" alt="Logo" style="max-height: 48px; max-width: 120px; object-fit: contain;">
                <?php else: ?>
                    <i class="fa-solid fa-graduation-cap fs-2 text-primary"></i>
                <?php endif; ?>
                <div>
                    <h2 class="fw-bold text-dark text-uppercase mb-0"><?= htmlspecialchars($school_name) ?></h2>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($school_address) ?> | Tel: <?= htmlspecialchars($school_phone) ?></p>
                </div>
            </div>
            <div class="title-badge fw-bold text-primary text-uppercase mt-1">
                OFFICIAL ACADEMIC TRANSCRIPT &amp; PERMANENT RECORD
            </div>
        </div>

        <!-- 2. Student Biographical Metadata (Compact Grid) -->
        <div class="info-grid">
            <div class="row g-2 info-item">
                <div class="col-6">
                    <span class="info-label d-block">Student Name</span>
                    <strong class="text-dark fs-6"><?= htmlspecialchars($student['full_name'] ?: ($student['first_name'] . ' ' . $student['last_name'])) ?></strong>
                </div>
                <div class="col-3">
                    <span class="info-label d-block">Student ID / Matric</span>
                    <strong class="text-primary">#<?= htmlspecialchars($student['admission_no'] ?: $student['student_id']) ?></strong>
                </div>
                <div class="col-3 text-end">
                    <span class="info-label d-block">Date of Issue</span>
                    <strong><?= date('F d, Y') ?></strong>
                </div>
                <div class="col-4">
                    <span class="info-label d-block">Class / Academic Program</span>
                    <strong><?= htmlspecialchars($student['class_name'] ?? 'General High School') ?></strong>
                </div>
                <div class="col-4">
                    <span class="info-label d-block">Enrollment Date</span>
                    <strong><?= date('M d, Y', strtotime($student['created_at'])) ?></strong>
                </div>
                <div class="col-4 text-end">
                    <span class="info-label d-block">Academic Standing</span>
                    <span class="badge bg-success py-1 px-2"><?= $standing ?></span>
                </div>
            </div>
        </div>

        <!-- 3. Course Grades Table -->
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-bold text-dark text-uppercase small"><i class="fa-solid fa-list-check me-1 text-primary"></i>Coursework &amp; Examination Records</span>
            <span class="text-muted small"><?= $totalCourses ?> Enrolled Courses</span>
        </div>

        <table class="table table-bordered grades-table align-middle">
            <thead>
                <tr>
                    <th style="width: 12%;">Code</th>
                    <th style="width: 40%;">Course Description</th>
                    <th class="text-center" style="width: 8%;">Credits</th>
                    <th class="text-center" style="width: 10%;">CA (40)</th>
                    <th class="text-center" style="width: 10%;">Exam (60)</th>
                    <th class="text-center" style="width: 10%;">Total</th>
                    <th class="text-center" style="width: 5%;">Grade</th>
                    <th class="text-center" style="width: 5%;">GPA</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($grades)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-3">No recorded coursework or examination grades found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($grades as $g): ?>
                        <?php 
                        $tot = floatval($g['total_score_val'] ?? 0); 
                        $pts = get_gpa_points($tot);
                        $letter = !empty($g['grade_letter']) && $g['grade_letter'] !== 'Pending' ? $g['grade_letter'] : get_letter_grade($tot);
                        ?>
                        <tr>
                            <td><code><?= htmlspecialchars($g['subject_code']) ?></code></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($g['subject_name']) ?></td>
                            <td class="text-center">3.0</td>
                            <td class="text-center"><?= $g['ca_score_val'] ?? 0 ?></td>
                            <td class="text-center"><?= $g['exam_score_val'] ?? 0 ?></td>
                            <td class="text-center fw-bold text-primary"><?= $tot ?></td>
                            <td class="text-center fw-bold"><?= $letter ?></td>
                            <td class="text-center fw-bold"><?= number_format($pts, 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- 4. Cumulative Performance Summary -->
        <div class="summary-card">
            <div class="row text-center align-items-center g-1">
                <div class="col-4">
                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;"><?= __('total_credit_hours') ?></small>
                    <h4 class="fw-bold text-dark mb-0"><?= number_format($totalCredits, 1) ?></h4>
                </div>
                <div class="col-4 border-start border-end">
                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;">Average Percentage</small>
                    <h4 class="fw-bold text-dark mb-0"><?= $overallPercent ?>%</h4>
                </div>
                <div class="col-4">
                    <small class="text-muted text-uppercase fw-bold d-block" style="font-size: 0.7rem;"><?= __('cumulative_gpa') ?> (Scale 4.0)</small>
                    <h3 class="fw-bold text-primary mb-0" style="font-size: 1.35rem;"><?= number_format($cgpa, 2) ?></h3>
                </div>
            </div>
        </div>

        <!-- 5. Grading Scale Key -->
        <div class="border rounded p-2 mb-2 bg-light text-center" style="font-size: 0.68rem; line-height: 1.2;">
            <strong>GRADING SCALE:</strong>
            A+ (90-100% / 4.0) &bull; A (85-89% / 3.75) &bull; B+ (80-84% / 3.5) &bull; B (75-79% / 3.0) &bull;
            C+ (70-74% / 2.5) &bull; C (65-69% / 2.0) &bull; D (50-64% / 1.0) &bull; F (&lt;50% / 0.0)
        </div>

        <!-- 6. Signatures & Official Seals Block -->
        <div class="signature-section">
            <div class="row text-center align-items-end g-2">
                <div class="col-4">
                    <div class="signature-line"></div>
                    <strong class="d-block text-dark small">Office of the Registrar</strong>
                    <span class="text-muted" style="font-size: 0.7rem;">Signature &amp; Verification</span>
                </div>
                <div class="col-4">
                    <div class="d-inline-block border border-2 border-dark rounded-circle p-2 text-muted fw-bold" style="width: 70px; height: 70px; font-size: 0.65rem; line-height: 1.1;">
                        <br>OFFICIAL SEAL
                    </div>
                </div>
                <div class="col-4">
                    <div class="signature-line"></div>
                    <strong class="d-block text-dark small">Academic Dean / Principal</strong>
                    <span class="text-muted" style="font-size: 0.7rem;">Signature &amp; Seal</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
