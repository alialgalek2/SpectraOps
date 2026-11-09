<?php
$zip = new ZipArchive();
if ($zip->open(__DIR__ . '/../storage/app_template/template-unsigned.apk') === TRUE) {
    echo "=== res/mipmap-anydpi-v26/ic_launcher.xml ===\n";
    echo bin2hex(substr($zip->getFromName('res/mipmap-anydpi-v26/ic_launcher.xml'), 0, 100)) . "\n";
    
    // Save images from APK to check
    $saved = [];
    $targets = [
        'res/mipmap-hdpi-v4/ic_launcher.png',
        'res/mipmap-hdpi-v4/ic_launcher_foreground.png',
        'res/mipmap-xxhdpi-v4/ic_launcher.png',
        'res/mipmap-xxhdpi-v4/ic_launcher_foreground.png',
    ];
    foreach ($targets as $target) {
        $content = $zip->getFromName($target);
        if ($content !== false) {
            $dest = __DIR__ . '/' . basename($target);
            file_put_contents($dest, $content);
            $saved[] = $dest . ' (' . strlen($content) . ' bytes)';
        }
    }
    echo "Saved: " . implode(', ', $saved) . "\n";
    $zip->close();
} else {
    echo "Failed to open APK\n";
}
