<?php
$apkPath = __DIR__ . '/../downloads/PrismEDU-App.apk';

if (!file_exists($apkPath)) {
    die("Final APK not found at: $apkPath\n");
}

$zip = new ZipArchive();
if ($zip->open($apkPath) === TRUE) {
    echo "=== Final APK: $apkPath (" . filesize($apkPath) . " bytes) ===\n";
    
    // Check for unwanted white square vector
    $hasWhiteSquareVector = ($zip->locateName('res/drawable/ic_launcher_foreground.xml') !== false);
    echo "1. Has dummy white square vector (res/drawable/ic_launcher_foreground.xml): " . ($hasWhiteSquareVector ? "YES (BUG!)" : "NO (CLEAN!)") . "\n";
    
    // Check adaptive xmls
    $hasAdaptiveLauncher = ($zip->locateName('res/mipmap-anydpi-v26/ic_launcher.xml') !== false);
    $hasAdaptiveRound = ($zip->locateName('res/mipmap-anydpi-v26/ic_launcher_round.xml') !== false);
    echo "2. Has adaptive launcher XMLs: " . ($hasAdaptiveLauncher && $hasAdaptiveRound ? "YES" : "NO") . "\n";

    // Extract icons from APK to disk for visual check
    $extracts = [
        'res/mipmap-xxhdpi-v4/ic_launcher.png' => __DIR__ . '/final_extracted_launcher.png',
        'res/mipmap-xxhdpi-v4/ic_launcher_foreground.png' => __DIR__ . '/final_extracted_foreground.png',
        'res/mipmap-xxhdpi-v4/ic_launcher_round.png' => __DIR__ . '/final_extracted_round.png',
    ];

    foreach ($extracts as $entry => $dest) {
        $content = $zip->getFromName($entry);
        if ($content !== false) {
            file_put_contents($dest, $content);
            echo "3. Extracted $entry -> " . basename($dest) . " (" . strlen($content) . " bytes)\n";
        } else {
            echo "3. MISSING ENTRY: $entry\n";
        }
    }
    
    // Verify config inside APK
    $configContent = $zip->getFromName('assets/app_config.json');
    echo "\n4. assets/app_config.json:\n$configContent\n";
    
    $zip->close();
} else {
    die("Failed to open final APK.\n");
}
