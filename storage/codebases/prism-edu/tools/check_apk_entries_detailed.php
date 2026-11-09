<?php
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/../android/app/build/outputs/apk/debug/app-debug.apk') === TRUE) {
    echo "Total files in APK: " . $zip->numFiles . "\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/(launcher|mipmap|icon|app_)/i', $name)) {
            $stat = $zip->statIndex($i);
            echo "   MATCH: {$stat['name']} ({$stat['size']} bytes)\n";
        }
    }
    $zip->close();
}
