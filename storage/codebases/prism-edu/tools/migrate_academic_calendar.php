<?php
require_once __DIR__ . '/../config/db.php';

echo "=== MIGRATING ACADEMIC CALENDAR TABLE ===\n\n";

$sql = "
CREATE TABLE IF NOT EXISTS `academic_calendar` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_type` enum('Term Start','Term End','Holiday','School Closure','Exam Period','General Event') NOT NULL DEFAULT 'General Event',
  `academic_year` varchar(50) NOT NULL DEFAULT '2025/2026',
  `term` varchar(50) DEFAULT 'Term 1',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `color_code` varchar(20) DEFAULT '#0d6efd',
  `target_audience` varchar(100) DEFAULT 'all',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_id`),
  KEY `idx_academic_year` (`academic_year`),
  KEY `idx_dates` (`start_date`, `end_date`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$pdo->exec($sql);
echo "[OK] Table `academic_calendar` created/verified.\n";

// Check if any events exist, if not, seed realistic events for current academic year
$count = $pdo->query("SELECT COUNT(*) FROM `academic_calendar`")->fetchColumn();

if ($count == 0) {
    $current_year = '2025/2026';
    // Let's get academic year from settings if available
    $setStmt = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key = 'academic_year' LIMIT 1");
    $yearSetting = $setStmt->fetchColumn();
    if (!empty($yearSetting)) {
        $current_year = $yearSetting;
    }

    $now = date('Y-m-d');
    $curY = (int)date('Y');
    $curM = (int)date('m');

    $seedEvents = [
        [
            'title' => 'Term 1 Official Opening & Orientation',
            'description' => 'Commencement of academic instruction and student welcoming sessions.',
            'event_type' => 'Term Start',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('-30 days')),
            'end_date' => date('Y-m-d', strtotime('-30 days')),
            'is_closed' => 0,
            'color_code' => '#10b981', // green
            'target_audience' => 'all'
        ],
        [
            'title' => 'Mid-Term Break & Staff Development',
            'description' => 'Instructional pause for teachers development workshops and student mid-term recess.',
            'event_type' => 'Holiday',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('+10 days')),
            'end_date' => date('Y-m-d', strtotime('+12 days')),
            'is_closed' => 1,
            'color_code' => '#f59e0b', // amber
            'target_audience' => 'all'
        ],
        [
            'title' => 'First Semester Final Examination Block',
            'description' => 'Comprehensive 40/60 assessment window across all grade levels.',
            'event_type' => 'Exam Period',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('+35 days')),
            'end_date' => date('Y-m-d', strtotime('+45 days')),
            'is_closed' => 0,
            'color_code' => '#8b5cf6', // purple
            'target_audience' => 'all'
        ],
        [
            'title' => 'Term 1 Official Conclusion & Grade Publication',
            'description' => 'Report card compilation and parent consultation conferences.',
            'event_type' => 'Term End',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('+50 days')),
            'end_date' => date('Y-m-d', strtotime('+50 days')),
            'is_closed' => 0,
            'color_code' => '#ef4444', // red
            'target_audience' => 'all'
        ],
        [
            'title' => 'National Independence Public Holiday',
            'description' => 'Official national holiday observance. All school facilities closed.',
            'event_type' => 'School Closure',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('+22 days')),
            'end_date' => date('Y-m-d', strtotime('+22 days')),
            'is_closed' => 1,
            'color_code' => '#dc2626', // dark red
            'target_audience' => 'all'
        ],
        [
            'title' => 'Annual STEM & Science Fair Showcase',
            'description' => 'Student project demonstrations, robotics exhibits, and parent invitation day.',
            'event_type' => 'General Event',
            'academic_year' => $current_year,
            'term' => 'Term 1',
            'start_date' => date('Y-m-d', strtotime('+18 days')),
            'end_date' => date('Y-m-d', strtotime('+18 days')),
            'is_closed' => 0,
            'color_code' => '#0ea5e9', // sky blue
            'target_audience' => 'all'
        ]
    ];

    $insStmt = $pdo->prepare("
        INSERT INTO `academic_calendar` 
        (`title`, `description`, `event_type`, `academic_year`, `term`, `start_date`, `end_date`, `is_closed`, `color_code`, `target_audience`, `created_by`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");

    foreach ($seedEvents as $evt) {
        $insStmt->execute([
            $evt['title'],
            $evt['description'],
            $evt['event_type'],
            $evt['academic_year'],
            $evt['term'],
            $evt['start_date'],
            $evt['end_date'],
            $evt['is_closed'],
            $evt['color_code'],
            $evt['target_audience']
        ]);
    }
    echo "[OK] Seeded " . count($seedEvents) . " benchmark academic milestones for $current_year.\n";
} else {
    echo "[INFO] `academic_calendar` already contains $count events.\n";
}

echo "\n=== MIGRATION COMPLETED ===\n";
