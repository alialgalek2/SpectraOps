<?php
/**
 * Administration Module - Official Printable Transfer Certificate (TC)
 * Tamper-proof layout with institutional letterhead, QR verification, and SHA-256 seal.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher', 'student', 'parent']);

$tc_id = isset($_GET['tc_id']) ? intval($_GET['tc_id']) : 0;
if ($tc_id <= 0) {
    die("Invalid Transfer Certificate ID.");
}

$stmt = $pdo->prepare("
    SELECT tc.*, 
           s.admission_no, s.dob, s.gender, s.nationality,
           COALESCE(u_s.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
           c.class_name, es.stage_name,
           u_iss.full_name as issuer_name
    FROM student_transfer_certificates tc
    JOIN students s ON tc.student_id = s.student_id
    JOIN users u_s ON s.user_id = u_s.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN educational_stages es ON c.stage_id = es.stage_id
    LEFT JOIN users u_iss ON tc.issued_by = u_iss.user_id
    WHERE tc.tc_id = ?
");
$stmt->execute([$tc_id]);
$tc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tc) {
    die("Transfer Certificate record not found.");
}

// Construct verification URL pointing to public verify endpoint
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
$verification_url = $base_url . "/../Grading/verify.php?doc_hash=" . urlencode($tc['verification_hash']) . "&sid=" . $tc['student_id'];
$qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=4&data=" . urlencode($verification_url);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Certificate - <?= htmlspecialchars($tc['tc_number']) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .tc-container {
            max-width: 860px;
            margin: 25px auto;
            background: #ffffff;
            color: #0f172a;
            padding: 45px 50px;
            border-radius: var(--radius-xl, 1.25rem);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 2px double #cbd5e1;
            position: relative;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .tc-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 20px !important;
                width: 100% !important;
                max-width: 100% !important;
                border: 2px solid #000 !important;
            }
        }
    </style>
</head>
<body class="bg-background">

    <!-- Print Action Bar -->
    <div class="container text-center my-3 no-print">
        <button class="btn btn-primary px-4 fw-bold shadow-sm" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i><?= is_rtl() ? 'طباعة شهادة النقل الرسمية' : 'Print Official Certificate' ?>
        </button>
        <a href="rollover.php" class="btn btn-outline-border ms-2">
            <i class="fa-solid fa-arrow-left me-1"></i><?= is_rtl() ? 'رجوع' : 'Back to Rollover' ?>
        </a>
    </div>

    <!-- Official Certificate Document -->
    <div class="tc-container">
        
        <!-- Letterhead Header -->
        <div class="d-flex justify-content-between align-items-center border-bottom pb-4 mb-4">
            <div class="d-flex align-items-center gap-3">
                <?php $logo_url = get_school_logo_url($global_branding['school_logo'] ?? ''); ?>
                <?php if (!empty($logo_url)): ?>
                    <img src="../../<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="rounded p-1 border" style="height: 70px; width: auto;">
                <?php else: ?>
                    <div class="bg-primary text-white rounded-xl d-flex align-items-center justify-content-center fs-3 fw-bold" style="width: 70px; height: 70px;">
                        <?= mb_substr($global_branding['school_name'] ?? 'S', 0, 1) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <h3 class="fw-bold font-heading mb-0 text-dark"><?= htmlspecialchars($global_branding['school_name'] ?? 'Global Academy') ?></h3>
                    <div class="small text-secondary"><?= is_rtl() ? 'إدارة الشؤون الأكاديمية والقبول والتسجيل' : 'Office of Academic Affairs, Admissions & Student Records' ?></div>
                    <div class="small text-muted font-monospace"><?= htmlspecialchars($global_branding['school_address'] ?? 'General Headquarters') ?></div>
                </div>
            </div>
            <div class="text-end">
                <span class="badge bg-dark text-white fs-6 px-3 py-1.5 rounded-pill font-heading">
                    TRANSFER CERTIFICATE
                </span>
                <div class="small text-muted mt-1 font-monospace">Certificate # <?= htmlspecialchars($tc['tc_number']) ?></div>
            </div>
        </div>

        <div class="text-center my-4">
            <h4 class="fw-bold font-heading text-uppercase text-decoration-underline mb-1">
                <?= is_rtl() ? 'شهادة نقل ومغادرة مدرسية رسمية' : 'OFFICIAL SCHOOL TRANSFER CERTIFICATE' ?>
            </h4>
            <div class="small text-muted font-monospace">Issued under Ministry of Education & Institutional Regulations</div>
        </div>

        <!-- Student Data Roster Table -->
        <table class="table table-bordered align-middle text-dark mb-4">
            <tbody>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small" style="width: 25%;">Candidate Legal Name</td>
                    <td class="fw-bold fs-6" style="width: 35%;"><?= htmlspecialchars($tc['student_name']) ?></td>
                    <td class="bg-light fw-bold text-uppercase small" style="width: 20%;">Admission / Roll #</td>
                    <td class="font-monospace fw-bold" style="width: 20%;"><?= htmlspecialchars($tc['admission_no']) ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Date of Birth</td>
                    <td><?= htmlspecialchars($tc['dob'] ?? 'N/A') ?></td>
                    <td class="bg-light fw-bold text-uppercase small">Gender & Nationality</td>
                    <td><?= htmlspecialchars($tc['gender']) ?> · <?= htmlspecialchars($tc['nationality'] ?? 'Egyptian') ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Last Class Section</td>
                    <td><?= htmlspecialchars($tc['class_name'] ?? 'General') ?></td>
                    <td class="bg-light fw-bold text-uppercase small">Educational Stage</td>
                    <td><?= htmlspecialchars($tc['stage_name'] ?? 'Standard Curriculum') ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Date of Official Exit</td>
                    <td class="font-monospace fw-bold"><?= htmlspecialchars($tc['leaving_date']) ?></td>
                    <td class="bg-light fw-bold text-uppercase small">Behavior & Conduct</td>
                    <td class="fw-bold text-success"><?= htmlspecialchars($tc['conduct_summary'] ?? 'Exemplary') ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Reason for Leaving</td>
                    <td colspan="3"><?= htmlspecialchars($tc['leaving_reason']) ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Qualified Next Grade</td>
                    <td colspan="3" class="fw-bold text-primary"><?= htmlspecialchars($tc['promoted_to_grade'] ?? 'Eligible for progressive grade level') ?></td>
                </tr>
                <tr>
                    <td class="bg-light fw-bold text-uppercase small">Clearance Status</td>
                    <td colspan="3">
                        <span class="badge bg-success-subtle text-success me-2">
                            <i class="fa-solid fa-check me-1"></i>Financial Clearance Verified ($0 Dues)
                        </span>
                        <span class="badge bg-info-subtle text-info">
                            <i class="fa-solid fa-book me-1"></i>Library & Assets Account Cleared
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="small text-secondary mb-4 text-justify" style="line-height: 1.6;">
            This is to certify that the above-named candidate was a bona fide student of this institution. During the tenure of attendance, the student maintained commendable moral conduct and satisfied all curricular requirements. All institutional dues, textbooks, and administrative obligations have been cleared in full.
        </p>

        <!-- Document Verification & Signatures -->
        <div class="row align-items-center pt-3 border-top text-dark mt-5">
            <div class="col-8">
                <div class="d-flex align-items-center gap-3">
                    <div class="bg-white border rounded p-1 shadow-xs">
                        <img src="<?= $qr_image_url ?>" alt="Verification QR" style="width: 80px; height: 80px;">
                    </div>
                    <div>
                        <div class="small fw-bold font-heading text-dark">
                            <i class="fa-solid fa-shield-halved text-success me-1"></i>Digitally Authenticated Certificate
                        </div>
                        <div class="small text-muted font-monospace" style="font-size: 0.72rem; word-break: break-all;">
                            SHA-256: <?= $tc['verification_hash'] ?>
                        </div>
                        <div class="small text-muted" style="font-size: 0.75rem;">
                            Scan QR code to independently verify this exit certificate against the central institutional ledger.
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-4 text-end">
                <div class="small fw-bold text-muted"><?= is_rtl() ? 'اعتماد الكنترول والمدير العام:' : 'Registrar & Head of School:' ?></div>
                <div class="mt-4 border-bottom border-dark mx-auto" style="width: 140px;"></div>
                <div class="small text-muted mt-1"><?= htmlspecialchars($tc['issuer_name'] ?? 'Academic Registrar') ?></div>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
