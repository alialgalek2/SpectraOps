<?php
require_once __DIR__ . '/../services/ApkBuilderService.php';

$builder = new \App\Services\ApkBuilderService();
echo "apksigner: " . ($builder->resolveTool('apksigner') ?? 'NOT FOUND') . "\n";
echo "zipalign: " . ($builder->resolveTool('zipalign') ?? 'NOT FOUND') . "\n";
echo "keytool: " . ($builder->resolveTool('keytool') ?? 'NOT FOUND') . "\n";
