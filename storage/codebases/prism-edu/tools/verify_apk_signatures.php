<?php
require_once __DIR__ . '/../services/ApkBuilderService.php';

$builder = new \App\Services\ApkBuilderService();
$apksigner = $builder->resolveTool('apksigner');

$files = [
    'downloads/PrismEDU-App.apk' => __DIR__ . '/../downloads/PrismEDU-App.apk',
    'public/downloads/PrismEDU-App.apk' => __DIR__ . '/../public/downloads/PrismEDU-App.apk',
    'android/app/build/outputs/apk/debug/app-debug.apk' => __DIR__ . '/../android/app/build/outputs/apk/debug/app-debug.apk',
    'storage/app_template/template-unsigned.apk' => __DIR__ . '/../storage/app_template/template-unsigned.apk'
];

foreach ($files as $name => $path) {
    echo "=== Checking $name ===\n";
    if (!file_exists($path)) {
        echo "File does not exist: $path\n\n";
        continue;
    }
    echo "Size: " . filesize($path) . " bytes\n";
    $cmd = sprintf('%s verify --verbose %s 2>&1', escapeshellarg($apksigner), escapeshellarg($path));
    exec($cmd, $output, $code);
    echo "Verification Exit Code: $code\n";
    echo implode("\n", $output) . "\n\n";
    unset($output);
}
