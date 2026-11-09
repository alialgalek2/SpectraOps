<?php
$portalDir = __DIR__ . '/../portals';
$files = glob($portalDir . '/*.php');

echo "Auditing Portal Redirect targets:\n";
$missing = 0;
foreach ($files as $f) {
    $content = file_get_contents($f);
    if (preg_match('/header\("Location:\s*([^"?]+)/i', $content, $m)) {
        $targetRel = trim($m[1]);
        $targetAbs = realpath($portalDir . '/' . $targetRel);
        $status = ($targetAbs && file_exists($targetAbs)) ? "OK" : "MISSING";
        if ($status === "MISSING") {
            $missing++;
            echo "[BROKEN LINK] " . basename($f) . " -> " . $targetRel . " (RESOLVED: " . ($targetAbs ?: 'NOT FOUND') . ")\n";
        } else {
            echo "[OK] " . basename($f) . " -> " . $targetRel . "\n";
        }
    }
}
echo "\nTotal Portal Redirect Files: " . count($files) . ", Missing Targets: $missing\n";
