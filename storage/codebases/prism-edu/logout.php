<?php
require_once __DIR__ . '/core/init.php';

Auth::logout();

header("Location: login.php");
exit();