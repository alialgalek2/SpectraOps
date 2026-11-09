<?php
require_once __DIR__ . '/../core/init.php';

echo "Courses: " . $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn() . "\n";
echo "Units: " . $pdo->query("SELECT COUNT(*) FROM curriculum_units")->fetchColumn() . "\n";
echo "Topics: " . $pdo->query("SELECT COUNT(*) FROM curriculum_topics")->fetchColumn() . "\n";
echo "Materials: " . $pdo->query("SELECT COUNT(*) FROM course_materials")->fetchColumn() . "\n";
echo "Discussions: " . $pdo->query("SELECT COUNT(*) FROM course_discussions")->fetchColumn() . "\n";
echo "Replies: " . $pdo->query("SELECT COUNT(*) FROM course_discussion_replies")->fetchColumn() . "\n";
