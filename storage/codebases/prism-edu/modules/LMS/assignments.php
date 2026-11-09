<?php
/**
 * modules/LMS/assignments.php
 * Dedicated Teacher Assignment Review & Grading Portal
 * (Core Umbrella Architecture)
 * 
 * Features:
 * 1. Assignment Creation, Editing & Management with Worksheet Attachments.
 * 2. Dedicated Interactive Student Submission Grading & Evaluation Desk.
 * 3. Individual & Batch Grading with Scores, Detailed Teacher Feedback, and Review Statuses.
 * 4. Real-time Submission Tracking (Submitted, Pending Review, Accepted/Graded, Needs Revision, Not Turned In).
 * 5. Full Bilingual RTL/LTR Support (English & Arabic).
 */

require_once __DIR__ . '/../../core/init.php';
require_once __DIR__ . '/../../licensing/license_helper.php';

// Verify License
$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

// Authentication Guard: Teachers, Admins, and School Admins
Auth::requireRole(['teacher', 'admin', 'school_admin']);

$user_id   = Auth::id();
$user_role = Auth::role();
$is_admin  = in_array($user_role, ['admin', 'school_admin']);

$msg = '';
$msg_type = 'success';

// Handle Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Create New Assignment
        if ($action === 'create_assignment') {
            $class_id             = (int)($_POST['class_id'] ?? 0);
            $subject_id           = (int)($_POST['subject_id'] ?? 0);
            $title                = trim($_POST['title'] ?? '');
            $instructions         = trim($_POST['instructions'] ?? '');
            $due_date             = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
            $max_score            = (float)($_POST['max_score'] ?? 100.00);
            $submission_type      = $_POST['submission_type'] ?? 'both';
            $deadline_type        = $_POST['deadline_type'] ?? 'soft';
            $cut_off_date         = !empty($_POST['cut_off_date']) ? $_POST['cut_off_date'] : null;
            $late_penalty_percent = (float)($_POST['late_penalty_percent'] ?? 0.00);
            $file_path            = null;

            if ($class_id <= 0 || $subject_id <= 0 || empty($title)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى ملء جميع الحقول المطلوبة (الفصل، المادة، العنوان).' : 'Please fill all required fields (Class, Subject, Title).');
            }

            // Handle Teacher Worksheet / Attachment Upload
            if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_tmp  = $_FILES['assignment_file']['tmp_name'];
                $file_name = time() . '_asn_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($_FILES['assignment_file']['name']));
                $target_file = $upload_dir . $file_name;

                if (move_uploaded_file($file_tmp, $target_file)) {
                    $file_path = 'uploads/' . $file_name;
                }
            }

            $stmt_ins = $pdo->prepare("
                INSERT INTO assignments (class_id, subject_id, teacher_id, title, instructions, file_path, due_date, max_score, submission_type, deadline_type, cut_off_date, late_penalty_percent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt_ins->execute([$class_id, $subject_id, $user_id, $title, $instructions, $file_path, $due_date, $max_score, $submission_type, $deadline_type, $cut_off_date, $late_penalty_percent]);

            $new_asn_id = (int)$pdo->lastInsertId();
            $_SESSION['flash_msg'] = is_rtl() ? 'تم إنشاء ونشر التكليف الأكاديمي بنجاح!' : 'Assignment created and published successfully to student portal!';
            $_SESSION['flash_type'] = 'success';
            header("Location: assignments.php?assignment_id=" . $new_asn_id);
            exit();
        }

        // 2. Edit Assignment
        if ($action === 'edit_assignment') {
            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            $class_id      = (int)($_POST['class_id'] ?? 0);
            $subject_id    = (int)($_POST['subject_id'] ?? 0);
            $title         = trim($_POST['title'] ?? '');
            $instructions  = trim($_POST['instructions'] ?? '');
            $due_date      = $_POST['due_date'] ?? date('Y-m-d');

            if ($assignment_id <= 0 || empty($title)) {
                throw new InvalidArgumentException(is_rtl() ? 'بيانات التكليف غير صالحة.' : 'Invalid assignment parameters.');
            }

            $file_path = null;
            if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_tmp  = $_FILES['assignment_file']['tmp_name'];
                $file_name = time() . '_asn_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($_FILES['assignment_file']['name']));
                $target_file = $upload_dir . $file_name;

                if (move_uploaded_file($file_tmp, $target_file)) {
                    $file_path = 'uploads/' . $file_name;
                }
            }

            if ($file_path) {
                $stmt_upd = $pdo->prepare("
                    UPDATE assignments 
                    SET class_id = ?, subject_id = ?, title = ?, instructions = ?, due_date = ?, file_path = ?
                    WHERE assignment_id = ? " . ($is_admin ? "" : "AND teacher_id = ?") . "
                ");
                $params = $is_admin 
                    ? [$class_id, $subject_id, $title, $instructions, $due_date, $file_path, $assignment_id]
                    : [$class_id, $subject_id, $title, $instructions, $due_date, $file_path, $assignment_id, $user_id];
                $stmt_upd->execute($params);
            } else {
                $stmt_upd = $pdo->prepare("
                    UPDATE assignments 
                    SET class_id = ?, subject_id = ?, title = ?, instructions = ?, due_date = ?
                    WHERE assignment_id = ? " . ($is_admin ? "" : "AND teacher_id = ?") . "
                ");
                $params = $is_admin 
                    ? [$class_id, $subject_id, $title, $instructions, $due_date, $assignment_id]
                    : [$class_id, $subject_id, $title, $instructions, $due_date, $assignment_id, $user_id];
                $stmt_upd->execute($params);
            }

            $_SESSION['flash_msg'] = is_rtl() ? 'تم تحديث بيانات التكليف بنجاح.' : 'Assignment details updated successfully.';
            $_SESSION['flash_type'] = 'success';
            header("Location: assignments.php?assignment_id=" . $assignment_id);
            exit();
        }

        // 3. Delete Assignment
        if ($action === 'delete_assignment') {
            $assignment_id = (int)($_POST['assignment_id'] ?? 0);
            if ($assignment_id > 0) {
                // Delete associated submissions first
                $pdo->prepare("DELETE FROM assignment_submissions WHERE assignment_id = ?")->execute([$assignment_id]);
                
                $del_stmt = $pdo->prepare("DELETE FROM assignments WHERE assignment_id = ? " . ($is_admin ? "" : "AND teacher_id = ?"));
                $params = $is_admin ? [$assignment_id] : [$assignment_id, $user_id];
                $del_stmt->execute($params);

                $_SESSION['flash_msg'] = is_rtl() ? 'تم حذف التكليف وسجلاته بنجاح.' : 'Assignment and its submission records deleted successfully.';
                $_SESSION['flash_type'] = 'info';
            }
            header("Location: assignments.php");
            exit();
        }

        // 4. Grade Single Submission
        if ($action === 'grade_single_submission') {
            $assignment_id    = (int)($_POST['assignment_id'] ?? 0);
            $student_id       = (int)($_POST['student_id'] ?? 0);
            $submission_id    = (int)($_POST['submission_id'] ?? 0);
            $grade            = trim($_POST['grade'] ?? '');
            $teacher_feedback = trim($_POST['teacher_feedback'] ?? '');
            $review_status    = $_POST['review_status'] ?? 'Accepted';
            $score_numeric    = is_numeric($grade) ? (float)$grade : null;

            if (!in_array($review_status, ['Accepted', 'Needs Revision', 'Pending'])) {
                $review_status = 'Accepted';
            }

            if ($submission_id > 0) {
                $upd = $pdo->prepare("
                    UPDATE assignment_submissions 
                    SET grade = ?, score_numeric = ?, teacher_feedback = ?, review_status = ? 
                    WHERE submission_id = ?
                ");
                $upd->execute([$grade, $score_numeric, $teacher_feedback, $review_status, $submission_id]);
            } else {
                // Insert evaluation record for student if they hadn't submitted
                $ins = $pdo->prepare("
                    INSERT INTO assignment_submissions (assignment_id, student_id, submission_text, file_path, submitted_at, status, grade, score_numeric, teacher_feedback, review_status)
                    VALUES (?, ?, '', NULL, NOW(), 'Graded by Teacher', ?, ?, ?, ?)
                ");
                $ins->execute([$assignment_id, $student_id, $grade, $score_numeric, $teacher_feedback, $review_status]);
            }

            $_SESSION['flash_msg'] = is_rtl() ? 'تم حفظ تقييم الطالب بنجاح!' : 'Student submission evaluated and graded successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: assignments.php?assignment_id=" . $assignment_id);
            exit();
        }

        // 5. Batch Grade Submissions
        if ($action === 'batch_grade_submissions') {
            $assignment_id    = (int)($_POST['assignment_id'] ?? 0);
            $grades           = $_POST['grades'] ?? [];
            $feedbacks        = $_POST['feedbacks'] ?? [];
            $review_statuses  = $_POST['review_statuses'] ?? [];
            $submission_ids   = $_POST['submission_ids'] ?? [];

            $pdo->beginTransaction();
            foreach ($grades as $s_id => $grd) {
                $s_id = (int)$s_id;
                $grd_val = trim($grd);
                $fb_val  = trim($feedbacks[$s_id] ?? '');
                $st_val  = $review_statuses[$s_id] ?? 'Accepted';
                $score_num = is_numeric($grd_val) ? (float)$grd_val : null;

                if (!in_array($st_val, ['Accepted', 'Needs Revision', 'Pending'])) {
                    $st_val = 'Accepted';
                }

                $sub_id = (int)($submission_ids[$s_id] ?? 0);

                if ($sub_id > 0) {
                    $upd = $pdo->prepare("
                        UPDATE assignment_submissions 
                        SET grade = ?, score_numeric = ?, teacher_feedback = ?, review_status = ? 
                        WHERE submission_id = ?
                    ");
                    $upd->execute([$grd_val, $score_num, $fb_val, $st_val, $sub_id]);
                } elseif (!empty($grd_val) || !empty($fb_val)) {
                    $ins = $pdo->prepare("
                        INSERT INTO assignment_submissions (assignment_id, student_id, submission_text, file_path, submitted_at, status, grade, score_numeric, teacher_feedback, review_status)
                        VALUES (?, ?, '', NULL, NOW(), 'Graded by Teacher', ?, ?, ?, ?)
                    ");
                    $ins->execute([$assignment_id, $s_id, $grd_val, $score_num, $fb_val, $st_val]);
                }
            }
            $pdo->commit();

            $_SESSION['flash_msg'] = is_rtl() ? 'تم حفظ وتحديث تقييمات الفصل الدراسي كاملاً بنجاح!' : 'All student assignment marks and feedback saved successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: assignments.php?assignment_id=" . $assignment_id);
            exit();
        }

        // 6. Gradebook Continuous Assessment Sync Action
        if ($action === 'sync_gradebook_ca') {
            $class_id   = (int)($_POST['class_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $term       = $_POST['term'] ?? 'Term 1';
            $year       = $_POST['academic_year'] ?? '2025/2026';

            if ($class_id > 0 && $subject_id > 0) {
                $students = $pdo->prepare("SELECT student_id FROM students WHERE class_id = ?");
                $students->execute([$class_id]);
                $st_list = $students->fetchAll(PDO::FETCH_COLUMN);

                $synced = 0;
                foreach ($st_list as $s_id) {
                    $g_stmt = $pdo->prepare("
                        SELECT subm.grade, subm.score_numeric, a.max_score
                        FROM assignment_submissions subm
                        JOIN assignments a ON subm.assignment_id = a.assignment_id
                        WHERE a.class_id = ? AND a.subject_id = ? AND subm.student_id = ?
                          AND subm.review_status = 'Accepted'
                    ");
                    $g_stmt->execute([$class_id, $subject_id, $s_id]);
                    $subms = $g_stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($subms)) {
                        $total_pct = 0;
                        $valid_count = 0;
                        foreach ($subms as $sub) {
                            $score = null;
                            if ($sub['score_numeric'] !== null) {
                                $score = (float)$sub['score_numeric'];
                            } elseif (is_numeric($sub['grade'])) {
                                $score = (float)$sub['grade'];
                            }
                            if ($score !== null) {
                                $max = ($sub['max_score'] > 0) ? (float)$sub['max_score'] : 100.0;
                                $pct = ($score / $max) * 100.0;
                                $total_pct += $pct;
                                $valid_count++;
                            }
                        }

                        if ($valid_count > 0) {
                            $avg_pct = $total_pct / $valid_count;
                            $ca_score = round(($avg_pct / 100.0) * 40.0, 2); // 40% Continuous Assessment

                            $chk = $pdo->prepare("SELECT grade_id, exam_score FROM grades WHERE student_id = ? AND subject_id = ? AND term = ? AND academic_year = ?");
                            $chk->execute([$s_id, $subject_id, $term, $year]);
                            $ext = $chk->fetch(PDO::FETCH_ASSOC);

                            if ($ext) {
                                $exam_score = $ext['exam_score'];
                                $total_score = ($exam_score !== null) ? ($ca_score + (float)$exam_score) : $ca_score;
                                $grade_letter = 'Pending';
                                if ($total_score >= 90) $grade_letter = 'A+';
                                elseif ($total_score >= 85) $grade_letter = 'A';
                                elseif ($total_score >= 80) $grade_letter = 'B+';
                                elseif ($total_score >= 75) $grade_letter = 'B';
                                elseif ($total_score >= 70) $grade_letter = 'C+';
                                elseif ($total_score >= 65) $grade_letter = 'C';
                                elseif ($total_score >= 50) $grade_letter = 'D';
                                else $grade_letter = 'F';

                                $upd_g = $pdo->prepare("UPDATE grades SET ca_score = ?, total_score = ?, grade_letter = ? WHERE grade_id = ?");
                                $upd_g->execute([$ca_score, $total_score, $grade_letter, $ext['grade_id']]);
                            } else {
                                $ins_g = $pdo->prepare("INSERT INTO grades (student_id, subject_id, class_id, ca_score, total_score, grade_letter, term, academic_year) VALUES (?, ?, ?, ?, ?, 'Pending', ?, ?)");
                                $ins_g->execute([$s_id, $subject_id, $class_id, $ca_score, $ca_score, $term, $year]);
                            }
                            $synced++;
                        }
                    }
                }

                $_SESSION['flash_msg'] = is_rtl() 
                    ? "تمت مزامنة درجات أعمال السنة (CA) بنجاح لـ {$synced} طالباً مع سجل الدرجات والامتحانات!" 
                    : "Successfully synced continuous assessment (CA) coursework for {$synced} student(s) into Examinations & Grading!";
                $_SESSION['flash_type'] = 'success';
                header("Location: assignments.php" . (!empty($_POST['assignment_id']) ? "?assignment_id=" . (int)$_POST['assignment_id'] : ""));
                exit();
            }
        }


    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

// Retrieve flash message from session
if (isset($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Fetch Classes & Subjects for dropdowns
$classes  = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Selected Assignment ID for detailed Grading Desk
$selected_assignment_id = (int)($_GET['assignment_id'] ?? 0);
$filter_class_id        = (int)($_GET['class_id'] ?? 0);
$filter_subject_id      = (int)($_GET['subject_id'] ?? 0);
$filter_status          = $_GET['status'] ?? 'all'; // all, pending, graded, revision

// Fetch Summary Stats for Teacher/Admin
$stat_where = $is_admin ? "" : "WHERE a.teacher_id = " . (int)$user_id;

$total_assignments_count = (int)$pdo->query("SELECT COUNT(*) FROM assignments a $stat_where")->fetchColumn();

$total_submissions_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    $stat_where
")->fetchColumn();

$pending_reviews_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    " . ($is_admin ? "WHERE" : "WHERE a.teacher_id = " . (int)$user_id . " AND") . " subm.review_status = 'Pending'
")->fetchColumn();

$accepted_reviews_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    " . ($is_admin ? "WHERE" : "WHERE a.teacher_id = " . (int)$user_id . " AND") . " subm.review_status = 'Accepted'
")->fetchColumn();

$revision_reviews_count = (int)$pdo->query("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    " . ($is_admin ? "WHERE" : "WHERE a.teacher_id = " . (int)$user_id . " AND") . " subm.review_status = 'Needs Revision'
")->fetchColumn();

// If an assignment is selected, fetch its details and all student submissions in that class
$active_assignment = null;
$student_submissions = [];

if ($selected_assignment_id > 0) {
    $stmt_act = $pdo->prepare("
        SELECT a.*, c.class_name, s.subject_name, u.full_name as teacher_name
        FROM assignments a
        JOIN classes c ON a.class_id = c.class_id
        JOIN subjects s ON a.subject_id = s.subject_id
        LEFT JOIN users u ON a.teacher_id = u.user_id
        WHERE a.assignment_id = ? " . ($is_admin ? "" : "AND a.teacher_id = ?") . "
    ");
    $stmt_act->execute($is_admin ? [$selected_assignment_id] : [$selected_assignment_id, $user_id]);
    $active_assignment = $stmt_act->fetch(PDO::FETCH_ASSOC);

    if ($active_assignment) {
        // Fetch all enrolled students in this class + their submission if exists
        $stmt_subs = $pdo->prepare("
            SELECT st.student_id, st.admission_no, COALESCE(u.full_name, CONCAT(st.first_name, ' ', st.last_name)) as student_name,
                   subm.submission_id, subm.submission_text, subm.file_path as student_file, subm.submitted_at,
                   subm.status as submission_status, subm.grade, subm.teacher_feedback, subm.review_status
            FROM students st
            LEFT JOIN users u ON st.user_id = u.user_id
            LEFT JOIN assignment_submissions subm ON st.student_id = subm.student_id AND subm.assignment_id = ?
            WHERE st.class_id = ?
            ORDER BY st.student_id ASC
        ");
        $stmt_subs->execute([$selected_assignment_id, $active_assignment['class_id']]);
        $student_submissions = $stmt_subs->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Fetch list of assignments for Overview table
$where_clauses = [];
$params = [];

if (!$is_admin) {
    $where_clauses[] = "a.teacher_id = ?";
    $params[] = $user_id;
}
if ($filter_class_id > 0) {
    $where_clauses[] = "a.class_id = ?";
    $params[] = $filter_class_id;
}
if ($filter_subject_id > 0) {
    $where_clauses[] = "a.subject_id = ?";
    $params[] = $filter_subject_id;
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

$assignments_list_stmt = $pdo->prepare("
    SELECT a.*, c.class_name, s.subject_name, u.full_name as teacher_name,
           (SELECT COUNT(*) FROM students st WHERE st.class_id = a.class_id) as total_enrolled,
           (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.assignment_id) as total_turned_in,
           (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.assignment_id AND subm.review_status = 'Pending') as pending_count,
           (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.assignment_id AND subm.review_status = 'Accepted') as accepted_count,
           (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.assignment_id AND subm.review_status = 'Needs Revision') as revision_count
    FROM assignments a
    JOIN classes c ON a.class_id = c.class_id
    JOIN subjects s ON a.subject_id = s.subject_id
    LEFT JOIN users u ON a.teacher_id = u.user_id
    $where_sql
    ORDER BY a.due_date DESC, a.assignment_id DESC
");
$assignments_list_stmt->execute($params);
$assignments_list = $assignments_list_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'بوابة تصحيح وتقييم الواجبات الأكاديمية' : 'Teacher Assignment Review & Grading Desk' ?> - <?= htmlspecialchars($global_branding['school_name'] ?? 'EduMaster') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --asn-primary: #0284c7;
            --asn-primary-hover: #0369a1;
            --asn-success: #10b981;
            --asn-warning: #f59e0b;
            --asn-danger: #ef4444;
            --asn-card-border: var(--border, #e2e8f0);
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Cairo', system-ui, -apple-system, sans-serif;
            background-color: var(--background, #f8fafc);
            color: var(--foreground, #0f172a);
        }

        .hero-banner {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: white;
            border-radius: 16px;
        }

        .metric-card {
            border: 1px solid var(--asn-card-border);
            border-radius: 14px;
            background: #ffffff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.06);
        }

        .assignment-row-card {
            border: 1px solid var(--asn-card-border);
            border-radius: 12px;
            background: #ffffff;
            transition: all 0.2s ease;
        }
        .assignment-row-card:hover {
            border-color: #38bdf8;
            box-shadow: 0 4px 12px rgba(2, 132, 199, 0.08);
        }

        .status-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 20px;
        }

        .student-subm-row {
            transition: background 0.15s ease;
        }
        .student-subm-row:hover {
            background-color: #f1f5f9;
        }

        .avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
            background: #e0f2fe;
            color: #0284c7;
        }

        .feedback-input-container {
            position: relative;
        }
        .feedback-textarea {
            transition: all 0.25s ease;
            font-size: 0.82rem;
            min-height: 48px;
            resize: vertical;
        }
        .feedback-textarea.revision-mode {
            border-color: #ef4444 !important;
            background-color: #fef2f2 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
        }
        .preset-chip {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            color: #475569;
            cursor: pointer;
            transition: all 0.15s ease;
            user-select: none;
            text-decoration: none;
        }
        .preset-chip:hover {
            border-color: #ef4444;
            color: #dc2626;
            background-color: #fff1f2;
            transform: translateY(-1px);
        }
        .preset-chip.chip-accepted:hover {
            border-color: #10b981;
            color: #059669;
            background-color: #ecfdf5;
        }
        .revision-hint {
            font-size: 0.7rem;
            font-weight: 600;
            color: #dc2626;
            display: none;
            margin-top: 3px;
        .revision-hint.show {
            display: flex;
            align-items: center;
            gap: 4px;
        }
    </style>
</head>
<body class="bg-background">

    <!-- Universal Top Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'تصحيح وتقييم الواجبات' : 'Assignment Grading Desk',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'دليل المقررات' : 'Courses') . '</a>',
            '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-info"></i> ' . (is_rtl() ? 'المستودع الرقمي' : 'Materials') . '</a>',
            '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-lg-4 py-4">

        <!-- Flash Alert Notification -->
        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs mb-4" role="alert">
                <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : ($msg_type === 'danger' ? 'fa-triangle-exclamation' : 'fa-circle-info') ?> fs-5"></i>
                <div><?= htmlspecialchars($msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header Actions Bar -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-file-signature text-primary me-2"></i>
                    <span><?= is_rtl() ? 'بوابة تصحيح وتقييم التكليفات والواجبات الأكاديمية' : 'Teacher Assignment Review & Grading Desk' ?></span>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() 
                        ? 'مراجعة واجبات الطلاب، تقييم الحلول، تسجيل الدرجات والملاحظات الإرشادية، ومزامنة أعمال السنة مع سجل الامتحانات.' 
                        : 'Review turned-in student solutions, assign marks & constructive feedback, track pending submissions, and sync continuous assessment scores.' ?>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newAssignmentModal">
                    <i class="fa-solid fa-plus-circle me-1"></i>
                    <span><?= is_rtl() ? 'إنشاء تكليف جديد' : 'New Assignment' ?></span>
                </button>
                <?php if ($selected_assignment_id > 0): ?>
                    <a href="assignments.php" class="btn btn-outline-border rounded-xl shadow-sm px-3">
                        <i class="fa-solid fa-arrow-left me-1"></i>
                        <span><?= is_rtl() ? 'كافة التكليفات' : 'All Assignments' ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Summary Metrics Bar -->
        <div class="row g-3 mb-4">
            <!-- Active Assignments -->
            <div class="col-xl-3 col-sm-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <?= is_rtl() ? 'إجمالي التكليفات' : 'Active Assignments' ?>
                        </div>
                        <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-file-lines"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1"><?= $total_assignments_count ?></div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div class="stat-trend text-primary">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?= is_rtl() ? 'تكليفات نشطة' : 'Active Coursework' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Review -->
            <div class="col-xl-3 col-sm-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <?= is_rtl() ? 'بانتظار التصحيح' : 'Pending Review' ?>
                        </div>
                        <div class="action-icon-box icon-box-warning" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-hourglass-half"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-warning"><?= $pending_reviews_count ?></div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div class="stat-trend text-warning">
                            <i class="fa-solid fa-clock"></i>
                            <span><?= is_rtl() ? 'حلول بانتظار التقييم' : 'Submissions to Grade' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Graded & Accepted -->
            <div class="col-xl-3 col-sm-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <?= is_rtl() ? 'تم التقييم والاعتماد' : 'Graded & Accepted' ?>
                        </div>
                        <div class="action-icon-box icon-box-success" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-success"><?= $accepted_reviews_count ?></div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div class="stat-trend text-success">
                            <i class="fa-solid fa-check-double"></i>
                            <span><?= is_rtl() ? 'حلول معتمدة ومكتملة' : 'Passed Submissions' ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Needs Revision -->
            <div class="col-xl-3 col-sm-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                            <?= is_rtl() ? 'مطلوب مراجعة وإعادة تسليم' : 'Needs Revision' ?>
                        </div>
                        <div class="action-icon-box icon-box-purple" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-rotate-left"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-danger"><?= $revision_reviews_count ?></div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div class="stat-trend text-danger">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <span><?= is_rtl() ? 'ملاحظات تصحيحية مرسلة' : 'Feedback Given (Redo)' ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- VIEW 1: DEDICATED STUDENT SUBMISSION GRADING & EVALUATION WORKSPACE -->
        <!-- ========================================================================= -->
        <?php if ($active_assignment): ?>

            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white py-3 px-4 border-bottom">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge bg-primary px-2.5 py-1.5"><?= htmlspecialchars($active_assignment['class_name']) ?></span>
                                <span class="badge bg-secondary text-dark px-2.5 py-1.5"><?= htmlspecialchars($active_assignment['subject_name']) ?></span>
                                <span class="text-muted small"><i class="fa-regular fa-clock me-1"></i><?= is_rtl() ? 'تاريخ التسليم الأخير:' : 'Due Date:' ?> <strong><?= htmlspecialchars($active_assignment['due_date']) ?></strong></span>
                            </div>
                            <h2 class="h4 fw-bold mb-0 text-dark"><?= htmlspecialchars($active_assignment['title']) ?></h2>
                            <?php if (!empty($active_assignment['instructions'])): ?>
                                <p class="text-muted mb-0 small mt-1"><?= nl2br(htmlspecialchars($active_assignment['instructions'])) ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($active_assignment['file_path'])): ?>
                                <a href="../../<?= htmlspecialchars($active_assignment['file_path']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm fw-semibold">
                                    <i class="fa-solid fa-paperclip me-1 text-primary"></i> <?= is_rtl() ? 'عرض ورقة التكليف' : 'View Worksheet Attachment' ?>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-primary btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#editAssignmentModal">
                                <i class="fa-solid fa-pen me-1"></i> <?= is_rtl() ? 'تعديل التكليف' : 'Edit' ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Submissions Evaluation Table & Batch Submission Form -->
                <div class="card-body p-0">
                    <form method="POST">
                        <input type="hidden" name="action" value="batch_grade_submissions">
                        <input type="hidden" name="assignment_id" value="<?= $active_assignment['assignment_id'] ?>">

                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-bold text-dark small"><i class="fa-solid fa-users me-1 text-primary"></i><?= is_rtl() ? 'قائمة طلاب الفصل والتسليمات:' : 'Enrolled Students & Submission Status:' ?></span>
                                <span class="badge bg-white text-secondary border"><?= count($student_submissions) ?> <?= is_rtl() ? 'طالب' : 'Students' ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm rounded-xl px-3" onclick="document.getElementById('syncGradebookForm').submit();" title="<?= is_rtl() ? 'مزامنة درجات أعمال السنة مع سجل الدرجات' : 'Sync Continuous Assessment Coursework' ?>">
                                    <i class="fa-solid fa-arrows-rotate me-1"></i> <?= is_rtl() ? 'مزامنة أعمال السنة (CA)' : 'Sync to Gradebook (CA)' ?>
                                </button>
                                <button type="submit" class="btn btn-success btn-sm fw-bold px-4 shadow-xs">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ كافة الدرجات والتقييمات' : 'Save All Grades & Feedback' ?>
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light text-muted small text-uppercase">
                                    <tr>
                                        <th style="width: 18%;"><?= is_rtl() ? 'اسم الطالب / الرقم الأكاديمي' : 'Student & Admission No' ?></th>
                                        <th style="width: 17%;"><?= is_rtl() ? 'حالة وحل الطالب' : 'Student Solution / Attachment' ?></th>
                                        <th style="width: 16%;"><?= is_rtl() ? 'قرار المراجعة (Decision)' : 'Review Decision' ?></th>
                                        <th style="width: 11%;"><?= is_rtl() ? 'الدرجة / التقييم' : 'Grade / Score' ?></th>
                                        <th style="width: 32%;"><?= is_rtl() ? 'توجيهات التعديل / ملاحظات المعلم' : 'Revision / Redo Comments & Feedback' ?></th>
                                        <th style="width: 6%;" class="text-center"><?= is_rtl() ? 'تفاصيل' : 'Detail' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($student_submissions)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="fa-solid fa-user-xmark fs-2 mb-2 d-block text-secondary"></i>
                                                <?= is_rtl() ? 'لا يوجد طلاب مسجلين في هذا الفصل الدراسي حالياً.' : 'No enrolled students found in this class section.' ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($student_submissions as $subm): 
                                            $st_id = (int)$subm['student_id'];
                                            $has_submitted = !empty($subm['submission_id']);
                                            $rev_status = $subm['review_status'] ?? 'Pending';
                                        ?>
                                            <tr class="student-subm-row">
                                                <input type="hidden" name="submission_ids[<?= $st_id ?>]" value="<?= (int)($subm['submission_id'] ?? 0) ?>">

                                                <!-- Student Info -->
                                                <td>
                                                    <div class="d-flex align-items-center gap-2.5">
                                                        <div class="avatar-circle">
                                                            <?= strtoupper(mb_substr($subm['student_name'] ?? 'S', 0, 1)) ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold text-dark"><?= htmlspecialchars($subm['student_name'] ?? 'Student') ?></div>
                                                            <span class="badge bg-light text-muted border font-monospace" style="font-size: 0.72rem;"><?= htmlspecialchars($subm['admission_no'] ?? '-') ?></span>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Student Submission Details -->
                                                <td>
                                                    <?php if ($has_submitted): ?>
                                                        <div class="d-flex flex-column gap-1">
                                                            <div class="d-flex align-items-center gap-1.5">
                                                                <span class="status-badge-pill bg-success bg-opacity-10 text-success">
                                                                    <i class="fa-solid fa-circle-check"></i> <?= is_rtl() ? 'تم التسليم' : 'Turned In' ?>
                                                                </span>
                                                                <span class="text-muted" style="font-size: 0.7rem;"><?= date('M d, H:i', strtotime($subm['submitted_at'])) ?></span>
                                                            </div>
                                                            
                                                            <?php if (!empty($subm['submission_text'])): ?>
                                                                <div class="p-2 bg-light rounded text-dark small border" style="font-size: 0.75rem; max-width: 230px; white-space: pre-line;">
                                                                    <?= htmlspecialchars($subm['submission_text']) ?>
                                                                </div>
                                                            <?php endif; ?>

                                                            <?php if (!empty($subm['student_file'])): ?>
                                                                <div class="d-flex align-items-center gap-1">
                                                                    <a href="../../<?= htmlspecialchars($subm['student_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0.5 px-2" style="font-size: 0.72rem;">
                                                                        <i class="fa-solid fa-file-arrow-down me-1"></i> <?= is_rtl() ? 'تحميل' : 'Download' ?>
                                                                    </a>
                                                                    <button type="button" class="btn btn-sm btn-outline-info py-0.5 px-2 rounded-lg" style="font-size: 0.72rem;"
                                                                            onclick="previewStudentFile('<?= htmlspecialchars(addslashes($subm['student_name'])) ?>', '../../<?= htmlspecialchars($subm['student_file']) ?>')">
                                                                        <i class="fa-solid fa-eye me-1"></i> <?= is_rtl() ? 'معاينة' : 'Preview' ?>
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="status-badge-pill bg-secondary text-muted">
                                                            <i class="fa-solid fa-circle-minus"></i> <?= is_rtl() ? 'لم يتم التسليم بعد' : 'Not Turned In Yet' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>

                                                <!-- Review Status / Decision Dropdown -->
                                                <td>
                                                    <select name="review_statuses[<?= $st_id ?>]" id="status_<?= $st_id ?>" 
                                                            class="form-select form-select-sm fw-bold <?= $rev_status === 'Accepted' ? 'border-success text-success' : ($rev_status === 'Needs Revision' ? 'border-danger text-danger bg-danger bg-opacity-10' : 'border-warning text-warning') ?>"
                                                            onchange="onReviewStatusChange(this, <?= $st_id ?>)">
                                                        <option value="Accepted" <?= $rev_status === 'Accepted' ? 'selected' : '' ?>>
                                                            <?= is_rtl() ? '✅ معتمد (Accepted)' : '✅ Accepted (Graded)' ?>
                                                        </option>
                                                        <option value="Needs Revision" <?= $rev_status === 'Needs Revision' ? 'selected' : '' ?>>
                                                            <?= is_rtl() ? '⚠️ مطلوب تعديل (Revision)' : '⚠️ Needs Revision (Redo)' ?>
                                                        </option>
                                                        <option value="Pending" <?= $rev_status === 'Pending' ? 'selected' : '' ?>>
                                                            <?= is_rtl() ? '⏳ قيد المراجعة (Pending)' : '⏳ Pending Evaluation' ?>
                                                        </option>
                                                    </select>
                                                </td>

                                                <!-- Grade Score Input -->
                                                <td>
                                                    <input type="text" name="grades[<?= $st_id ?>]" id="grade_<?= $st_id ?>" 
                                                           class="form-control form-control-sm fw-bold text-center" 
                                                           value="<?= htmlspecialchars($subm['grade'] ?? '') ?>" 
                                                           placeholder="<?= is_rtl() ? 'مثال: 95' : 'e.g. 95' ?>">
                                                </td>

                                                <!-- Teacher Feedback & Revision / Redo Comments -->
                                                <td>
                                                    <div class="feedback-input-container">
                                                        <textarea name="feedbacks[<?= $st_id ?>]" id="feedback_<?= $st_id ?>" 
                                                                  class="form-control form-control-sm feedback-textarea <?= $rev_status === 'Needs Revision' ? 'revision-mode' : '' ?>" 
                                                                  rows="1" 
                                                                  placeholder="<?= is_rtl() ? 'اكتب ملاحظات وتوجيهات التعديل للطالب...' : 'Constructive feedback or revision instructions...' ?>"><?= htmlspecialchars($subm['teacher_feedback'] ?? '') ?></textarea>
                                                        
                                                        <div id="revision_hint_<?= $st_id ?>" class="revision-hint <?= $rev_status === 'Needs Revision' ? 'show' : '' ?>">
                                                            <i class="fa-solid fa-triangle-exclamation"></i>
                                                            <span><?= is_rtl() ? 'مطلوب توضيح الأسئلة المطلوب تعديلها لإعادة التسليم.' : 'Please provide specific redo instructions for student.' ?></span>
                                                        </div>

                                                        <!-- Quick Revision Preset Chips -->
                                                        <div class="d-flex flex-wrap gap-1 mt-1 align-items-center">
                                                            <span class="text-muted" style="font-size: 0.65rem; font-weight: 700;"><?= is_rtl() ? 'قوالب سريعة:' : 'Quick Presets:' ?></span>
                                                            <button type="button" class="preset-chip" onclick="insertPresetFeedback(<?= $st_id ?>, '<?= is_rtl() ? 'يرجى إعادة حل السؤال وإظهار خطوات الحل بالتفصيل.' : 'Please redo question #3 and show full working steps.' ?>', true)">
                                                                <i class="fa-solid fa-rotate-left text-danger"></i> <?= is_rtl() ? 'إعادة حل' : 'Redo Question' ?>
                                                            </button>
                                                            <button type="button" class="preset-chip" onclick="insertPresetFeedback(<?= $st_id ?>, '<?= is_rtl() ? 'التسليم غير مكتمل - يرجى حل كافة الأسئلة وإعادة التسليم.' : 'Incomplete submission - please complete all required sections.' ?>', true)">
                                                                <i class="fa-solid fa-list-check text-warning"></i> <?= is_rtl() ? 'غير مكتمل' : 'Incomplete' ?>
                                                            </button>
                                                            <button type="button" class="preset-chip" onclick="insertPresetFeedback(<?= $st_id ?>, '<?= is_rtl() ? 'الملف المرفق غير واضح. يرجى إعادة إرفاق صورة واضحة أو مستند PDF.' : 'Attachment is unreadable. Please re-upload a clear photo or scan.' ?>', true)">
                                                                <i class="fa-solid fa-camera text-primary"></i> <?= is_rtl() ? 'صورة أوضح' : 'Clear Scan' ?>
                                                            </button>
                                                            <button type="button" class="preset-chip chip-accepted" onclick="insertPresetFeedback(<?= $st_id ?>, '<?= is_rtl() ? 'عمل ممتاز وإجابات نموذجية متكاملة!' : 'Excellent work with complete, accurate answers!' ?>', false)">
                                                                <i class="fa-solid fa-star text-success"></i> <?= is_rtl() ? 'ممتاز' : 'Excellent' ?>
                                                            </button>
                                                        </div>
                                                    </div>
                                                </td>

                                                <!-- Individual Detail Modal Launcher -->
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-outline-primary btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center" 
                                                            style="width: 32px; height: 32px;" 
                                                            title="<?= is_rtl() ? 'مراجعة وتعديل تفصيلي' : 'Detailed Review & Revision Modal' ?>"
                                                            onclick='openStudentGradeModal(<?= json_encode([
                                                                "student_id" => $st_id,
                                                                "submission_id" => (int)($subm["submission_id"] ?? 0),
                                                                "student_name" => $subm["student_name"] ?? "Student",
                                                                "admission_no" => $subm["admission_no"] ?? "-",
                                                                "grade" => $subm["grade"] ?? "",
                                                                "review_status" => $rev_status,
                                                                "teacher_feedback" => $subm["teacher_feedback"] ?? "",
                                                                "has_submitted" => $has_submitted,
                                                                "submission_text" => $subm["submission_text"] ?? "",
                                                                "student_file" => $subm["student_file"] ?? "",
                                                                "submitted_at" => !empty($subm["submitted_at"]) ? date("M d, Y H:i", strtotime($subm["submitted_at"])) : ""
                                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                        <i class="fa-solid fa-up-right-and-down-left-from-center" style="font-size: 0.75rem;"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-3 bg-light border-top text-end">
                            <button type="submit" class="btn btn-success fw-bold px-4 shadow-xs">
                                <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ كافة الدرجات والتقييمات' : 'Save All Grades & Feedback' ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <!-- ========================================================================= -->
        <!-- VIEW 2: ASSIGNMENTS OVERVIEW & DISCOVERY TABLE -->
        <!-- ========================================================================= -->
        <?php else: ?>

            <!-- Filter Strip -->
            <div class="card border-0 bg-white shadow-xs mb-4 rounded-3">
                <div class="card-body py-2 px-3">
                    <form method="GET" class="row g-2 align-items-center">
                        <div class="col-auto">
                            <span class="small fw-bold text-muted"><i class="fa-solid fa-filter me-1 text-primary"></i><?= is_rtl() ? 'تصفية حسب الفصل:' : 'Filter Class:' ?></span>
                        </div>
                        <div class="col-auto">
                            <select name="class_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                                <option value="0"><?= is_rtl() ? 'كافة الفصول والشعب' : 'All Classes' ?></option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['class_id'] ?>" <?= $filter_class_id === (int)$c['class_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['class_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-auto ms-md-2">
                            <span class="small fw-bold text-muted"><?= is_rtl() ? 'المادة الدراسية:' : 'Subject:' ?></span>
                        </div>
                        <div class="col-auto">
                            <select name="subject_id" class="form-select form-select-sm fw-bold" onchange="this.form.submit()">
                                <option value="0"><?= is_rtl() ? 'كافة المواد' : 'All Subjects' ?></option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?= $s['subject_id'] ?>" <?= $filter_subject_id === (int)$s['subject_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['subject_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if ($filter_class_id > 0 || $filter_subject_id > 0): ?>
                            <div class="col-auto">
                                <a href="assignments.php" class="btn btn-sm btn-link text-decoration-none text-danger">
                                    <i class="fa-solid fa-xmark me-1"></i><?= is_rtl() ? 'إعادة ضبط' : 'Reset' ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Assignments Card Grid -->
            <div class="row g-3">
                <?php if (empty($assignments_list)): ?>
                    <div class="col-12">
                        <div class="card border-0 shadow-sm p-5 text-center bg-white rounded-4">
                            <i class="fa-solid fa-file-circle-question fs-1 text-muted mb-3 d-block"></i>
                            <h4 class="fw-bold text-dark mb-1"><?= is_rtl() ? 'لا توجد تكليفات مسجلة حالياً' : 'No assignments found' ?></h4>
                            <p class="text-muted small mb-3"><?= is_rtl() ? 'ابدأ بنشر أول تكليف أكاديمي أو واجب منزلي للطلاب في هذا الفصل.' : 'Get started by creating and publishing your first assignment for your students.' ?></p>
                            <div>
                                <button type="button" class="btn btn-primary fw-bold px-4 shadow-xs" data-bs-toggle="modal" data-bs-target="#newAssignmentModal">
                                    <i class="fa-solid fa-plus me-1"></i> <?= is_rtl() ? 'إنشاء تكليف جديد الآن' : 'Create New Assignment' ?>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($assignments_list as $asn): 
                        $pct = ($asn['total_enrolled'] > 0) ? round(($asn['total_turned_in'] / $asn['total_enrolled']) * 100) : 0;
                        $is_past_due = strtotime($asn['due_date']) < strtotime(date('Y-m-d'));
                    ?>
                        <div class="col-lg-6 col-xl-4">
                            <div class="assignment-row-card p-4 h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div class="d-flex align-items-center gap-1.5">
                                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25"><?= htmlspecialchars($asn['class_name']) ?></span>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($asn['subject_name']) ?></span>
                                        </div>
                                        <?php if ($is_past_due): ?>
                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 small"><?= is_rtl() ? 'منتهي التسليم' : 'Past Due' ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small"><?= is_rtl() ? 'متاح للتسليم' : 'Active' ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="h5 fw-bold text-dark mb-1">
                                        <a href="assignments.php?assignment_id=<?= $asn['assignment_id'] ?>" class="text-decoration-none text-dark hover-primary">
                                            <?= htmlspecialchars($asn['title']) ?>
                                        </a>
                                    </h3>
                                    
                                    <p class="text-muted small mb-3" style="min-height: 38px;">
                                        <?= !empty($asn['instructions']) ? htmlspecialchars(mb_substr($asn['instructions'], 0, 90)) . '...' : (is_rtl() ? 'لا توجد تعليمات إضافية.' : 'No additional instructions provided.') ?>
                                    </p>

                                    <!-- Submission Progress Meter -->
                                    <div class="p-2.5 bg-light rounded-3 mb-3 border">
                                        <div class="d-flex justify-content-between align-items-center small mb-1">
                                            <span class="fw-bold text-dark"><?= is_rtl() ? 'نسبة التسليم:' : 'Submissions:' ?></span>
                                            <span class="fw-bold text-primary"><?= $asn['total_turned_in'] ?> / <?= $asn['total_enrolled'] ?> (<?= $pct ?>%)</span>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $pct ?>%;"></div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mt-2" style="font-size: 0.72rem;">
                                            <span class="text-warning fw-bold"><i class="fa-solid fa-hourglass-half me-1"></i><?= $asn['pending_count'] ?> <?= is_rtl() ? 'قيد المراجعة' : 'Pending' ?></span>
                                            <span class="text-success fw-bold"><i class="fa-solid fa-circle-check me-1"></i><?= $asn['accepted_count'] ?> <?= is_rtl() ? 'معتمد' : 'Accepted' ?></span>
                                            <span class="text-danger fw-bold"><i class="fa-solid fa-rotate-left me-1"></i><?= $asn['revision_count'] ?> <?= is_rtl() ? 'تعديل' : 'Revision' ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                                    <span class="text-muted" style="font-size: 0.75rem;">
                                        <i class="fa-regular fa-calendar me-1"></i><?= is_rtl() ? 'التسليم:' : 'Due:' ?> <?= $asn['due_date'] ?>
                                    </span>

                                    <div class="d-flex align-items-center gap-1">
                                        <a href="assignments.php?assignment_id=<?= $asn['assignment_id'] ?>" class="btn btn-primary btn-sm fw-bold px-3">
                                            <i class="fa-solid fa-pen-to-square me-1"></i> <?= is_rtl() ? 'تصحيح وتقييم' : 'Review & Grade' ?>
                                        </a>
                                        <button type="button" class="btn btn-outline-danger btn-sm px-2" onclick="confirmDeleteAssignment(<?= $asn['assignment_id'] ?>, '<?= htmlspecialchars(addslashes($asn['title'])) ?>')">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    </div>

    <!-- Create Assignment Modal -->
    <div class="modal fade" id="newAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_assignment">
                    <div class="modal-header bg-dark text-white py-3 px-4">
                        <h5 class="modal-title fw-bold fs-6 d-flex align-items-center">
                            <i class="fa-solid fa-file-circle-plus me-2 text-warning"></i>
                            <span><?= is_rtl() ? 'إنشاء ونشر تكليف / واجب دراسي جديد' : 'Create & Publish New Assignment' ?></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4 bg-light">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الفصل والشعبة المستهدفة' : 'Target Class / Section' ?> <span class="text-danger">*</span></label>
                                <select name="class_id" class="form-select fw-bold" required>
                                    <option value=""><?= is_rtl() ? '-- اختر الفصل الدراسي --' : '-- Select Class --' ?></option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_id'] ?>" <?= ($filter_class_id === (int)$c['class_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['class_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'المادة الدراسية' : 'Subject' ?> <span class="text-danger">*</span></label>
                                <select name="subject_id" class="form-select fw-bold" required>
                                    <option value=""><?= is_rtl() ? '-- اختر المادة --' : '-- Select Subject --' ?></option>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['subject_id'] ?>" <?= ($filter_subject_id === (int)$s['subject_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['subject_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'عنوان الواجب / التكليف' : 'Assignment Title' ?> <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control fw-bold" required placeholder="e.g. Physics Chapter 4 Motion Problems Worksheet">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'التعليمات والإرشادات للطلاب' : 'Detailed Instructions for Students' ?></label>
                                <textarea name="instructions" class="form-control" rows="3" placeholder="<?= is_rtl() ? 'اكتب هنا أرقام الأسئلة، الصفحات، أو التوجيهات الخاصة بالحل والتسليم...' : 'Enter target questions, textbook pages, solution requirements, or submission guidelines...' ?>"></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'تاريخ التسليم المستهدف (Due Date)' : 'Target Due Date' ?> <span class="text-danger">*</span></label>
                                <input type="date" name="due_date" class="form-control fw-bold" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الدرجة القصوى (Max Score)' : 'Maximum Score' ?></label>
                                <input type="number" step="0.5" name="max_score" class="form-control fw-bold" value="100" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'طريقة التسليم المطلوبة' : 'Submission Type' ?></label>
                                <select name="submission_type" class="form-select fw-bold">
                                    <option value="both"><?= is_rtl() ? 'رفع ملف أو كتابة نص' : 'File Upload or Online Text' ?></option>
                                    <option value="file_upload"><?= is_rtl() ? 'ملف مرفق فقط (PDF/Word/صورة)' : 'File Upload Only' ?></option>
                                    <option value="online_text"><?= is_rtl() ? 'محرر نصي مباشر' : 'Online Text Only' ?></option>
                                    <option value="physical"><?= is_rtl() ? 'تسليم ورقي في الفصل' : 'Physical Classroom Hand-in' ?></option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'سياسة الموعد النهائي' : 'Deadline Policy' ?></label>
                                <select name="deadline_type" class="form-select fw-bold">
                                    <option value="soft"><?= is_rtl() ? 'موعد مرن (يقبل المتأخر مع وسم)' : 'Soft Deadline (Accepts Late with Tag)' ?></option>
                                    <option value="hard"><?= is_rtl() ? 'موعد صارم (إغلاق البوابة نهائياً)' : 'Hard Deadline (Locks Uploads)' ?></option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'تاريخ الإغلاق الصارم (Cutoff)' : 'Hard Cutoff Date' ?></label>
                                <input type="date" name="cut_off_date" class="form-control" value="<?= date('Y-m-d', strtotime('+10 days')) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'إرفاق ورقة عمل / ملف مرجعي (اختياري)' : 'Worksheet / Attachment (Optional)' ?></label>
                                <input type="file" name="assignment_file" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-white py-2 px-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold px-4">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= is_rtl() ? 'نشر التكليف للطلاب' : 'Publish Assignment' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Assignment Modal -->
    <?php if ($active_assignment): ?>
    <div class="modal fade" id="editAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit_assignment">
                    <input type="hidden" name="assignment_id" value="<?= $active_assignment['assignment_id'] ?>">

                    <div class="modal-header bg-dark text-white py-3 px-4">
                        <h5 class="modal-title fw-bold fs-6 d-flex align-items-center">
                            <i class="fa-solid fa-pen-to-square me-2 text-warning"></i>
                            <span><?= is_rtl() ? 'تعديل بيانات التكليف الدراسي' : 'Edit Assignment Details' ?></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4 bg-light">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الفصل الدراسي' : 'Target Class' ?> <span class="text-danger">*</span></label>
                                <select name="class_id" class="form-select fw-bold" required>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_id'] ?>" <?= ((int)$active_assignment['class_id'] === (int)$c['class_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['class_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'المادة' : 'Subject' ?> <span class="text-danger">*</span></label>
                                <select name="subject_id" class="form-select fw-bold" required>
                                    <?php foreach ($subjects as $s): ?>
                                        <option value="<?= $s['subject_id'] ?>" <?= ((int)$active_assignment['subject_id'] === (int)$s['subject_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['subject_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'العنوان' : 'Title' ?> <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control fw-bold" value="<?= htmlspecialchars($active_assignment['title']) ?>" required>
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'التعليمات' : 'Instructions' ?></label>
                                <textarea name="instructions" class="form-control" rows="3"><?= htmlspecialchars($active_assignment['instructions'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'تاريخ التسليم النهائي' : 'Due Date' ?> <span class="text-danger">*</span></label>
                                <input type="date" name="due_date" class="form-control fw-bold" value="<?= htmlspecialchars($active_assignment['due_date']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'استبدال الملف المرفق (اختياري)' : 'Replace Attachment (Optional)' ?></label>
                                <input type="file" name="assignment_file" class="form-control">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-white py-2 px-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold px-4">
                            <i class="fa-solid fa-check me-1"></i> <?= is_rtl() ? 'حفظ التعديلات' : 'Save Changes' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteAssignmentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_assignment">
                    <input type="hidden" name="assignment_id" id="deleteAssignmentId" value="0">

                    <div class="modal-header bg-danger text-white py-3 px-4">
                        <h5 class="modal-title fw-bold fs-6 d-flex align-items-center">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i>
                            <span><?= is_rtl() ? 'تأكيد حذف التكليف الدراسي' : 'Confirm Assignment Deletion' ?></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light">
                        <p class="text-muted mb-2">
                            <?= is_rtl() ? 'هل أنت متأكد من رغبتك في حذف هذا التكليف؟ سيتم حذف جميع تسليمات الطلاب والدرجات المرتبطة به نهائياً.' : 'Are you sure you want to delete this assignment? All associated student submissions and marks will be permanently removed.' ?>
                        </p>
                        <div class="fw-bold text-dark p-2 bg-white rounded border" id="deleteAssignmentTitleDisplay"></div>
                    </div>
                    <div class="modal-footer bg-white py-2 px-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-danger btn-sm fw-bold px-3">
                            <i class="fa-solid fa-trash me-1"></i> <?= is_rtl() ? 'نعم، احذف التكليف' : 'Yes, Delete' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Dedicated Student Grade & Revision Modal -->
    <?php if ($active_assignment): ?>
    <div class="modal fade" id="gradeStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <form method="POST">
                    <input type="hidden" name="action" value="grade_single_submission">
                    <input type="hidden" name="assignment_id" value="<?= (int)($active_assignment['assignment_id'] ?? 0) ?>">
                    <input type="hidden" name="student_id" id="modalStudentId" value="0">
                    <input type="hidden" name="submission_id" id="modalSubmissionId" value="0">

                    <div class="modal-header bg-dark text-white py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-clipboard-check text-warning fs-5"></i>
                            <div>
                                <h5 class="modal-title fw-bold fs-6 mb-0" id="modalStudentNameDisplay">Student Name</h5>
                                <span class="badge bg-secondary font-monospace" id="modalAdmissionDisplay" style="font-size: 0.7rem;">-</span>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body p-4 bg-light">
                        <!-- Student Submission Inspection Box -->
                        <div class="card border-0 shadow-xs mb-3 rounded-3">
                            <div class="card-header bg-white py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center">
                                <span><i class="fa-solid fa-file-invoice text-primary me-1"></i> <?= is_rtl() ? 'حل وإرفاق الطالب المستلم:' : 'Student Submitted Work:' ?></span>
                                <span id="modalSubmissionTime" class="text-muted small" style="font-size: 0.72rem;"></span>
                            </div>
                            <div class="card-body p-3">
                                <div id="modalSolutionText" class="p-2.5 bg-light rounded text-dark small border mb-2" style="white-space: pre-line; max-height: 140px; overflow-y: auto;">
                                    <?= is_rtl() ? 'لا يوجد نص مكتوب.' : 'No written text provided.' ?>
                                </div>
                                <div id="modalFileContainer" style="display: none;">
                                    <a id="modalFileLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary fw-bold">
                                        <i class="fa-solid fa-download me-1"></i> <?= is_rtl() ? 'تحميل ومعاينة الملف المرفق من الطالب' : 'Download & View Student Attachment' ?>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- Grading & Review Decision Section -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'قرار التقييم والاعتماد' : 'Review Decision / Status' ?> <span class="text-danger">*</span></label>
                                <select name="review_status" id="modalReviewStatus" class="form-select fw-bold" onchange="onModalStatusChange(this)">
                                    <option value="Accepted"><?= is_rtl() ? '✅ معتمد وناجح (Accepted & Graded)' : '✅ Accepted & Graded' ?></option>
                                    <option value="Needs Revision"><?= is_rtl() ? '⚠️ مطلوب تعديل وإعادة تسليم (Needs Revision)' : '⚠️ Needs Revision (Redo Required)' ?></option>
                                    <option value="Pending"><?= is_rtl() ? '⏳ قيد المراجعة (Pending Evaluation)' : '⏳ Pending Evaluation' ?></option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الدرجة / التقييم الممنوح' : 'Grade / Score Awarded' ?></label>
                                <input type="text" name="grade" id="modalGradeInput" class="form-control fw-bold" placeholder="e.g. 95/100 or A+">
                            </div>

                            <div class="col-12">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <label class="form-label small fw-bold text-dark mb-0" id="modalFeedbackLabel">
                                        <i class="fa-solid fa-comment-dots text-primary me-1"></i>
                                        <span><?= is_rtl() ? 'توجيهات التعديل وملاحظات المعلم' : 'Revision Instructions & Teacher Feedback' ?></span>
                                    </label>
                                    <span class="text-muted small" style="font-size: 0.72rem;"><?= is_rtl() ? 'تظهر مباشرة في بوابة الطالب وولي الأمر' : 'Visible in Student & Parent Portals' ?></span>
                                </div>
                                <textarea name="teacher_feedback" id="modalFeedbackInput" class="form-control" rows="3" 
                                          placeholder="<?= is_rtl() ? 'اكتب بالتفصيل التوجيهات أو أرقام الأسئلة المطلوب إعادة حلها أو الملاحظات الإيجابية...' : 'Clearly describe what sections or questions need to be corrected or redone, or provide positive encouragement...' ?>"></textarea>

                                <div id="modalRevisionAlert" class="alert alert-danger py-1.5 px-2 mt-2 mb-0 d-none align-items-center gap-1.5 small">
                                    <i class="fa-solid fa-triangle-exclamation"></i>
                                    <span><?= is_rtl() ? 'تنبيه: سيتم إشعار الطالب بطلب إعادة التسليم مع هذه التوجيهات.' : 'Note: Student will receive a Revision alert with these specific redo instructions.' ?></span>
                                </div>

                                <!-- Preset Chips in Modal -->
                                <div class="d-flex flex-wrap gap-1 mt-2 align-items-center">
                                    <span class="text-muted small fw-bold me-1"><?= is_rtl() ? 'إدراج توجيه سريع:' : 'Quick Inserts:' ?></span>
                                    <button type="button" class="preset-chip" onclick="insertModalPreset('<?= is_rtl() ? 'يرجى إعادة حل السؤال وإظهار خطوات الحل كاملة.' : 'Please redo question #3 and show full working steps.' ?>', true)">
                                        <i class="fa-solid fa-rotate-left text-danger"></i> <?= is_rtl() ? 'إعادة حل السؤال مع الخطوات' : 'Redo Problem with Steps' ?>
                                    </button>
                                    <button type="button" class="preset-chip" onclick="insertModalPreset('<?= is_rtl() ? 'التسليم غير مكتمل - يرجى حل كافة الأسئلة المتبقية في ورقة العمل وإعادة التسليم.' : 'Incomplete submission - please complete all remaining questions in the worksheet and turn in again.' ?>', true)">
                                        <i class="fa-solid fa-list-check text-warning"></i> <?= is_rtl() ? 'استكمال باقي الأسئلة' : 'Complete All Questions' ?>
                                    </button>
                                    <button type="button" class="preset-chip" onclick="insertModalPreset('<?= is_rtl() ? 'الملف المرفق غير واضح أو يصعب قراءته. يرجى إعادة إرفاق صورة واضحة أو مستند PDF ممسوح ضوئياً.' : 'Uploaded document is unreadable or blurry. Please re-upload a clear photo or PDF scan.' ?>', true)">
                                        <i class="fa-solid fa-camera text-primary"></i> <?= is_rtl() ? 'إعادة إرفاق مستند واضح' : 'Clearer Scan/Photo' ?>
                                    </button>
                                    <button type="button" class="preset-chip chip-accepted" onclick="insertModalPreset('<?= is_rtl() ? 'إجابات ممتازة وخطوات نموذجية منظمة. أحسنت!' : 'Outstanding work! Very well structured and accurate.' ?>', false)">
                                        <i class="fa-solid fa-star text-success"></i> <?= is_rtl() ? 'إشادة وتميز' : 'Outstanding' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-white py-2 px-4">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold px-4">
                            <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ التقييم وإرسال التوجيهات' : 'Save Evaluation & Instructions' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Hidden Form for Continuous Assessment (CA) Gradebook Sync -->
    <?php if ($active_assignment): ?>
    <form id="syncGradebookForm" method="POST" action="" class="d-none" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من مزامنة درجات أعمال السنة مع سجل الدرجات والامتحانات؟' : 'Synchronize Continuous Assessment (CA) coursework into the official gradebook?' ?>');">
        <input type="hidden" name="action" value="sync_gradebook_ca">
        <input type="hidden" name="assignment_id" value="<?= (int)$active_assignment['assignment_id'] ?>">
        <input type="hidden" name="class_id" value="<?= (int)$active_assignment['class_id'] ?>">
        <input type="hidden" name="subject_id" value="<?= (int)$active_assignment['subject_id'] ?>">
        <input type="hidden" name="term" value="Term 1">
        <input type="hidden" name="academic_year" value="2025/2026">
    </form>
    <?php endif; ?>

    <!-- In-line Document Preview Modal -->
    <div class="modal fade" id="docPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground" id="docPreviewTitle">
                        <i class="fa-solid fa-file-invoice text-primary me-2"></i><?= is_rtl() ? 'معاينة حل الطالب' : 'Student Submission Preview' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" id="docPreviewBody" style="min-height: 500px; max-height: 80vh; overflow: auto;">
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <a id="docPreviewDownloadBtn" href="#" download class="btn btn-primary btn-sm rounded-lg">
                        <i class="fa-solid fa-download me-1"></i> <?= is_rtl() ? 'تحميل الملف' : 'Download File' ?>
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إغلاق' : 'Close' ?></button>
                </div>
            </div>
        </div>
    </div>

    <?= render_platform_footer() ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewStudentFile(studentName, fileUrl) {
            document.getElementById('docPreviewTitle').innerText = (<?= json_encode(is_rtl() ? 'معاينة ملف: ' : 'Submission: ') ?>) + studentName;
            document.getElementById('docPreviewDownloadBtn').href = fileUrl;
            const body = document.getElementById('docPreviewBody');
            body.innerHTML = '';

            const ext = fileUrl.split('.').pop().toLowerCase();
            if (ext === 'pdf') {
                body.innerHTML = `<iframe src="${fileUrl}" style="width:100%; height:75vh; border:none;"></iframe>`;
            } else if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) {
                body.innerHTML = `<div class="text-center p-4"><img src="${fileUrl}" class="img-fluid rounded shadow-sm" style="max-height:75vh;"></div>`;
            } else {
                body.innerHTML = `
                    <div class="p-5 text-center text-muted-foreground">
                        <i class="fa-solid fa-file-lines fa-3x mb-3 text-primary"></i>
                        <h5>${studentName}</h5>
                        <p class="small">In-line preview is not supported for .${ext} files. Please download to view.</p>
                        <a href="${fileUrl}" download class="btn btn-primary rounded-lg">Download File</a>
                    </div>
                `;
            }

            new bootstrap.Modal(document.getElementById('docPreviewModal')).show();
        }

        function confirmDeleteAssignment(id, title) {
            document.getElementById('deleteAssignmentId').value = id;
            document.getElementById('deleteAssignmentTitleDisplay').innerText = title;
            var delModal = new bootstrap.Modal(document.getElementById('deleteAssignmentModal'));
            delModal.show();
        }

        function onReviewStatusChange(selectElem, studentId) {
            const status = selectElem.value;
            const fbTextarea = document.getElementById('feedback_' + studentId);
            const revHint = document.getElementById('revision_hint_' + studentId);

            selectElem.className = 'form-select form-select-sm fw-bold ';
            if (status === 'Accepted') {
                selectElem.classList.add('border-success', 'text-success');
                if (fbTextarea) fbTextarea.classList.remove('revision-mode');
                if (revHint) revHint.classList.remove('show');
            } else if (status === 'Needs Revision') {
                selectElem.classList.add('border-danger', 'text-danger', 'bg-danger', 'bg-opacity-10');
                if (fbTextarea) {
                    fbTextarea.classList.add('revision-mode');
                    fbTextarea.focus();
                }
                if (revHint) revHint.classList.add('show');
            } else {
                selectElem.classList.add('border-warning', 'text-warning');
                if (fbTextarea) fbTextarea.classList.remove('revision-mode');
                if (revHint) revHint.classList.remove('show');
            }
        }

        function insertPresetFeedback(studentId, text, isRevision) {
            const fbTextarea = document.getElementById('feedback_' + studentId);
            const statusSelect = document.getElementById('status_' + studentId);
            if (!fbTextarea) return;

            if (fbTextarea.value.trim() === '') {
                fbTextarea.value = text;
            } else if (!fbTextarea.value.includes(text)) {
                fbTextarea.value = fbTextarea.value.trim() + ' ' + text;
            }

            if (isRevision && statusSelect && statusSelect.value !== 'Needs Revision') {
                statusSelect.value = 'Needs Revision';
                onReviewStatusChange(statusSelect, studentId);
            } else if (!isRevision && statusSelect && statusSelect.value === 'Pending') {
                statusSelect.value = 'Accepted';
                onReviewStatusChange(statusSelect, studentId);
            }
            fbTextarea.focus();
        }

        function openStudentGradeModal(data) {
            document.getElementById('modalStudentId').value = data.student_id;
            document.getElementById('modalSubmissionId').value = data.submission_id || 0;
            document.getElementById('modalStudentNameDisplay').innerText = data.student_name;
            document.getElementById('modalAdmissionDisplay').innerText = data.admission_no || '-';
            document.getElementById('modalGradeInput').value = data.grade || '';
            document.getElementById('modalFeedbackInput').value = data.teacher_feedback || '';
            
            const statusSelect = document.getElementById('modalReviewStatus');
            statusSelect.value = data.review_status || 'Accepted';
            onModalStatusChange(statusSelect);

            const solText = document.getElementById('modalSolutionText');
            if (data.has_submitted) {
                solText.innerText = data.submission_text || (<?= json_encode(is_rtl() ? 'لا توجد ملاحظات نصية مرفقة.' : 'No text submission notes.') ?>);
                document.getElementById('modalSubmissionTime').innerText = data.submitted_at ? (<?= json_encode(is_rtl() ? 'تم التسليم في: ' : 'Turned In: ') ?> + data.submitted_at) : '';
            } else {
                solText.innerHTML = '<span class="text-muted"><i class="fa-solid fa-circle-minus me-1"></i> ' + (<?= json_encode(is_rtl() ? 'لم يقم الطالب بتسليم الواجب بعد.' : 'Student has not turned in work yet.') ?>) + '</span>';
                document.getElementById('modalSubmissionTime').innerText = <?= json_encode(is_rtl() ? 'غير مسلّم' : 'Not Turned In') ?>;
            }

            const fileContainer = document.getElementById('modalFileContainer');
            const fileLink = document.getElementById('modalFileLink');
            if (data.student_file) {
                fileLink.href = '../../' + data.student_file;
                fileContainer.style.display = 'block';
            } else {
                fileContainer.style.display = 'none';
            }

            const modal = new bootstrap.Modal(document.getElementById('gradeStudentModal'));
            modal.show();
        }

        function onModalStatusChange(selectElem) {
            const revAlert = document.getElementById('modalRevisionAlert');
            const fbInput = document.getElementById('modalFeedbackInput');
            if (selectElem.value === 'Needs Revision') {
                if (revAlert) {
                    revAlert.classList.remove('d-none');
                    revAlert.classList.add('d-flex');
                }
                if (fbInput) {
                    fbInput.style.borderColor = '#ef4444';
                    fbInput.style.backgroundColor = '#fef2f2';
                }
            } else {
                if (revAlert) {
                    revAlert.classList.remove('d-flex');
                    revAlert.classList.add('d-none');
                }
                if (fbInput) {
                    fbInput.style.borderColor = '';
                    fbInput.style.backgroundColor = '';
                }
            }
        }

        function insertModalPreset(text, isRevision) {
            const fbInput = document.getElementById('modalFeedbackInput');
            const statusSelect = document.getElementById('modalReviewStatus');
            if (!fbInput) return;

            if (fbInput.value.trim() === '') {
                fbInput.value = text;
            } else if (!fbInput.value.includes(text)) {
                fbInput.value = fbInput.value.trim() + ' ' + text;
            }

            if (isRevision && statusSelect && statusSelect.value !== 'Needs Revision') {
                statusSelect.value = 'Needs Revision';
                onModalStatusChange(statusSelect);
            } else if (!isRevision && statusSelect && statusSelect.value === 'Pending') {
                statusSelect.value = 'Accepted';
                onModalStatusChange(statusSelect);
            }
            fbInput.focus();
        }
    </script>
</body>
</html>
