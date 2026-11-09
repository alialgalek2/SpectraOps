<?php
$im = imagecreatetruecolor(128, 128);
imagealphablending($im, false);
imagesavealpha($im, true);
$transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
imagefilledrectangle($im, 0, 0, 128, 128, $transparent);

$bg = imagecolorallocate($im, 19, 63, 131);
$accent = imagecolorallocate($im, 13, 192, 231);
$white = imagecolorallocate($im, 238, 253, 255);

imagefilledellipse($im, 64, 64, 124, 124, $bg);
imagefilledellipse($im, 64, 48, 44, 44, $white);
imagefilledarc($im, 64, 110, 80, 70, 180, 360, $white, IMG_ARC_PIE);

imagepng($im, __DIR__ . '/../assets/img/default-avatar.png');
imagedestroy($im);
echo "PNG generated successfully\n";
