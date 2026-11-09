<?php
$img = imagecreatefrompng(__DIR__ . '/../uploads/branding/prism_edu_app_icon.png');
$rgb = imagecolorat($img, 100, 100);
$colors = imagecolorsforindex($img, $rgb);
printf("Dark background (100,100): R=%d G=%d B=%d Alpha=%d (#%02x%02x%02x)\n", $colors['red'], $colors['green'], $colors['blue'], $colors['alpha'], $colors['red'], $colors['green'], $colors['blue']);

$rgbCenter = imagecolorat($img, 512, 512);
$colorsC = imagecolorsforindex($img, $rgbCenter);
printf("Center (512,512): R=%d G=%d B=%d Alpha=%d (#%02x%02x%02x)\n", $colorsC['red'], $colorsC['green'], $colorsC['blue'], $colorsC['alpha'], $colorsC['red'], $colorsC['green'], $colorsC['blue']);

$rgbTop = imagecolorat($img, 512, 195);
$colorsT = imagecolorsforindex($img, $rgbTop);
printf("Light dot (512,195): R=%d G=%d B=%d Alpha=%d (#%02x%02x%02x)\n", $colorsT['red'], $colorsT['green'], $colorsT['blue'], $colorsT['alpha'], $colorsT['red'], $colorsT['green'], $colorsT['blue']);

$rgbCorner = imagecolorat($img, 5, 5);
$colorsCorner = imagecolorsforindex($img, $rgbCorner);
printf("Corner (5,5): Alpha=%d (127 is transparent)\n", $colorsCorner['alpha']);

