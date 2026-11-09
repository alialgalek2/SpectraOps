<?php
declare(strict_types=1);

/**
 * CLI Tool: Build Favicons and App Icons for School System & SpectraOps
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI execution only.\n");
}

function createIcoFromPngData(array $pngBlobs): string {
    $count = count($pngBlobs);
    // Header: Reserved (2), Type 1=Icon (2), Count (2)
    $ico = pack('vvv', 0, 1, $count);
    
    $offset = 6 + ($count * 16);
    
    // Directory entries
    foreach ($pngBlobs as $blob) {
        $info = getimagesizefromstring($blob);
        $w = $info ? $info[0] : 32;
        $h = $info ? $info[1] : 32;
        $widthByte = ($w >= 256) ? 0 : $w;
        $heightByte = ($h >= 256) ? 0 : $h;
        $size = strlen($blob);
        
        $ico .= pack('CCCCvvVV',
            $widthByte,
            $heightByte,
            0, // color count
            0, // reserved
            1, // planes
            32, // bpp
            $size,
            $offset
        );
        $offset += $size;
    }
    
    // Append PNG blobs
    foreach ($pngBlobs as $blob) {
        $ico .= $blob;
    }
    
    return $ico;
}

function resizePng(string $srcPath, int $size): string {
    $src = imagecreatefrompng($srcPath);
    if (!$src) {
        throw new RuntimeException("Cannot load PNG: " . $srcPath);
    }
    $w = imagesx($src);
    $h = imagesy($src);
    
    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefill($dst, 0, 0, $transparent);
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, $w, $h);
    imagedestroy($src);
    
    ob_start();
    imagepng($dst, null, 9);
    $data = ob_get_clean();
    imagedestroy($dst);
    
    return $data;
}

// -------------------------------------------------------------
// 1. Build School System Icons from prism_edu_app_icon.png
// -------------------------------------------------------------
$schoolBase = dirname(__DIR__);
$prismPng = $schoolBase . '/uploads/branding/prism_edu_app_icon.png';
$prismSvg = $schoolBase . '/uploads/branding/prism_edu_icon.svg';

if (file_exists($prismPng)) {
    echo "Processing School System icons from {$prismPng}...\n";
    $sizes = [16, 32, 48, 64];
    $blobs = [];
    foreach ($sizes as $s) {
        $blobs[$s] = resizePng($prismPng, $s);
    }
    
    // Generate multi-size favicon.ico
    $icoData = createIcoFromPngData(array_values($blobs));
    file_put_contents($schoolBase . '/favicon.ico', $icoData);
    echo "  -> Created {$schoolBase}/favicon.ico (" . strlen($icoData) . " bytes)\n";
    
    // Generate 32x32 favicon.png
    file_put_contents($schoolBase . '/favicon.png', $blobs[32]);
    echo "  -> Created {$schoolBase}/favicon.png\n";
    
    // Generate 180x180 apple-touch-icon.png
    $appleTouch = resizePng($prismPng, 180);
    file_put_contents($schoolBase . '/apple-touch-icon.png', $appleTouch);
    echo "  -> Created {$schoolBase}/apple-touch-icon.png\n";
    
    if (file_exists($prismSvg)) {
        copy($prismSvg, $schoolBase . '/favicon.svg');
        echo "  -> Copied favicon.svg\n";
    }
} else {
    echo "Warning: {$prismPng} not found!\n";
}

// -------------------------------------------------------------
// 2. Build SpectraOps Icons
// -------------------------------------------------------------
function renderSpectraOpsPng(int $canvasSize): string {
    // Supersample 4x for clean antialiasing
    $scale = 4;
    $sSize = $canvasSize * $scale;
    $img = imagecreatetruecolor($sSize, $sSize);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);
    
    // Scale factor from 54x54 SVG coordinate space to $sSize
    $factor = $sSize / 54.0;
    
    // Colors:
    // #4FA8E0 => 79, 168, 224
    // #2D5A78 => 45, 90, 120
    // #7FC4EE => 127, 196, 238
    $cSlant = imagecolorallocate($img, 79, 168, 224);
    $cBar1  = imagecolorallocate($img, 45, 90, 120);
    $cBar2  = imagecolorallocate($img, 79, 168, 224);
    $cBar3  = imagecolorallocate($img, 127, 196, 238);
    
    // Polygon points: 6,46 22,10 31,10 15,46
    $polyPoints = [
        (int)round(6 * $factor), (int)round(46 * $factor),
        (int)round(22 * $factor), (int)round(10 * $factor),
        (int)round(31 * $factor), (int)round(10 * $factor),
        (int)round(15 * $factor), (int)round(46 * $factor),
    ];
    imagefilledpolygon($img, $polyPoints, 4, $cSlant);
    
    // Helper to draw filled rounded rectangle
    $drawRoundRect = function($im, $x, $y, $w, $h, $rx, $color) use ($factor) {
        $x1 = (int)round($x * $factor);
        $y1 = (int)round($y * $factor);
        $x2 = (int)round(($x + $w) * $factor);
        $y2 = (int)round(($y + $h) * $factor);
        $r  = (int)round($rx * $factor);
        if ($r < 1) $r = 1;
        
        imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        imagefilledellipse($im, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($im, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    };
    
    // Bar 1: rect x="23" y="28" width="4.2" height="18" rx="1" fill="#2D5A78"
    $drawRoundRect($img, 23, 28, 4.2, 18, 1, $cBar1);
    // Bar 2: rect x="31" y="20" width="4.2" height="26" rx="1" fill="#4FA8E0"
    $drawRoundRect($img, 31, 20, 4.2, 26, 1, $cBar2);
    // Bar 3: rect x="39" y="12" width="4.2" height="34" rx="1" fill="#7FC4EE"
    $drawRoundRect($img, 39, 12, 4.2, 34, 1, $cBar3);
    
    // Downsample to final size with high quality bicubic/resample
    $final = imagecreatetruecolor($canvasSize, $canvasSize);
    imagealphablending($final, false);
    imagesavealpha($final, true);
    $transFinal = imagecolorallocatealpha($final, 0, 0, 0, 127);
    imagefill($final, 0, 0, $transFinal);
    
    imagecopyresampled($final, $img, 0, 0, 0, 0, $canvasSize, $canvasSize, $sSize, $sSize);
    imagedestroy($img);
    
    ob_start();
    imagepng($final, null, 9);
    $out = ob_get_clean();
    imagedestroy($final);
    return $out;
}

echo "\nProcessing SpectraOps icons...\n";
$spSizes = [16, 32, 48, 64, 128, 180];
$spBlobs = [];
foreach ($spSizes as $sz) {
    $spBlobs[$sz] = renderSpectraOpsPng($sz);
}

$spIco = createIcoFromPngData([$spBlobs[16], $spBlobs[32], $spBlobs[48], $spBlobs[64]]);

$spectraOpsDirs = [
    'c:/xampp/htdocs/SPECTRAOPS/public',
    'C:/Users/WMIS/.gemini/antigravity-ide/scratch/fleet-hub/public'
];

foreach ($spectraOpsDirs as $dir) {
    if (is_dir($dir)) {
        file_put_contents($dir . '/spectraops-icon.png', $spBlobs[64]);
        file_put_contents($dir . '/favicon.png', $spBlobs[32]);
        file_put_contents($dir . '/favicon.ico', $spIco);
        file_put_contents($dir . '/apple-touch-icon.png', $spBlobs[180]);
        echo "  -> Saved SpectraOps icons to {$dir}\n";
    }
}

echo "\nAll favicons generated successfully!\n";
