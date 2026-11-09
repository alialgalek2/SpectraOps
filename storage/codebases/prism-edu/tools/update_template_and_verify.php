<?php
$sourceReleaseApk = __DIR__ . '/../android/app/build/outputs/apk/release/app-release-unsigned.apk';
$templateApk = __DIR__ . '/../storage/app_template/template-unsigned.apk';

if (!file_exists($sourceReleaseApk)) {
    die("Source release APK not found at: $sourceReleaseApk\n");
}

echo "Source release APK size: " . filesize($sourceReleaseApk) . " bytes\n";
if (!copy($sourceReleaseApk, $templateApk)) {
    die("Failed to copy to template path: $templateApk\n");
}

echo "Successfully updated template APK at: $templateApk (" . filesize($templateApk) . " bytes)\n";

// Inspect template APK entries
$zip = new ZipArchive();
if ($zip->open($templateApk) === TRUE) {
    echo "\n=== Entries in updated template-unsigned.apk ===\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (stripos($name, 'mipmap') !== false || stripos($name, 'ic_launcher') !== false) {
            $stat = $zip->statIndex($i);
            echo " - {$stat['name']} ({$stat['size']} bytes)\n";
        }
    }
    $zip->close();
} else {
    echo "Failed to open zip archive.\n";
}
