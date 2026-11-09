<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Strict Security check: Must be admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../../dashboard.php");
    exit();
}

$type = $_GET['type'] ?? 'students';

// UTF-8 BOM for Excel compatibility
$bom = "\xEF\xBB\xBF";

switch ($type) {
    case 'students':
        $filename = "template_students.csv";
        $headers = ["admission_no", "full_name", "gender", "dob", "class_name", "phone", "email", "parent_name", "parent_phone", "parent_email"];
        $rows = [
            ["STD-1001", "Ahmed Ali", "Male", "2010-05-15", "Grade 1 (A)", "+249912345678", "ahmed@example.com", "Ali Hassan", "+249987654321", "ali.parent@example.com"],
            ["STD-1002", "Fatima Omer", "Female", "2011-08-22", "Grade 1 (A)", "+249911223344", "fatima@example.com", "Omer Khalid", "+249955667788", "omer.parent@example.com"],
            ["STD-1003", "Johnathan Smith", "Male", "2010-11-03", "Grade 2", "+249922334455", "john.s@example.com", "Robert Smith", "+249966778899", "robert@example.com"]
        ];
        break;

    case 'teachers':
        $filename = "template_teachers.csv";
        $headers = ["full_name", "username", "email", "phone", "assigned_classes", "assigned_subjects"];
        $rows = [
            ["Prof. Sarah Jenkins", "sarah.j", "sarah.jenkins@school.edu", "+249900112233", "Grade 1 (A), Grade 2", "Mathematics, Physics"],
            ["Dr. Michael Vance", "m.vance", "m.vance@school.edu", "+249900445566", "Grade 3", "English, History"],
            ["Ustaz Ibrahim Tariq", "ibrahim.t", "ibrahim.t@school.edu", "+249900778899", "Grade 1 (A)", "Biology"]
        ];
        break;

    case 'classes':
        $filename = "template_classes.csv";
        $headers = ["class_name", "section"];
        $rows = [
            ["Grade 1", "A"],
            ["Grade 1", "B"],
            ["Grade 2", "A"],
            ["Grade 3", ""]
        ];
        break;

    case 'subjects':
        $filename = "template_subjects.csv";
        $headers = ["subject_name", "subject_code"];
        $rows = [
            ["Mathematics", "MATH101"],
            ["English Language", "ENG101"],
            ["Biology", "BIO101"],
            ["Physics", "PHY101"],
            ["Chemistry", "CHEM101"],
            ["History", "HIST101"],
            ["Computer Studies", "CS101"]
        ];
        break;

    case 'grades':
        $filename = "template_grades.csv";
        $headers = ["admission_no", "subject_code_or_name", "term", "academic_year", "ca_score", "exam_score"];
        $rows = [
            ["STD-1001", "Mathematics", "Term 1", "2026", "35.5", "54.0"],
            ["STD-1001", "English Language", "Term 1", "2026", "38.0", "56.5"],
            ["STD-1002", "Mathematics", "Term 1", "2026", "32.0", "48.0"],
            ["STD-1003", "Physics", "Term 1", "2026", "39.0", "58.0"]
        ];
        break;

    case 'fees':
        $filename = "template_fees.csv";
        $headers = ["admission_no", "invoice_number", "term", "academic_year", "total_amount", "status", "due_date"];
        $rows = [
            ["STD-1001", "INV-2026-001", "Term 1", "2026", "500.00", "Paid", "2026-09-30"],
            ["STD-1002", "INV-2026-002", "Term 1", "2026", "500.00", "Partial", "2026-09-30"],
            ["STD-1003", "INV-2026-003", "Term 1", "2026", "650.00", "Unpaid", "2026-10-15"]
        ];
        break;

    default:
        die("Invalid template type.");
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fwrite($output, $bom); // write UTF-8 BOM
fputcsv($output, $headers);

foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit();
