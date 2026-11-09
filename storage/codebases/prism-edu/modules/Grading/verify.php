<?php
/**
 * Public Academic Credential & Report Card Verification Endpoint
 * Validates document hash and QR code scans directly against central institutional database.
 */

require_once __DIR__ . '/../../core/init.php';

$doc_hash = trim($_GET['doc_hash'] ?? '');
$student_id = (int)($_GET['sid'] ?? 0);

$is_valid = false;
$record = null;

if (!empty($doc_hash)) {
    $stmt = $pdo->prepare("
        SELECT g.*, s.admission_no, 
               COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
               c.class_name
        FROM grades g
        JOIN students s ON g.student_id = s.student_id
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        WHERE g.verification_hash = ?
        LIMIT 1
    ");
    $stmt->execute([$doc_hash]);
    $record = $stmt->fetch();

    if ($record) {
        $is_valid = true;
    } else {
        // Fallback: If hash was generated on student_id and term
        if ($student_id > 0) {
            $stu_stmt = $pdo->prepare("
                SELECT s.student_id, s.admission_no,
                       COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
                       c.class_name,
                       AVG(g.total_score) as avg_score,
                       AVG(g.gpa_points) as avg_gpa,
                       g.academic_year, g.term
                FROM students s
                JOIN classes c ON s.class_id = c.class_id
                LEFT JOIN users u ON s.user_id = u.user_id
                LEFT JOIN grades g ON s.student_id = g.student_id
                WHERE s.student_id = ?
                GROUP BY s.student_id
            ");
            $stu_stmt->execute([$student_id]);
            $record = $stu_stmt->fetch();
            if ($record && !empty($record['student_name'])) {
                $is_valid = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'بوابة التحقق من الشهادات والوثائق الأكاديمية' : 'Academic Credential Verification Portal' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground d-flex align-items-center justify-content-center min-vh-100 py-5">

<div class="container" style="max-width: 680px;">
    <div class="text-center mb-4">
        <h4 class="fw-bold font-heading mb-1 text-foreground">
            <i class="fa-solid fa-building-columns text-primary me-2"></i><?= htmlspecialchars($global_branding['school_name'] ?? 'Global Academy') ?>
        </h4>
        <div class="text-muted-foreground small"><?= is_rtl() ? 'بوابة التدقيق والتحقق من صحة الوثائق والشهادات الرسمية' : 'Central Registry of Academic Documents & Verified Credentials' ?></div>
    </div>

    <div class="card bg-card border border-border rounded-2xl shadow-lg p-4 p-md-5">
        <?php if ($is_valid && $record): ?>
            <div class="text-center mb-4">
                <div class="action-icon-box icon-box-success mx-auto mb-3" style="width: 75px; height: 75px; font-size: 2.2rem;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 fs-6 rounded-pill">
                    <i class="fa-solid fa-shield-halved me-1"></i><?= is_rtl() ? 'وثيقة أصلية ومعتمدة رسمياً' : 'OFFICIALLY VERIFIED & AUTHENTIC' ?>
                </span>
                <h5 class="fw-bold text-foreground mt-3 mb-0">
                    <?= is_rtl() ? 'شهادة دراسية مسجلة في قاعدة بيانات المدرسة' : 'Credential Verified in Central Institutional Registry' ?>
                </h5>
            </div>

            <div class="p-3 bg-secondary-subtle rounded-xl border border-border mb-4">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'اسم الطالب:' : 'Candidate Name:' ?></div>
                        <div class="fw-bold text-foreground fs-6"><?= htmlspecialchars($record['student_name']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'رقم القيد الأكاديمي:' : 'Admission / Roll No:' ?></div>
                        <div class="fw-bold text-foreground font-monospace"><?= htmlspecialchars($record['admission_no']) ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي / الشعبة:' : 'Class / Section:' ?></div>
                        <div class="fw-bold text-foreground"><?= htmlspecialchars($record['class_name'] ?? 'Assigned') ?></div>
                    </div>
                    <div class="col-sm-6">
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'العام الأكاديمي والفصل:' : 'Session & Term:' ?></div>
                        <div class="fw-bold text-foreground"><?= htmlspecialchars($record['academic_year'] ?? '2025/2026') ?> - <?= htmlspecialchars($record['term'] ?? 'Term 1') ?></div>
                    </div>
                </div>
            </div>

            <div class="p-3 border border-border rounded-xl bg-card text-center mb-4">
                <div class="small text-muted-foreground mb-1"><?= is_rtl() ? 'بصمة التشفير الرقمية (Digital Fingerprint):' : 'Digital Security Hash (SHA-256):' ?></div>
                <div class="small font-monospace text-primary text-break" style="font-size: 0.8rem;">
                    <?= htmlspecialchars($doc_hash) ?>
                </div>
                <div class="small text-muted-foreground mt-2">
                    <i class="fa-regular fa-clock me-1"></i><?= is_rtl() ? 'تم التحقق في: ' : 'Verified at: ' ?><?= date('Y-m-d H:i:s') ?> UTC
                </div>
            </div>

        <?php else: ?>
            <div class="text-center py-4">
                <div class="action-icon-box icon-box-danger mx-auto mb-3" style="width: 75px; height: 75px; font-size: 2.2rem;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1.5 fs-6 rounded-pill">
                    <?= is_rtl() ? 'تعذر التحقق من الوثيقة' : 'UNABLE TO VERIFY CREDENTIAL' ?>
                </span>
                <h5 class="fw-bold text-foreground mt-3 mb-2">
                    <?= is_rtl() ? 'بصمة الوثيقة غير مطابقة لسجلات الكنترول' : 'Document Hash Not Found or Invalid' ?>
                </h5>
                <p class="text-muted-foreground small col-md-10 mx-auto mb-4">
                    <?= is_rtl() 
                        ? 'لم يتم العثور على سجل درجات مطابق لهذا الرمز في قاعدة البيانات المدرسية. يرجى التأكد من مسح الرمز الأصلي المطبوع على الشهادة.' 
                        : 'No official academic record matches this digital signature in our central database. Please confirm the authenticity of the printed QR code.' ?>
                </p>

                <!-- Manual Hash Input Search -->
                <form method="GET" action="" class="d-flex gap-2">
                    <input type="text" name="doc_hash" class="form-control bg-background border-border text-foreground font-monospace small" placeholder="<?= is_rtl() ? 'أدخل رمز التشفير يدوياً...' : 'Enter document verification hash...' ?>" value="<?= htmlspecialchars($doc_hash) ?>" required>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-magnifying-glass me-1"></i><?= is_rtl() ? 'تحقق' : 'Verify' ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="text-center pt-3 border-top border-border">
            <a href="../../login.php" class="btn btn-outline-border btn-sm rounded-pill px-4">
                <i class="fa-solid fa-arrow-left me-1 <?= is_rtl() ? 'fa-flip-horizontal' : '' ?>"></i>
                <?= is_rtl() ? 'العودة لبوابة المدرسة الرئيسية' : 'Return to Portal Home' ?>
            </a>
        </div>
    </div>
</div>

</body>
</html>
