<?php
$pages = [
    'modules/LMS/index.php',
    'modules/LMS/courses.php',
    'modules/LMS/materials.php',
    'modules/LMS/assignments.php',
    'modules/LMS/discussions.php',
    'modules/LMS/announcements.php',
    'modules/LMS/student_portal.php',
    'modules/LMS/parent_portal.php',
    'docs.php'
];

foreach ($pages as $p) {
    $ch = curl_init('http://localhost:8080/school_system/' . $p);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, __DIR__ . '/../cookie_admin.txt');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "$p -> HTTP $code (Bytes: " . strlen($res) . ")\n";
    curl_close($ch);
}
