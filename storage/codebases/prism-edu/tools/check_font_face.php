<?php
$css = file_get_contents('C:/xampp/htdocs/school_system/assets/vendor/fontawesome/css/all.min.css');
preg_match_all('/@font-face\{[^}]+\}/', $css, $m);
foreach ($m[0] as $ff) {
    echo $ff . "\n\n";
}
