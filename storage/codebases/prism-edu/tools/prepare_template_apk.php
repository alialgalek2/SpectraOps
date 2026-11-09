<?php
require_once __DIR__ . '/../services/ApkBuilderService.php';

use App\Services\ApkBuilderService;

$debugApk = __DIR__ . '/../android/app/build/outputs/apk/debug/app-debug.apk';
$templateApk = __DIR__ . '/../storage/app_template/template-unsigned.apk';

if (!file_exists($debugApk)) {
    die("Debug APK not found at: $debugApk\n");
}

echo "1. Preparing clean unsigned template APK from build output...\n";
copy($debugApk, $templateApk);

// Strip META-INF signatures
$zip = new ZipArchive();
if ($zip->open($templateApk) === TRUE) {
    for ($i = $zip->numFiles - 1; $i >= 0; $i--) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^META-INF/#i', $name)) {
            $zip->deleteIndex($i);
        }
    }
    $zip->close();
    echo "Template APK ready at $templateApk (" . filesize($templateApk) . " bytes)\n";
} else {
    die("Failed to open template APK zip.\n");
}

echo "\n2. Generating signed APK with institutional keystore...\n";
$service = new ApkBuilderService();
$lanUrl = 'http://192.168.1.30:8080/school_system';
$result = $service->generateCustomApk($lanUrl, 'PRISM EDU');

echo "Result:\n";
print_r($result);

if (!empty($result['success'])) {
    echo "\n3. Verifying output APK signatures...\n";
    $apksigner = $service->resolveTool('apksigner');
    if ($apksigner) {
        $outApk = $service->getOutputApkPath();
        $verifyCmd = sprintf('%s verify --verbose %s 2>&1', escapeshellarg($apksigner), escapeshellarg($outApk));
        $verifyOut = shell_exec($verifyCmd);
        echo "apksigner verify:\n$verifyOut\n";
    }
}
