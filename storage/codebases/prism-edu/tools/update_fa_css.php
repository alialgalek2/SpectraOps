<?php
$cssFile = 'C:/xampp/htdocs/school_system/assets/vendor/fontawesome/css/all.min.css';
$css = file_get_contents($cssFile);

// Update font-display to swap
$css = str_replace('font-display:block', 'font-display:swap', $css);

// Ensure @font-face includes CDN fallback
$replacements = [
    'src:url(../webfonts/fa-solid-900.woff2) format("woff2"),url(../webfonts/fa-solid-900.ttf) format("truetype")' =>
    'src:url(../webfonts/fa-solid-900.woff2) format("woff2"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2) format("woff2"),url(../webfonts/fa-solid-900.ttf) format("truetype"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.ttf) format("truetype")',

    'src:url(../webfonts/fa-regular-400.woff2) format("woff2"),url(../webfonts/fa-regular-400.ttf) format("truetype")' =>
    'src:url(../webfonts/fa-regular-400.woff2) format("woff2"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.woff2) format("woff2"),url(../webfonts/fa-regular-400.ttf) format("truetype"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-regular-400.ttf) format("truetype")',

    'src:url(../webfonts/fa-brands-400.woff2) format("woff2"),url(../webfonts/fa-brands-400.ttf) format("truetype")' =>
    'src:url(../webfonts/fa-brands-400.woff2) format("woff2"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.woff2) format("woff2"),url(../webfonts/fa-brands-400.ttf) format("truetype"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-brands-400.ttf) format("truetype")',

    'src:url(../webfonts/fa-v4compatibility.woff2) format("woff2"),url(../webfonts/fa-v4compatibility.ttf) format("truetype")' =>
    'src:url(../webfonts/fa-v4compatibility.woff2) format("woff2"),url(https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-v4compatibility.woff2) format("woff2"),url(../webfonts/fa-v4compatibility.ttf) format("truetype")'
];

foreach ($replacements as $search => $replace) {
    $css = str_replace($search, $replace, $css);
}

file_put_contents($cssFile, $css);
echo "Updated FA CSS with CDN fallback and font-display: swap! File size: " . strlen($css) . " bytes\n";
