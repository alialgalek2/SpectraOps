<?php
$files = glob(__DIR__ . '/../android/app/build/outputs/apk/release/*');
foreach ($files as $file) {
    echo basename($file) . " (" . filesize($file) . " bytes)\n";
}
