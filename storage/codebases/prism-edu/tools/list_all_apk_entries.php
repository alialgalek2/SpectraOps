<?php
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/../storage/app_template/template-unsigned.apk') === TRUE) {
    echo "Total entries: " . $zip->numFiles . "\n";
    for ($i = 0; $i < min(50, $zip->numFiles); $i++) {
        $stat = $zip->statIndex($i);
        echo " - " . $stat['name'] . "\n";
    }
    // Also search for any .png or .xml
    echo "\nAll PNGs in APK:\n";
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (substr($name, -4) === '.png' || stripos($name, 'res/') === 0) {
            echo "   * $name\n";
        }
    }
    $zip->close();
}
