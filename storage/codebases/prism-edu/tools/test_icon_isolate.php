<?php
$src = imagecreatefrompng(__DIR__ . '/../uploads/branding/prism_edu_app_icon.png');
$w = imagesx($src);
$h = imagesy($src);
echo "Size: {$w}x{$h}\n";

$minX = $w; $maxX = 0;
$minY = $h; $maxY = 0;

for ($y = 0; $y < $h; $y++) {
    for ($x = 0; $x < $w; $x++) {
        $c = imagecolorsforindex($src, imagecolorat($src, $x, $y));
        if ($c['alpha'] > 10) continue; // skip transparent / semi-transparent corners
        // Check distance to #091830 (9, 24, 48)
        $dr = abs($c['red'] - 9);
        $dg = abs($c['green'] - 24);
        $db = abs($c['blue'] - 48);
        if ($dr > 15 || $dg > 15 || $db > 15) {
            if ($x < $minX) $minX = $x;
            if ($x > $maxX) $maxX = $x;
            if ($y < $minY) $minY = $y;
            if ($y > $maxY) $maxY = $y;
        }
    }
}

echo "Prism bounding box: X=[$minX, $maxX] (width " . ($maxX - $minX) . "), Y=[$minY, $maxY] (height " . ($maxY - $minY) . ")\n";


