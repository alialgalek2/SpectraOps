<?php
/**
 * Administration Module - 360° Comprehensive Student Dossier
 * Integrates: Personal Legal Data, Health/Special Needs, Academic History,
 * Authorized Pickup Persons & School Transport Mapping.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$msg = "";
$msg_type = "info";

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
if ($student_id <= 0) {
    $first = $pdo->query("SELECT student_id FROM students WHERE is_deleted = 0 ORDER BY student_id ASC LIMIT 1")->fetchColumn();
    $student_id = $first ? intval($first) : 0;
}

// Handle Update Medical & Special Needs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_medical') {
    $blood_group       = trim($_POST['blood_group'] ?? '');
    $allergies         = trim($_POST['allergies'] ?? '');
    $conditions        = trim($_POST['medical_conditions'] ?? '');
    $emg_contact_name  = trim($_POST['emergency_contact_name'] ?? '');
    $emg_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');

    try {
        $stmt = $pdo->prepare("
            UPDATE students 
            SET blood_group = ?, allergies = ?, medical_conditions = ?, emergency_contact_name = ?, emergency_contact_phone = ? 
            WHERE student_id = ?
        ");
        $stmt->execute([$blood_group, $allergies, $conditions, $emg_contact_name, $emg_contact_phone, $student_id]);
        $msg = is_rtl() ? "تم تحديث السجل الطبي والاحتياجات الخاصة بنجاح!" : "Medical & special accommodations updated successfully!";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $msg = "Error updating medical dossier: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Handle Add Authorized Pickup Person
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_pickup_auth') {
    $p_name = trim($_POST['pickup_name'] ?? '');
    $p_rel  = trim($_POST['relationship'] ?? 'Guardian');
    $p_tel  = trim($_POST['phone'] ?? '');
    $p_nid  = trim($_POST['national_id'] ?? '');
    $is_emg = isset($_POST['is_emergency_contact']) ? 1 : 0;

    if (!empty($p_name) && !empty($p_tel)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO student_pickup_authorizations 
                (student_id, pickup_name, relationship, phone, national_id, is_emergency_contact, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ");
            $stmt->execute([$student_id, $p_name, $p_rel, $p_tel, $p_nid, $is_emg]);
            $msg = is_rtl() ? "تم تسجيل تفويض الاستلام بنجاح!" : "Pickup authorization recorded successfully!";
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error adding authorization: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle Update Transport Mapping
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_transport') {
    $route_name  = trim($_POST['route_name'] ?? '');
    $bus_no      = trim($_POST['bus_number'] ?? '');
    $pickup_pt   = trim($_POST['pickup_point'] ?? '');
    $dropoff_pt  = trim($_POST['dropoff_point'] ?? '');
    $driver_tel  = trim($_POST['driver_contact'] ?? '');

    try {
        $stmt = $pdo->prepare("
            INSERT INTO student_transport_mappings 
            (student_id, route_name, bus_number, pickup_point, dropoff_point, driver_contact, status) 
            VALUES (?, ?, ?, ?, ?, ?, 'Active')
            ON DUPLICATE KEY UPDATE 
            route_name = VALUES(route_name), bus_number = VALUES(bus_number), 
            pickup_point = VALUES(pickup_point), dropoff_point = VALUES(dropoff_point), 
            driver_contact = VALUES(driver_contact)
        ");
        $stmt->execute([$student_id, $route_name, $bus_no, $pickup_pt, $dropoff_pt, $driver_tel]);
        $msg = is_rtl() ? "تم تحديث بيانات النقل المدرسي والمحطات بنجاح!" : "Transport route & bus mapping updated successfully!";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $msg = "Error updating transport: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Student Data
$stmt = $pdo->prepare("
    SELECT s.*, u.username, u.email as user_email, u.full_name, c.class_name, es.stage_name, cp.campus_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN educational_stages es ON c.stage_id = es.stage_id
    LEFT JOIN campuses cp ON c.campus_id = cp.campus_id
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch Authorized Pickups
$pickups = [];
try {
    $pStmt = $pdo->prepare("SELECT * FROM student_pickup_authorizations WHERE student_id = ? ORDER BY auth_id DESC");
    $pStmt->execute([$student_id]);
    $pickups = $pStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Transport Info
$transport = null;
try {
    $tStmt = $pdo->prepare("SELECT * FROM student_transport_mappings WHERE student_id = ?");
    $tStmt->execute([$student_id]);
    $transport = $tStmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Student Roster for Switcher
$roster = $pdo->query("
    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE s.is_deleted = 0
    ORDER BY c.class_name ASC, student_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'الملف الشامل للطالب 360°' : '360° Comprehensive Student Dossier' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'الملف الأكاديمي والصحي الشامل للطالب' : '360° Student Dossier',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="admissions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-plus me-1 text-success"></i> ' . (is_rtl() ? 'القبول' : 'Admissions') . '</a>',
            '<a href="households.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-people-roof me-1 text-primary"></i> ' . (is_rtl() ? 'العائلات' : 'Households') . '</a>',
            '<a href="behavior.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-shield-heart me-1 text-warning"></i> ' . (is_rtl() ? 'السلوك' : 'Behavior') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Student Switcher Bar -->
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-md-6">
                    <label class="small text-muted-foreground fw-bold mb-1"><?= is_rtl() ? 'اختيار ملف الطالب:' : 'Select Student Profile:' ?></label>
                    <select name="student_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <?php foreach ($roster as $st): ?>
                            <option value="<?= $st['student_id'] ?>" <?= $st['student_id'] == $student_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['student_name']) ?> (<?= htmlspecialchars($st['class_name'] ?? 'General') ?> - #<?= htmlspecialchars($st['admission_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 text-md-end pt-3 pt-md-0">
                    <span class="badge bg-primary-subtle text-primary fs-6 px-3 py-2 rounded-xl">
                        <i class="fa-solid fa-id-badge me-1"></i> <?= htmlspecialchars($student['admission_no'] ?? 'N/A') ?>
                    </span>
                </div>
            </form>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-info me-2 fs-5"></i>
                <div><?= htmlspecialchars($msg) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$student): ?>
            <div class="alert alert-warning"><?= is_rtl() ? 'لم يتم العثور على ملف الطالب المحدد.' : 'Student profile not found.' ?></div>
        <?php else: ?>

            <div class="row g-4">
                <!-- Left Column: Student Dossier Profile & Health -->
                <div class="col-lg-4">
                    <!-- Identity Card -->
                    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4 text-center p-4">
                        <div class="avatar-badge avatar-badge-lg font-heading mx-auto mb-3" style="background: linear-gradient(135deg, var(--primary) 0%, #7c3aed 100%); color: #fff; width: 85px; height: 85px; font-size: 2.2rem; display: flex; align-items: center; justify-content: center; border-radius: 50%;">
                            <?= mb_substr($student['full_name'] ?? 'S', 0, 1) ?>
                        </div>
                        <h5 class="fw-bold font-heading text-foreground mb-1"><?= htmlspecialchars($student['full_name']) ?></h5>
                        <div class="text-muted-foreground small mb-2 font-monospace">
                            <?= htmlspecialchars($student['admission_no']) ?> · <?= htmlspecialchars($student['class_name'] ?? 'Unassigned') ?>
                        </div>
                        <span class="badge bg-success-subtle text-success rounded-pill px-3 py-1.5 mx-auto">
                            <?= htmlspecialchars($student['enrollment_status'] ?? 'Active') ?> Student
                        </span>

                        <div class="border-top border-border mt-4 pt-3 text-start small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted-foreground"><?= is_rtl() ? 'المرحلة والفرع:' : 'Stage & Campus:' ?></span>
                                <span class="fw-semibold text-foreground"><?= htmlspecialchars($student['stage_name'] ?? 'General') ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted-foreground"><?= is_rtl() ? 'تاريخ الميلاد:' : 'DOB:' ?></span>
                                <span class="fw-semibold text-foreground"><?= htmlspecialchars($student['dob'] ?? 'N/A') ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted-foreground"><?= is_rtl() ? 'النوع والجنسية:' : 'Gender & Nation:' ?></span>
                                <span class="fw-semibold text-foreground"><?= htmlspecialchars($student['gender']) ?> · <?= htmlspecialchars($student['nationality'] ?? 'Egyptian') ?></span>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted-foreground"><?= is_rtl() ? 'بطاقة RFID الذكية:' : 'Smart RFID Tag:' ?></span>
                                <span class="font-monospace text-primary"><?= htmlspecialchars($student['rfid_card_id'] ?? 'Unlinked') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Medical & Special Needs Card -->
                    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold font-heading mb-0 text-foreground">
                                <i class="fa-solid fa-heart-pulse text-danger me-2"></i><?= is_rtl() ? 'السجل الصحي والاحتياجات الخاصة' : 'Health & Medical Profile' ?>
                            </h6>
                            <span class="badge bg-danger-subtle text-danger font-monospace"><?= htmlspecialchars($student['blood_group'] ?? 'Unknown') ?></span>
                        </div>
                        <div class="card-body p-3">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_medical">
                                <div class="mb-2">
                                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'فصيلة الدم' : 'Blood Group' ?></label>
                                    <select name="blood_group" class="form-select form-select-sm bg-background border-border text-foreground">
                                        <?php foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bg): ?>
                                            <option value="<?= $bg ?>" <?= ($student['blood_group'] ?? '') === $bg ? 'selected' : '' ?>><?= $bg ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الحساسية الغذائية / الدوائية' : 'Allergies' ?></label>
                                    <input type="text" name="allergies" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($student['allergies'] ?? '') ?>" placeholder="e.g. Peanuts, Penicillin">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'حالات مزمنة / رعاية خاصة' : 'Chronic Conditions & Needs' ?></label>
                                    <textarea name="medical_conditions" class="form-control form-control-sm bg-background border-border text-foreground" rows="2" placeholder="Asthma, Diabetes, visual accommodations..."><?= htmlspecialchars($student['medical_conditions'] ?? '') ?></textarea>
                                </div>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'جهة اتصال طوارئ' : 'Emergency Contact' ?></label>
                                        <input type="text" name="emergency_contact_name" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($student['emergency_contact_name'] ?? '') ?>" placeholder="Name">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'هاتف الطوارئ' : 'Emergency Phone' ?></label>
                                        <input type="text" name="emergency_contact_phone" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($student['emergency_contact_phone'] ?? '') ?>" placeholder="+20...">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-outline-border btn-sm w-100 rounded-lg">
                                    <i class="fa-solid fa-floppy-disk me-1"></i><?= is_rtl() ? 'حفظ البيانات الصحية' : 'Save Health Record' ?>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Transport Mapping & Authorized Pickups -->
                <div class="col-lg-8">

                    <!-- School Bus & Transport Mapping -->
                    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold font-heading mb-0 text-foreground">
                                <i class="fa-solid fa-bus text-warning me-2"></i><?= is_rtl() ? 'بيانات النقل والحافلات المدرسية' : 'School Transport & Bus Route Mapping' ?>
                            </h6>
                            <span class="badge bg-warning-subtle text-warning font-monospace"><?= htmlspecialchars($transport['bus_number'] ?? 'Bus Not Assigned') ?></span>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_transport">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم خط السير / المسار' : 'Bus Route Name' ?></label>
                                        <input type="text" name="route_name" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($transport['route_name'] ?? 'Route 12 - New Cairo') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رقم الحافلة' : 'Bus Number / Plate' ?></label>
                                        <input type="text" name="bus_number" class="form-control bg-background border-border text-foreground font-monospace" value="<?= htmlspecialchars($transport['bus_number'] ?? 'BUS-04') ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'نقطة الركوب الصباحية' : 'Morning Pickup Point' ?></label>
                                        <input type="text" name="pickup_point" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($transport['pickup_point'] ?? '') ?>" placeholder="Intersection St. 90">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'نقطة النزول بعد الظهر' : 'Afternoon Drop-off Point' ?></label>
                                        <input type="text" name="dropoff_point" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($transport['dropoff_point'] ?? '') ?>" placeholder="Home Residence Gate">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'هاتف السائق والمشرف' : 'Driver / Supervisor Contact' ?></label>
                                        <input type="text" name="driver_contact" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($transport['driver_contact'] ?? '') ?>" placeholder="+20 100 000 0000">
                                    </div>
                                    <div class="col-md-6 d-flex align-items-end">
                                        <button type="submit" class="btn btn-warning fw-bold w-100 rounded-lg">
                                            <i class="fa-solid fa-floppy-disk me-1"></i><?= is_rtl() ? 'تحديث خط السير' : 'Update Transport Record' ?>
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Authorized Pickup Persons -->
                    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                            <h6 class="fw-bold font-heading mb-0 text-foreground">
                                <i class="fa-solid fa-id-card-clip text-primary me-2"></i><?= is_rtl() ? 'الأشخاص المفوضون بالاستلام من المدرسة' : 'Authorized Pickup Persons & Photo Security' ?>
                            </h6>
                            <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#pickupModal">
                                <i class="fa-solid fa-plus me-1"></i><?= is_rtl() ? 'إضافة مفوض' : 'Add Pickup Auth' ?>
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-secondary">
                                        <tr class="text-muted-foreground small text-uppercase font-bold">
                                            <th class="ps-3"><?= is_rtl() ? 'الاسم' : 'Authorized Name' ?></th>
                                            <th><?= is_rtl() ? 'صلة القرابة' : 'Relationship' ?></th>
                                            <th><?= is_rtl() ? 'الهاتف' : 'Phone' ?></th>
                                            <th><?= is_rtl() ? 'الرقم القومي' : 'National ID' ?></th>
                                            <th class="text-center"><?= is_rtl() ? 'حالة التفويض' : 'Status' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($pickups)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted-foreground small">
                                                    <?= is_rtl() ? 'لا يوجد مفوضون إضافيون مسجلون. أولياء الأمور فقط مصرح لهم.' : 'No additional authorized pickup individuals logged.' ?>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($pickups as $pk): ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold text-foreground"><?= htmlspecialchars($pk['pickup_name']) ?></div>
                                                        <?php if ($pk['is_emergency_contact']): ?>
                                                            <span class="badge bg-danger-subtle text-danger" style="font-size: 0.7rem;">Emergency Contact</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?= htmlspecialchars($pk['relationship']) ?></td>
                                                    <td class="font-monospace text-primary"><?= htmlspecialchars($pk['phone']) ?></td>
                                                    <td class="font-monospace text-muted-foreground"><?= htmlspecialchars($pk['national_id'] ?? '—') ?></td>
                                                    <td class="text-center">
                                                        <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($pk['status']) ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php endif; ?>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: Add Pickup Authorization -->
    <div class="modal fade" id="pickupModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="add_pickup_auth">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-id-card-clip me-2 text-primary"></i><?= is_rtl() ? 'تسجيل مفوض باستلام الطالب' : 'Add Pickup Authorization' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الاسم الرباعي' : 'Full Name' ?></label>
                            <input type="text" name="pickup_name" class="form-control bg-background border-border text-foreground" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'صلة القرابة' : 'Relationship' ?></label>
                                <input type="text" name="relationship" class="form-control bg-background border-border text-foreground" placeholder="e.g. Uncle, Driver, Nanny" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رقم الهاتف' : 'Contact Phone' ?></label>
                                <input type="text" name="phone" class="form-control bg-background border-border text-foreground" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الرقم القومي / إثبات الشخصية' : 'National ID / Passport No' ?></label>
                            <input type="text" name="national_id" class="form-control bg-background border-border text-foreground font-monospace">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_emergency_contact" value="1" id="isEmgCheck">
                            <label class="form-check-label small" for="isEmgCheck">
                                <?= is_rtl() ? 'اعتماد كجهة اتصال طوارئ أساسية' : 'Designate as priority emergency contact' ?>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'حفظ المفوض' : 'Save Authorization' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
