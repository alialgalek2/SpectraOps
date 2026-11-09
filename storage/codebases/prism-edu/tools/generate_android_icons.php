<?php
/**
 * Script to generate pixel-perfect Android launcher mipmaps from uploads/branding/prism_edu_app_icon.png
 */

$sourceFile = __DIR__ . '/../uploads/branding/prism_edu_app_icon.png';
if (!file_exists($sourceFile)) {
    die("Source file not found: $sourceFile\n");
}

$src = imagecreatefrompng($sourceFile);
$w = imagesx($src);
$h = imagesy($src);

echo "Loaded source icon: {$w}x{$h}\n";

$densities = [
    'mipmap-mdpi'    => 48,
    'mipmap-hdpi'    => 72,
    'mipmap-xhdpi'   => 96,
    'mipmap-xxhdpi'  => 144,
    'mipmap-xxxhdpi' => 192,
];

$fgDensities = [
    'mipmap-mdpi'    => 108,
    'mipmap-hdpi'    => 162,
    'mipmap-xhdpi'   => 216,
    'mipmap-xxhdpi'  => 324,
    'mipmap-xxxhdpi' => 432,
];

$resDir = __DIR__ . '/../android/app/src/main/res';

// 1. Standard ic_launcher.png (Preserves original rounded squircle on transparent background)
foreach ($densities as $folder => $size) {
    $dir = "$resDir/$folder";
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $dest = imagecreatetruecolor($size, $size);
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $size, $size, $transparent);

    imagecopyresampled($dest, $src, 0, 0, 0, 0, $size, $size, $w, $h);
    imagepng($dest, "$dir/ic_launcher.png", 9);
    imagedestroy($dest);
    echo "Generated $dir/ic_launcher.png ($size x $size)\n";
}

// 2. Circular ic_launcher_round.png (Smooth circular masked icon)
foreach ($densities as $folder => $size) {
    $dir = "$resDir/$folder";

    $dest = imagecreatetruecolor($size, $size);
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $size, $size, $transparent);

    // Resample original first
    $temp = imagecreatetruecolor($size, $size);
    imagealphablending($temp, false);
    imagesavealpha($temp, true);
    imagecopyresampled($temp, $src, 0, 0, 0, 0, $size, $size, $w, $h);

    // Circular mask with anti-aliasing
    $radius = $size / 2;
    for ($x = 0; $x < $size; $x++) {
        for ($y = 0; $y < $size; $y++) {
            $dx = $x - $radius + 0.5;
            $dy = $y - $radius + 0.5;
            $dist = sqrt($dx * $dx + $dy * $dy);
            if ($dist <= $radius - 0.5) {
                $color = imagecolorat($temp, $x, $y);
                imagesetpixel($dest, $x, $y, $color);
            } elseif ($dist <= $radius + 0.5) {
                $alphaRatio = 1.0 - ($dist - ($radius - 0.5));
                $color = imagecolorat($temp, $x, $y);
                $rgba = imagecolorsforindex($temp, $color);
                $newAlpha = 127 - (int)round((127 - $rgba['alpha']) * $alphaRatio);
                $newColor = imagecolorallocatealpha($dest, $rgba['red'], $rgba['green'], $rgba['blue'], max(0, min(127, $newAlpha)));
                imagesetpixel($dest, $x, $y, $newColor);
            }
        }
    }
    imagepng($dest, "$dir/ic_launcher_round.png", 9);
    imagedestroy($temp);
    imagedestroy($dest);
    echo "Generated $dir/ic_launcher_round.png ($size x $size)\n";
}

// 3. Adaptive Foreground ic_launcher_foreground.png (108dp base)
// Filled with #091830 edge-to-edge, with the prism centered in the safe zone
$brandNavyR = 9;
$brandNavyG = 24;
$brandNavyB = 48;

foreach ($fgDensities as $folder => $size) {
    $dir = "$resDir/$folder";

    $dest = imagecreatetruecolor($size, $size);
    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    
    // Fill with solid institutional brand navy #091830
    $bgNavy = imagecolorallocate($dest, $brandNavyR, $brandNavyG, $brandNavyB);
    imagefilledrectangle($dest, 0, 0, $size, $size, $bgNavy);

    // Enable alpha blending to copy the prism icon seamlessly over the navy canvas
    imagealphablending($dest, true);
    
    // Sizing: in 108dp canvas, safe zone is inner 72dp (~66%).
    // If we scale the original 1024x1024 icon to 92% of size and center it:
    // Prism bounding box is 632px high in 1024px (61.7%). At 92%, it will be 56.8% of 108dp (~61dp),
    // which fits with comfortable margin inside the 72dp circular mask!
    $scale = 0.90;
    $scaledSize = (int)round($size * $scale);
    $offset = (int)round(($size - $scaledSize) / 2);

    imagecopyresampled($dest, $src, $offset, $offset, 0, 0, $scaledSize, $scaledSize, $w, $h);

    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    imagepng($dest, "$dir/ic_launcher_foreground.png", 9);
    imagedestroy($dest);
    echo "Generated $dir/ic_launcher_foreground.png ($size x $size)\n";
}

echo "All Android launcher assets generated successfully!\n";
