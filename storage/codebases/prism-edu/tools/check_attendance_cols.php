<?php
require 'core/init.php';
print_r($pdo->query("DESCRIBE student_attendance")->fetchAll(PDO::FETCH_COLUMN));
