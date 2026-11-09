<?php
$f1 = 'C:/Users/WMIS/.gemini/antigravity-ide/brain/55376078-bccd-4cac-8c66-a52234ca7fb1/.user_uploaded/media_1789291058809.png';
$f2 = 'C:/Users/WMIS/.gemini/antigravity-ide/brain/55376078-bccd-4cac-8c66-a52234ca7fb1/.user_uploaded/media_1789291058882.png';

$im1 = imagecreatefrompng($f1);
$im2 = imagecreatefrompng($f2);

$c1 = imagecolorsforindex($im1, imagecolorat($im1, 200, 200));
$c2 = imagecolorsforindex($im2, imagecolorat($im2, 200, 200));

echo "File 1 (809) at (200,200): r={$c1['red']} g={$c1['green']} b={$c1['blue']} a={$c1['alpha']}\n";
echo "File 2 (882) at (200,200): r={$c2['red']} g={$c2['green']} b={$c2['blue']} a={$c2['alpha']}\n";
