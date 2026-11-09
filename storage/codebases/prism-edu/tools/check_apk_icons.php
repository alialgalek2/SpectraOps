<?php
$apkPath = __DIR__ . '/../storage/app_template/template-unsigned.apk';
if (!file_exists($apkPath)) {
    echo "APK not found at $apkPath\n";
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($apkPath) === TRUE) {
    echo "Found entries in template-unsigned.apk:\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (stripos($stat['name'], 'mipmap') !== false || stripos($stat['name'], 'ic_launcher') !== false || stripos($stat['name'], 'icon') !== false) {
            echo " - {$stat['name']} ({$stat['size']} bytes)\n";
        }
    }
    $zip->close();
} else {
    echo "Failed to open zip\n";
}
